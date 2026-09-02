<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\AgentRunAction;
use App\Models\ProjectVerificationRun;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Finalize one QA-approved candidate using immutable Git identity and authoritative host verification evidence.
 */
class TaskCommitIntegrator
{
    /**
     * Initialize finalization with candidate, verification, repair, audit, and Git collaborators.
     */
    public function __construct(
        private readonly CandidateAcceptanceGate $candidateAcceptanceGate,
        private readonly RepairCycleGuard $repairCycleGuard,
        private readonly AgentRunActionRecorder $actionRecorder,
        private readonly TaskWorktreeManager $worktreeManager,
        private readonly TaskCandidateFingerprint $candidateFingerprint,
        private readonly ProjectVerificationService $projectVerificationService,
    ) {}

    /**
     * Verify and integrate the active approved Coder candidate without executing an independent legacy CI command.
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
            throw new UnexpectedValueException(
                'Only the active approved Coder execution may finalize this Task.',
            );
        }

        if (! $this->candidateAcceptanceGate->hasCurrentApproval($task)) {
            throw new UnexpectedValueException(
                'A Coder may not report a commit before the current candidate has QA approval.',
            );
        }

        $this->assertCurrentCandidateTree($task);
        $this->actionRecorder->assertVaultNoteWritten($coderRun);

        if ($task->candidate_kind === 'no_change') {
            if (filled($commitSha)) {
                throw new UnexpectedValueException(
                    'A no-change candidate must not create a commit.',
                );
            }

            $baseTreeSha = $this->candidateFingerprint
                ->forTask($task)['base_tree_sha'];

            if ($task->candidate_tree_sha !== $baseTreeSha) {
                throw new UnexpectedValueException(
                    'A no-change candidate must equal the Task base tree.',
                );
            }

            $verification = $this->authoritativeCiVerification(
                $task,
                $coderRun,
                $executionToken,
            );

            if ($verification->status === ProjectVerificationRun::STATUS_FAILED) {
                $this->repair(
                    $task,
                    $coderRun,
                    $this->verificationOutput($verification),
                );

                return;
            }

            $this->assertVerificationPassed($verification);
            $this->assertCurrentCandidateTree($task);

            $this->complete($task, $coderRun, [
                'outcome' => 'no_change',
                'commit_sha' => null,
                'pull_request_url' => null,
            ]);

            return;
        }

        if ($task->candidate_kind !== 'changes' || ! filled($commitSha)) {
            throw new UnexpectedValueException(
                'A changed candidate requires a commit SHA for finalization.',
            );
        }

        $verifiedSha = $this->worktreeManager
            ->verifyCommitExists($task, $commitSha);

        $this->worktreeManager->verifyHeadMatches($task, $verifiedSha);

        if (
            $this->candidateFingerprint->commitTreeSha($task, $verifiedSha)
            !== $task->candidate_tree_sha
        ) {
            throw new UnexpectedValueException(
                'The final commit tree does not match the approved candidate tree.',
            );
        }

        $verification = $this->authoritativeCiVerification(
            $task,
            $coderRun,
            $executionToken,
        );

        if ($verification->status === ProjectVerificationRun::STATUS_FAILED) {
            $this->repair(
                $task,
                $coderRun,
                $this->verificationOutput($verification),
            );

            return;
        }

        $this->assertVerificationPassed($verification);
        $this->assertCurrentCandidateTree($task);

        $pullRequest = $this->worktreeManager->pushAndOpenPullRequest(
            $task,
            $verifiedSha,
            $task->title,
            $summary,
        );

        $this->complete($task, $coderRun, [
            'outcome' => 'implemented',
            ...$pullRequest,
        ]);
    }

    /**
     * Require the physical Task worktree to still match its durable approved candidate identity.
     */
    private function assertCurrentCandidateTree(Task $task): void
    {
        if (
            ! filled($task->candidate_tree_sha)
            || $this->candidateFingerprint->currentTreeSha($task)
                !== $task->candidate_tree_sha
        ) {
            throw new UnexpectedValueException(
                'The Task worktree no longer matches the approved candidate tree.',
            );
        }
    }

