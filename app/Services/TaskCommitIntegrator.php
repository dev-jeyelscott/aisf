<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\AgentRunAction;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Finalize a Coder reported commit. Laravel, not the Agent, verifies that the current
 * candidate still has QA approval, verifies the commit and Project CI check, and only then
 * opens a pull request and completes the Task. A CI failure becomes a durable, bounded repair
 * handoff back to the Coder instead of a terminal failure or a red pull request.
 */
class TaskCommitIntegrator
{
    /**
     * Initialize the Task integration service with its existing verification collaborators.
     */
    public function __construct(
        private readonly CandidateAcceptanceGate $candidateAcceptanceGate,
        private readonly RepairCycleGuard $repairCycleGuard,
        private readonly AgentRunActionRecorder $actionRecorder,
        private readonly TaskWorktreeManager $worktreeManager,
        private readonly TaskCandidateFingerprint $candidateFingerprint,
    ) {}

    /**
     * Verify and integrate the Coder reported candidate only after current QA approval.
     */
    public function finalize(
        Task $task,
        AgentRun $coderRun,
        ?string $commitSha,
        string $summary,
        string $executionToken,
    ): void {
        $task = $task->fresh();
        $coderRun->loadMissing('agentSession.projectAgent');

        if (
            $task->status !== 'running'
            || $coderRun->status !== 'running'
            || ! hash_equals((string) $coderRun->execution_token, $executionToken)
            || $coderRun->agentSession->task_id !== $task->id
            || $coderRun->agentSession->projectAgent->role !== 'coder'
            || ($task->last_handoff['to_role'] ?? null) !== 'coder'
            || ($task->last_handoff['reason'] ?? null) !== 'approved'
            || (int) ($coderRun->execution_metadata['accepted_handoff_id'] ?? 0)
                !== (int) ($task->last_handoff['id'] ?? 0)
        ) {
            throw new UnexpectedValueException('Only the active approved Coder execution may finalize this Task.');
        }

        if (! $this->candidateAcceptanceGate->hasCurrentApproval($task)) {
            throw new UnexpectedValueException(
                'A Coder may not report a commit before the current candidate has QA approval.',
            );
        }

        $currentTreeSha = $this->candidateFingerprint->currentTreeSha($task);

        if ($currentTreeSha !== $task->candidate_tree_sha) {
            throw new UnexpectedValueException(
                'The Task worktree no longer matches the approved candidate tree.',
            );
        }

        $this->actionRecorder->assertVaultNoteWritten($coderRun);

        if ($task->candidate_kind === 'no_change') {
            if (filled($commitSha)) {
                throw new UnexpectedValueException('A no-change candidate must not create a commit.');
            }

            $baseTreeSha = $this->candidateFingerprint->forTask($task)['base_tree_sha'];

            if ($currentTreeSha !== $baseTreeSha) {
                throw new UnexpectedValueException('A no-change candidate must equal the Task base tree.');
            }

            $ci = $this->worktreeManager->runCiCheck($task);

            if (! $ci['passed']) {
                $this->repair($task, $coderRun, $ci['output']);

                return;
            }

            $this->complete($task, $coderRun, [
                'outcome' => 'no_change',
                'commit_sha' => null,
                'pull_request_url' => null,
            ]);

            return;
        }

        if ($task->candidate_kind !== 'changes' || ! filled($commitSha)) {
            throw new UnexpectedValueException('A changed candidate requires a commit SHA for finalization.');
        }

        $verifiedSha = $this->worktreeManager->verifyCommitExists($task, $commitSha);
        $this->worktreeManager->verifyHeadMatches($task, $verifiedSha);

        if ($this->candidateFingerprint->commitTreeSha($task, $verifiedSha) !== $task->candidate_tree_sha) {
            throw new UnexpectedValueException('The final commit tree does not match the approved candidate tree.');
        }

        $ci = $this->worktreeManager->runCiCheck($task);

        if (! $ci['passed']) {
            $this->repair($task, $coderRun, $ci['output']);

            return;
        }

        $pullRequest = $this->worktreeManager->pushAndOpenPullRequest($task, $verifiedSha, $task->title, $summary);

        $this->complete($task, $coderRun, [
            'outcome' => 'implemented',
            ...$pullRequest,
        ]);
    }

