<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Models\Task;
use App\Models\WorkRequest;
use App\Services\AgentExecutionRunner;
use App\Services\TaskCommitIntegrator;
use App\Services\TaskWorkflowService;
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

    public function handle(AgentExecutionRunner $runner, TaskCommitIntegrator $commitIntegrator): void
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

        $this->applyCompletion($subject, $completion, $commitIntegrator);

        // Fire the happy-path continuation immediately instead of waiting for the next
        // workflow:dispatch scheduler tick (up to 60s later). The short delay lets this job's own
        // per-project ShouldBeUnique lock release first; workflow:dispatch still reconciles
        // anything this attempt misses.
        DispatchWorkflowForProject::dispatch($this->projectId())->delay(now()->addSeconds(2));
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
    private function applyCompletion(Task|WorkRequest $subject, array $completion, TaskCommitIntegrator $commitIntegrator): void
    {
        if ($subject instanceof Task && $subject->refresh()->status === 'waiting' && isset($subject->last_handoff['id'])) {
            return;
        }

        if ($completion['status'] === 'failed') {
            $this->markFailed($completion['summary']);

            return;
        }

        if ($completion['status'] === 'waiting') {
            if ($subject instanceof Task) {
                throw new \UnexpectedValueException('A Task Agent must use the durable handoff tool instead of returning a raw handoff.');
            }

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

        $this->completeTask($subject, $completion, $commitIntegrator);
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

            $locked->update([
                'status' => 'completed',
                'summary' => $completion['summary'],
                'evidence' => $completion['already_implemented'] === true ? [$completion['summary']] : null,
                'failure_reason' => null,
                'last_handoff' => null,
            ]);
        }, attempts: 3);

        if (($completion['tasks'] ?? []) !== [] && ! $workRequest->tasks()->exists()) {
            app(TaskWorkflowService::class)->persistPlan(
                AgentRun::query()->findOrFail($completion['agent_run_id']),
                $workRequest,
                $completion['tasks'] ?? [],
            );
        }
    }

    /**
     * @param  array{status: string, summary: string, handoff: array<string, mixed>|null, commit_sha: string|null, tasks: list<array<string, mixed>>|null, already_implemented: bool|null, delegations: list<array<string, mixed>>, review: array<string, mixed>|null, agent_run_id: int}  $completion
     */
    private function completeTask(Task $task, array $completion, TaskCommitIntegrator $commitIntegrator): void
    {
        if (! filled($completion['commit_sha'])) {
            throw new \UnexpectedValueException('A Task Agent must save its result and request a durable handoff before completing.');
        }

        $run = AgentRun::query()->findOrFail($completion['agent_run_id']);

        $commitIntegrator->integrate($task, $run, $completion['commit_sha'], $completion['summary']);
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
