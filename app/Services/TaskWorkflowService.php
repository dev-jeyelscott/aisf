<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\CandidateReview;
use App\Models\Task;
use App\Models\TaskHandoff;
use App\Models\WorkRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

class TaskWorkflowService
{
    /** @var list<string> */
    private const ROLES = ['project_manager', 'coder', 'qa'];

    public function __construct(
        private readonly CandidateAcceptanceGate $candidateAcceptanceGate,
        private readonly TaskWorktreeManager $worktreeManager,
        private readonly AgentSessionManager $sessionManager,
        private readonly RepairCycleGuard $repairCycleGuard,
    ) {}

    /**
     * Persist one PM plan before the PM explicitly hands each ready Task to the Coder.
     *
     * @param  list<array<string, mixed>>  $plans
     * @return list<Task>
     */
    public function persistPlan(AgentRun $run, WorkRequest $workRequest, array $plans): array
    {
        $run->loadMissing('agentSession.projectAgent');

        if (! in_array($run->status, ['running', 'succeeded'], true) || $run->agentSession->work_request_id !== $workRequest->id || $run->agentSession->projectAgent->role !== 'project_manager') {
            throw new UnexpectedValueException('Only the completed Project Manager run may persist this WorkRequest plan.');
        }

        return DB::transaction(function () use ($workRequest, $plans): array {
            $locked = WorkRequest::query()->with('project')->lockForUpdate()->findOrFail($workRequest->id);
            if ($locked->tasks()->exists()) {
                throw new UnexpectedValueException('This WorkRequest already has a durable Task plan.');
            }
            $coder = $locked->project->agents()->where('role', 'coder')->where('enabled', true)->firstOrFail();
            $created = [];

            foreach ($plans as $index => $plan) {
                $position = $index + 1;
                $dependency = null;
                $dependencyPosition = $plan['depends_on_position'] ?? null;
                if (is_int($dependencyPosition) && $dependencyPosition > 0 && $dependencyPosition < $position) {
                    $dependency = $created[$dependencyPosition - 1] ?? null;
                }
                $task = $locked->tasks()->create([
                    'depends_on_task_id' => $dependency?->id,
                    'position' => $position,
                    'title' => (string) $plan['title'],
                    'objective' => (string) ($plan['objective'] ?? $plan['title']),
                    'implementation_spec' => (string) ($plan['implementation_spec'] ?? ''),
                    'acceptance_criteria' => $plan['acceptance_criteria'] ?? [],
                    'verification_commands' => $plan['verification_commands'] ?? [],
                    'browser_steps' => $plan['browser_steps'] ?? [],
                    'status' => 'pending',
                ]);
                $this->sessionManager->forSubject($coder, $task);
                $qa = $locked->project->agents()->where('role', 'qa')->where('enabled', true)->firstOrFail();
                $this->sessionManager->forSubject($qa, $task);

                $created[] = $task;
            }

            return $created;
        }, attempts: 3);
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     * @return list<Task>
     */
    public function savePlan(AgentRun $run, WorkRequest $workRequest, array $plans, string $executionToken): array
    {
        $run->loadMissing('agentSession.projectAgent');
        if ($run->status !== 'running' || ! hash_equals((string) $run->execution_token, $executionToken) || $run->agentSession->work_request_id !== $workRequest->id || $run->agentSession->projectAgent->role !== 'project_manager') {
            throw new UnexpectedValueException('This Agent execution is stale or is not authorized to save the WorkRequest plan.');
        }

        return $this->persistPlan($run, $workRequest, $plans);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function saveResult(AgentRun $run, Task $task, array $result, string $executionToken): array
    {
        $this->assertActiveRun($run, $task, 'coder', $executionToken);
        $this->worktreeManager->ensureWorktree($task);
        $task->refresh();
        $this->worktreeManager->assertNoCommitBeforeQa($task);
        $artifacts = [
            'summary' => (string) ($result['summary'] ?? ''),
            'changed_files' => $result['changed_files'] ?? $this->worktreeManager->changedFiles($task),
            'validation' => $result['validation'] ?? [],
            'browser_verification' => $result['browser_verification'] ?? null,
            'assumptions' => $result['assumptions'] ?? [],
            'risks' => $result['risks'] ?? [],
            'base_sha' => $task->base_sha,
        ];
        $run->update(['artifacts' => $artifacts, 'execution_metadata' => array_merge($run->execution_metadata ?? [], ['structured_result' => $result])]);
        $task->update(['candidate_sha' => $task->base_sha]);

        return $artifacts;
    }

    /** @param list<string> $findings */
    public function saveReview(AgentRun $run, Task $task, string $candidateSha, string $status, string $summary, array $findings, string $executionToken): CandidateReview
    {
        $this->assertActiveRun($run, $task, 'qa', $executionToken);
        $candidateRun = $task->agentSessions()->whereHas('projectAgent', fn ($query) => $query->where('role', 'coder'))->with('runs')->get()->flatMap(fn ($session) => $session->runs)->where('status', 'succeeded')->sortByDesc('id')->first();
        if (! $candidateRun instanceof AgentRun) {
            throw new UnexpectedValueException('A QA review requires durable Coder evidence.');
        }

        return $this->candidateAcceptanceGate->recordReview($task, $candidateRun, $run, $candidateSha, $status, $summary, $findings);
    }

    /** @param array<string, mixed> $payload */
    public function handoff(AgentRun $run, Task $task, string $toRole, string $reason, string $idempotencyKey, array $payload, string $executionToken): TaskHandoff
    {
        $run->loadMissing('agentSession.projectAgent');
        $existing = TaskHandoff::query()
            ->where('from_agent_run_id', $run->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null && $run->status === 'running' && hash_equals((string) $run->execution_token, $executionToken)) {
            return $existing;
        }
        $fromRole = (string) $run->agentSession->projectAgent->role;
        if ($fromRole === 'project_manager') {
            $this->assertActiveProjectManagerRun($run, $task, $executionToken);
        } else {
            $this->assertActiveRun($run, $task, $fromRole, $executionToken);
        }
        if (! in_array($toRole, self::ROLES, true) || trim($reason) === '' || trim($idempotencyKey) === '') {
            throw new UnexpectedValueException('The requested handoff is invalid.');
        }
        if (($fromRole === 'project_manager' && $toRole !== 'coder') || ($fromRole === 'coder' && $toRole !== 'qa') || ($fromRole === 'qa' && $toRole !== 'coder')) {
            throw new UnexpectedValueException('The requested role handoff is not valid for this Agent execution.');
        }
        if ($fromRole === 'project_manager' && $task->depends_on_task_id !== null && $task->dependsOn?->status !== 'completed') {
            throw new UnexpectedValueException('A Task dependency must complete before the PM can hand it to the Coder.');
        }
        if ($fromRole === 'coder') {
            if (! is_array($run->artifacts) || ! array_key_exists('validation', $run->artifacts)) {
                throw new UnexpectedValueException('The Coder must save structured implementation evidence before QA handoff.');
            }
            $this->worktreeManager->assertNoCommitBeforeQa($task);
        }

        return DB::transaction(function () use ($run, $task, $toRole, $reason, $idempotencyKey, $payload, $fromRole): TaskHandoff {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $agent = $locked->workRequest->project->agents()->where('role', $toRole)->where('enabled', true)->first();
            if ($agent === null) {
                throw new UnexpectedValueException("No enabled {$toRole} Agent is configured for this Project.");
            }
            $existing = TaskHandoff::query()->where('from_agent_run_id', $run->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
            $handoff = $locked->handoffs()->create([
                'from_project_agent_id' => $run->agentSession->project_agent_id,
                'to_project_agent_id' => $agent->id,
                'from_agent_run_id' => $run->id,
                'reason' => Str::limit(trim($reason), 100, ''),
                'payload' => $payload,
                'idempotency_key' => Str::limit(trim($idempotencyKey), 100, ''),
                'dispatched_at' => now(),
            ]);

            // A QA "changes_requested" handoff back to the Coder is a repair cycle. Bound the loop:
            // once the limit is reached, the Task durably fails instead of looping forever, while
            // the handoff itself is still recorded for the audit trail.
            $isRepairCycle = $fromRole === 'qa' && $locked->candidateReviews()
                ->where('reviewer_agent_run_id', $run->id)
                ->where('status', 'changes_requested')
                ->exists();

            if ($isRepairCycle && $this->repairCycleGuard->limitExceeded($locked)) {
                $limit = (int) config('aisf.max_repair_cycles');
                $locked->update([
                    'status' => 'failed',
                    'blocked_reason' => "The Task exceeded its QA repair cycle limit ({$limit}) and requires operator review.",
                    'last_handoff' => ['id' => $handoff->id, 'to_role' => $toRole, 'reason' => $reason, 'payload' => $payload],
                ]);

                return $handoff;
            }

            $locked->update(['status' => 'waiting', 'last_handoff' => ['id' => $handoff->id, 'to_role' => $toRole, 'reason' => $reason, 'payload' => $payload]]);

            return $handoff;
        }, attempts: 3);
    }

    private function assertActiveRun(AgentRun $run, Task $task, string $role, string $executionToken): void
    {
        $run->loadMissing('agentSession.projectAgent');
        $task->loadMissing('workRequest');
        $acceptedHandoffId = $run->execution_metadata['accepted_handoff_id'] ?? null;
        if ($run->status !== 'running' || ! hash_equals((string) $run->execution_token, $executionToken) || $run->agentSession->task_id !== $task->id || (int) $run->agentSession->projectAgent->project_id !== (int) $task->workRequest->project_id || $run->agentSession->projectAgent->role !== $role || ($acceptedHandoffId !== null && (int) ($task->last_handoff['id'] ?? 0) !== (int) $acceptedHandoffId)) {
            throw new UnexpectedValueException('This Agent execution is stale or is not authorized for the Task action.');
        }
    }

    private function assertActiveProjectManagerRun(AgentRun $run, Task $task, string $executionToken): void
    {
        $run->loadMissing('agentSession.projectAgent');
        if ($run->status !== 'running' || ! hash_equals((string) $run->execution_token, $executionToken) || $run->agentSession->work_request_id !== $task->work_request_id || $run->agentSession->projectAgent->role !== 'project_manager') {
            throw new UnexpectedValueException('This Project Manager execution is stale or is not authorized for the Task handoff.');
        }
    }
}
