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
        private readonly AgentExecutionRunner $runner,
        private readonly CandidateAcceptanceGate $candidateAcceptanceGate,
        private readonly RepairCycleGuard $repairCycleGuard,
        private readonly AgentRunActionRecorder $actionRecorder,
    ) {}

    /**
     * Verify and integrate the Coder reported candidate only after current QA approval.
     */
    public function integrate(
        Task $task,
        AgentRun $coderRun,
        string $commitSha,
        string $summary,
    ): void {
        $task = $task->fresh();

        if (! $this->candidateAcceptanceGate->hasCurrentApproval($task)) {
            throw new UnexpectedValueException(
                'A Coder may not report a commit before the current candidate has QA approval.',
            );
        }

        $result = $this->runner->integrateReportedCommit(
            $task,
            $commitSha,
            $summary,
        );

        if ($result === null) {
            throw new UnexpectedValueException(
                'A Coder may not report an empty commit SHA for finalization.',
            );
        }

        if ($result['integrated'] === true) {
            $this->complete($task, $coderRun, $result);

            return;
        }

        $this->repair($task, $coderRun, $result['ci_output']);
    }

    /**
     * Atomically complete the Task and attribute candidate finalization to the responsible Coder run.
     *
     * @param  array{integrated: true, commit_sha: string, pull_request_url: string}  $result
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
                    'blocked_reason' => "The Task exceeded its repair cycle limit ({$limit}) after a CI failure and requires operator review.",
                ]);

                return;
            }

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