    /**
     * Atomically complete the Task and attribute candidate finalization to the responsible Coder run.
     *
     * @param  array{outcome: 'implemented'|'no_change', commit_sha: string|null, pull_request_url: string|null}  $result
     */
    private function complete(
        Task $task,
        AgentRun $coderRun,
        array $result,
    ): void {
        DB::transaction(function () use (
            $task,
            $coderRun,
            $result,
        ): void {
            $locked = Task::query()
                ->lockForUpdate()
                ->findOrFail($task->id);

            if ($locked->status !== 'running') {
                return;
            }

            $locked->update([
                'status' => 'completed',
                'outcome' => $result['outcome'],
                'commit_sha' => $result['commit_sha'],
                'pull_request_url' => $result['pull_request_url'],
                'blocked_reason' => null,
                'last_handoff' => null,
            ]);

            $this->actionRecorder->record(
                $coderRun,
                AgentRunAction::ACTION_CANDIDATE_FINALIZED,
                $locked,
            );

            $workRequest = $locked->workRequest()->lockForUpdate()->firstOrFail();

            if (! $workRequest->tasks()->where('status', '!=', 'completed')->exists()) {
                $workRequest->update([
                    'status' => 'completed',
                    'outcome' => 'implemented',
                    'failure_reason' => null,
                    'last_handoff' => null,
                ]);

                $this->actionRecorder->record(
                    $coderRun,
                    AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED,
                    $workRequest,
                );
            }
        }, attempts: 3);
    }

    /**
     * Persist a bounded CI repair outcome and attribute any created handoff to the Coder run.
     */
    private function repair(
        Task $task,
        AgentRun $coderRun,
        string $ciOutput,
    ): void {
        $limit = (int) config('aisf.max_repair_cycles');

        DB::transaction(function () use (
            $task,
            $coderRun,
            $ciOutput,
            $limit,
        ): void {
            $locked = Task::query()
                ->lockForUpdate()
                ->findOrFail($task->id);

            if ($locked->status !== 'running') {
                return;
            }

            if ($this->repairCycleGuard->limitExceeded($locked)) {
                $locked->update([
                    'status' => 'failed',
                    'outcome' => 'blocked',
                    'blocked_reason' => "The Task exceeded its repair cycle limit ({$limit}) after a CI failure and requires operator review.",
                ]);

                $this->actionRecorder->record(
                    $coderRun,
                    AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED,
                    $locked,
                );

                return;
            }

            $this->worktreeManager->resetToBasePreservingChanges($locked);

            $coderRun->loadMissing('agentSession');

            $handoff = $locked->handoffs()->create([
                'from_project_agent_id' => $coderRun->agentSession->project_agent_id,
                'to_project_agent_id' => $coderRun->agentSession->project_agent_id,
                'from_agent_run_id' => $coderRun->id,
                'reason' => 'ci_failed',
                'payload' => [
                    'ci_output' => Str::limit($ciOutput, 10000, ''),
                ],
                'idempotency_key' => 'ci-failure-'.$coderRun->id,
                'dispatched_at' => now(),
            ]);

            $this->actionRecorder->record(
                $coderRun,
                AgentRunAction::ACTION_HANDOFF_CREATED,
                $handoff,
            );

            $locked->update([
                'status' => 'waiting',
                'candidate_tree_sha' => null,
                'candidate_created_by_run_id' => null,
                'candidate_kind' => null,
                'commit_sha' => null,
                'blocked_reason' => null,
                'last_handoff' => [
                    'id' => $handoff->id,
                    'to_role' => 'coder',
                    'reason' => 'ci_failed',
                    'payload' => $handoff->payload,
                ],
            ]);
        }, attempts: 3);
    }
}