    /**
     * Reuse decisive exact-candidate CI evidence or run the approved CI profile through the single host verification service.
     */
    private function authoritativeCiVerification(
        Task $task,
        AgentRun $coderRun,
        string $executionToken,
    ): ProjectVerificationRun {
        $task->loadMissing('workRequest');

        $verification = ProjectVerificationRun::query()
            ->where('project_id', $task->workRequest->project_id)
            ->where('task_id', $task->id)
            ->where('profile', 'ci')
            ->where('target_type', 'task_candidate')
            ->where('candidate_tree_sha', $task->candidate_tree_sha)
            ->whereIn('status', [
                ProjectVerificationRun::STATUS_PASSED,
                ProjectVerificationRun::STATUS_FAILED,
            ])
            ->latest('id')
            ->first();

        if (! $verification instanceof ProjectVerificationRun) {
            $verification = $this->projectVerificationService->run(
                $coderRun,
                $executionToken,
                'ci',
                Str::limit(
                    sprintf(
                        'task-finalization-ci-%d-%s',
                        $task->id,
                        (string) $task->candidate_tree_sha,
                    ),
                    100,
                    '',
                ),
            );
        }

        $this->assertVerificationMatchesCandidate($task, $verification);

        return $verification;
    }

    /**
     * Reject verification evidence belonging to another Project, Task, profile, target, or candidate tree.
     */
    private function assertVerificationMatchesCandidate(
        Task $task,
        ProjectVerificationRun $verification,
    ): void {
        $task->loadMissing('workRequest');

        if (
            (int) $verification->project_id
                !== (int) $task->workRequest->project_id
            || (int) $verification->task_id !== (int) $task->id
            || $verification->profile !== 'ci'
            || $verification->target_type !== 'task_candidate'
            || ! is_string($verification->candidate_tree_sha)
            || ! is_string($task->candidate_tree_sha)
            || ! hash_equals(
                $task->candidate_tree_sha,
                $verification->candidate_tree_sha,
            )
        ) {
            throw new UnexpectedValueException(
                'Project verification evidence does not belong to the current Task candidate.',
            );
        }
    }

    /**
     * Permit integration only for an authoritative passed verification and classify inconclusive statuses explicitly.
     */
    private function assertVerificationPassed(
        ProjectVerificationRun $verification,
    ): void {
        if ($verification->status === ProjectVerificationRun::STATUS_PASSED) {
            return;
        }

        $message = match ($verification->status) {
            ProjectVerificationRun::STATUS_STALE_CANDIDATE => 'Authoritative CI verification became stale and cannot authorize finalization.',
            ProjectVerificationRun::STATUS_ENVIRONMENT_UNAVAILABLE => 'The CI verification environment is unavailable. No Coder repair cycle was consumed.',
            ProjectVerificationRun::STATUS_TIMED_OUT => 'Authoritative CI verification timed out. No Coder repair cycle was consumed.',
            ProjectVerificationRun::STATUS_RUNNING => 'Authoritative CI verification is still running and cannot authorize finalization.',
            default => 'Authoritative CI verification did not produce a finalization verdict.',
        };

        throw new UnexpectedValueException($message);
    }

    /**
     * Build bounded diagnostic evidence for a genuine code-level CI repair handoff.
     */
    private function verificationOutput(
        ProjectVerificationRun $verification,
    ): string {
        $parts = array_values(array_filter([
            trim((string) $verification->diagnostic),
            trim((string) $verification->stdout),
            trim((string) $verification->stderr),
        ], static fn (string $part): bool => $part !== ''));

        return $parts !== []
            ? implode("\n\n", $parts)
            : 'The authoritative CI profile failed without additional output.';
    }

    /**
     * Atomically complete the Task and attribute candidate finalization to the responsible Coder run.
     *
     * @param array{
     *     outcome: 'implemented'|'no_change',
     *     commit_sha: string|null,
     *     pull_request_url: string|null
     * } $result
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

            $workRequest = $locked->workRequest()
                ->lockForUpdate()
                ->firstOrFail();

            if (
                ! $workRequest->tasks()
                    ->where('status', '!=', 'completed')
                    ->exists()
            ) {
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
     * Persist one genuine authoritative CI failure and then enforce the active repair limit.
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

            $handoffState = [
                'id' => $handoff->id,
                'to_role' => 'coder',
                'reason' => 'ci_failed',
                'payload' => $handoff->payload,
            ];

            $candidateReset = [
                'candidate_tree_sha' => null,
                'candidate_created_by_run_id' => null,
                'candidate_kind' => null,
                'commit_sha' => null,
                'last_handoff' => $handoffState,
            ];

            if ($this->repairCycleGuard->limitExceeded($locked)) {
                $locked->update([
                    ...$candidateReset,
                    'status' => 'failed',
                    'outcome' => 'blocked',
                    'blocked_reason' => "The Task reached its repair cycle limit ({$limit}) after an authoritative CI failure and requires operator review.",
                ]);

                $this->actionRecorder->record(
                    $coderRun,
                    AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED,
                    $locked,
                );

                return;
            }

            $locked->update([
                ...$candidateReset,
                'status' => 'waiting',
                'blocked_reason' => null,
            ]);
        }, attempts: 3);
    }
}
