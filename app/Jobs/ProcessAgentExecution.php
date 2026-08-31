<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Models\Task;
use App\Models\WorkRequest;
use App\Services\AgentExecutionRunner;
use App\Services\CandidateAcceptanceGate;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Run exactly one Agent execution for a Task or WorkRequest. Laravel owns dispatch, retries, and
 * persisting whatever the Agent reports; the Agent owns every decision about the work itself.
 */
class ProcessAgentExecution implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Let the Agent harness run to completion without an artificial wall-clock kill.
     */
    public int $timeout = 0;

    /**
     * Allow the initial attempt plus two automatic retries for transient infrastructure failures.
     */
    public int $tries = 3;

    public int $backoff = 5;

    public int $uniqueFor = 300;

    public function __construct(
        public Task|WorkRequest $subject,
        public ?string $operatorInstruction = null,
    ) {}

    /**
     * Keep at most one active Agent execution per Project.
     */
    public function uniqueId(): string
    {
        return (string) $this->projectId();
    }

    public function handle(AgentExecutionRunner $runner): void
    {
        $subject = $this->freshSubject();

        if (! in_array($subject->status, ['pending', 'waiting', 'running'], true)) {
            return;
        }

        $subject->update(['status' => 'running']);

        // Any failure below (a validation problem with the Agent's own response, or an
        // infrastructure error the harness call surfaced) propagates to Laravel's queue retry
        // mechanism ($tries); only after the final attempt does failed() persist it as terminal.
        $completion = $runner->run($subject, $this->operatorInstruction);

        $this->applyCompletion($subject, $completion, $runner);
    }

    /**
     * Mark a terminal queue failure without overwriting a subject another retry already completed.
     */
    public function failed(?Throwable $exception): void
    {
        $this->markFailed(
            $exception?->getMessage() ?? 'Agent execution failed.',
        );
    }

    /**
     * @param  array{status: string, summary: string, handoff: array<string, mixed>|null, commit_sha: string|null, tasks: list<array<string, mixed>>|null, already_implemented: bool|null, delegations: list<array<string, mixed>>, review: array<string, mixed>|null, agent_run_id: int}  $completion
     */
    private function applyCompletion(Task|WorkRequest $subject, array $completion, AgentExecutionRunner $runner): void
    {
        if ($completion['status'] === 'failed') {
            $this->markFailed($completion['summary']);

            return;
        }

        if ($completion['status'] === 'waiting') {
            $subject::query()->whereKey($subject->getKey())
                ->where('status', 'running')
                ->update([
                    'status' => 'waiting',
                    'last_handoff' => $completion['handoff'],
                    $this->failureReasonColumn($subject) => null,
                ]);

            return;
        }

        // completed
        if ($subject instanceof WorkRequest) {
            $this->completeWorkRequest($subject, $completion);

            return;
        }

        $this->completeTask($subject, $completion, $runner);
    }

    /**
     * @param  array{status: string, summary: string, handoff: array<string, mixed>|null, commit_sha: string|null, tasks: list<array<string, mixed>>|null, already_implemented: bool|null, delegations: list<array<string, mixed>>, review: array<string, mixed>|null, agent_run_id: int}  $completion
     */
    private function completeWorkRequest(WorkRequest $workRequest, array $completion): void
    {
        DB::transaction(function () use ($workRequest, $completion): void {
            $locked = WorkRequest::query()->lockForUpdate()->whereKey($workRequest->getKey())->firstOrFail();

            if ($locked->status !== 'running') {
                return;
            }

            $locked->tasks()->delete();

            foreach (($completion['tasks'] ?? []) as $index => $taskPlan) {
                $position = $index + 1;
                $dependsOnPosition = $taskPlan['depends_on_position'] ?? null;
                $dependsOnTaskId = null;

                if (is_int($dependsOnPosition) && $dependsOnPosition >= 1 && $dependsOnPosition < $position) {
                    $dependency = $locked->tasks()->where('position', $dependsOnPosition)->first();
                    $dependsOnTaskId = $dependency?->getKey();
                }

                $locked->tasks()->create([
                    'assigned_project_agent_id' => filled($taskPlan['assigned_agent_role'] ?? null)
                        ? $locked->project->agents()->where('role', $taskPlan['assigned_agent_role'])->value('id')
                        : null,
                    'depends_on_task_id' => $dependsOnTaskId,
                    'position' => $position,
                    'title' => (string) $taskPlan['title'],
                    'objective' => (string) ($taskPlan['objective'] ?? $taskPlan['title']),
                    'implementation_spec' => (string) ($taskPlan['implementation_spec'] ?? ''),
                    'acceptance_criteria' => $taskPlan['acceptance_criteria'] ?? [],
                    'verification_commands' => $taskPlan['verification_commands'] ?? [],
                    'browser_steps' => $taskPlan['browser_steps'] ?? [],
                ]);
            }

            $locked->update([
                'status' => 'completed',
                'summary' => $completion['summary'],
                'evidence' => $completion['already_implemented'] === true ? [$completion['summary']] : null,
                'failure_reason' => null,
                'last_handoff' => null,
            ]);
        }, attempts: 3);
    }

    /**
     * @param  array{status: string, summary: string, handoff: array<string, mixed>|null, commit_sha: string|null, tasks: list<array<string, mixed>>|null, already_implemented: bool|null, delegations: list<array<string, mixed>>, review: array<string, mixed>|null, agent_run_id: int}  $completion
     */
    private function completeTask(Task $task, array $completion, AgentExecutionRunner $runner): void
    {
        if (is_array($completion['review'] ?? null)) {
            $review = $completion['review'];
            $candidateRunId = $task->last_handoff['candidate_agent_run_id'] ?? null;
            $candidateRun = AgentRun::query()->find($candidateRunId);
            $reviewerRun = AgentRun::query()->find($completion['agent_run_id']);

            if (! $candidateRun instanceof AgentRun || ! $reviewerRun instanceof AgentRun) {
                throw new \UnexpectedValueException('AISF could not locate the candidate and reviewer execution evidence.');
            }

            app(CandidateAcceptanceGate::class)->recordReview(
                $task,
                $candidateRun,
                $reviewerRun,
                $review['candidate_sha'],
                $review['status'],
                $review['summary'],
                $review['findings'],
            );

            if ($review['status'] === 'changes_requested') {
                $task->update([
                    'status' => 'waiting',
                    'last_handoff' => ['to_role' => 'foreman', 'note' => $review['summary'], 'findings' => $review['findings']],
                ]);

                return;
            }

            $task->loadMissing('workRequest.project');
            if ($task->workRequest->project->merge_policy === 'automatic') {
                try {
                    if (! app(CandidateAcceptanceGate::class)->hasCurrentApproval($task)) {
                        throw new \UnexpectedValueException('Automatic merge requires current independent approval.');
                    }

                    $runner->mergeVerifiedCandidate($task);
                } catch (Throwable $exception) {
                    $task->update([
                        'status' => 'waiting',
                        'last_handoff' => [
                            'to_role' => 'foreman',
                            'note' => 'AISF merge gate rejected the candidate: '.$exception->getMessage(),
                        ],
                    ]);

                    return;
                }
            }

            $task->update(['status' => 'completed', 'last_handoff' => null]);

            return;
        }

        if (filled($completion['commit_sha'])) {
            $result = $runner->integrateReportedCommit($task, $completion['commit_sha'], $completion['summary']);

            if ($result !== null && ! $result['integrated']) {
                Task::query()->whereKey($task->getKey())
                    ->where('status', 'running')
                    ->update([
                        'status' => 'waiting',
                        'blocked_reason' => null,
                        'last_handoff' => [
                            'to_role' => 'foreman',
                            'note' => "CI checks failed before a pull request could be opened:\n\n".Str::limit($result['ci_output'], 4000, ''),
                        ],
                    ]);

                return;
            }

            Task::query()->whereKey($task->getKey())
                ->where('status', 'running')
                ->update([
                    'status' => 'waiting',
                    'candidate_sha' => $result['commit_sha'],
                    'last_handoff' => ['to_role' => 'foreman', 'candidate_agent_run_id' => $completion['agent_run_id'], 'note' => 'Coordinate an independent review for this exact candidate SHA.'],
                    'blocked_reason' => null,
                    'commit_sha' => $result['commit_sha'],
                    'pull_request_url' => $result['pull_request_url'],
                ]);

            return;
        }

        Task::query()->whereKey($task->getKey())
            ->where('status', 'running')
            ->update([
                'status' => 'completed',
                'last_handoff' => null,
                'blocked_reason' => null,
            ]);
    }

    private function freshSubject(): Task|WorkRequest
    {
        return $this->subject instanceof WorkRequest
            ? WorkRequest::query()->whereKey($this->subject->getKey())->sole()
            : Task::query()->whereKey($this->subject->getKey())->sole();
    }

    private function projectId(): int
    {
        if ($this->subject instanceof WorkRequest) {
            return (int) $this->subject->project_id;
        }

        $this->subject->loadMissing('workRequest');

        return (int) $this->subject->workRequest->project_id;
    }

    /**
     * Persist a concise terminal failure reason only while the subject has not already completed.
     */
    private function markFailed(string $message): void
    {
        $subjectClass = $this->subject::class;

        $subjectClass::query()
            ->whereKey($this->subject->getKey())
            ->where('status', '!=', 'completed')
            ->update([
                'status' => 'failed',
                $this->failureReasonColumn($this->subject) => Str::limit($message, 10000, ''),
            ]);
    }

    /**
     * Task and WorkRequest persist their failure reason under different historical column names.
     */
    private function failureReasonColumn(Task|WorkRequest $subject): string
    {
        return $subject instanceof WorkRequest ? 'failure_reason' : 'blocked_reason';
    }
}
