<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\AgentSession;
use App\Models\Task;
use App\Models\TaskHandoff;

class TaskPayloadBuilder
{
    /**
     * Create the Task payload builder from the existing worktree and repair-cycle services.
     */
    public function __construct(
        private readonly TaskWorktreeManager $worktreeManager,
        private readonly RepairCycleGuard $repairCycleGuard,
    ) {}

    /**
     * Serialize one Task and its durable workflow evidence.
     *
     * A numeric run limit preserves the compact Project workspace contract.
     * A null run limit exposes the complete persisted run history for the
     * dedicated Task inspection page.
     *
     * @return array<string, mixed>
     */
    public function task(Task $task, ?int $runLimit = 10): array
    {
        return [
            ...$task->only([
                'id',
                'depends_on_task_id',
                'position',
                'title',
                'objective',
                'implementation_spec',
                'status',
                'protocol_recovery_count',
                'branch_name',
                'worktree_path',
                'blocked_reason',
                'last_handoff',
                'commit_sha',
                'candidate_tree_sha',
                'candidate_kind',
                'outcome',
                'pull_request_url',
            ]),
            'changed_files' => filled($task->worktree_path)
                ? $this->worktreeManager->changedFiles($task)
                : [],
            'agent_sessions' => $task->agentSessions
                ->sortByDesc('updated_at')
                ->map(
                    fn (AgentSession $session): array => $this->session(
                        $session,
                        $runLimit,
                    ),
                )
                ->values()
                ->all(),
            'candidate_reviews' => $task->candidateReviews
                ->map(
                    fn ($review): array => $review->only([
                        'candidate_tree_sha',
                        'status',
                        'summary',
                        'findings',
                        'created_at',
                    ]),
                )
                ->values()
                ->all(),
            'handoffs' => $task->handoffs
                ->sortBy('id')
                ->map(fn (TaskHandoff $handoff): array => [
                    'id' => $handoff->id,
                    'from_role' => $handoff->fromProjectAgent?->role,
                    'to_role' => $handoff->toProjectAgent?->role,
                    'reason' => $handoff->reason,
                    'dispatched_at' => $handoff->dispatched_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'repair_cycle_count' => $this->repairCycleGuard->repairCycleCount($task),
            'repair_cycle_limit' => (int) config('aisf.max_repair_cycles'),
        ];
    }

    /**
     * Serialize one logical Agent session and its persisted invocation evidence.
     *
     * @return array<string, mixed>
     */
    public function session(
        AgentSession $session,
        ?int $runLimit = 10,
    ): array {
        $runs = $session->runs->sortByDesc('attempt');

        if ($runLimit !== null) {
            $runs = $runs->take($runLimit);
        }

        return [
            'id' => $session->id,
            'has_provider_continuity' => filled(
                $session->provider_session_id,
            ),
            'agent' => [
                'id' => $session->projectAgent->id,
                'name' => $session->projectAgent->name,
                'role' => $session->projectAgent->role,
            ],
            'runs' => $runs
                ->map(
                    fn (AgentRun $run): array => $this->run($run),
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * Serialize safe durable Agent invocation metadata and exact submitted context.
     *
     * @return array<string, mixed>
     */
    private function run(AgentRun $run): array
    {
        return [
            'id' => $run->id,
            'attempt' => $run->attempt,
            'purpose' => $run->purpose,
            'status' => $run->status,
            'reconciliation_status' => $run->reconciliation_status,
            'failure_class' => $run->failure_class,
            'context_mode' => $run->context_mode,
            'submitted_input' => $run->submitted_input,
            'context_sources' => $run->context_sources ?? [],
            'output_summary' => $run->output_summary,
            'exit_code' => $run->exit_code,
            'harness' => $run->execution_metadata['harness'] ?? null,
            'model' => $run->execution_metadata['model'] ?? null,
            'started_at' => $run->started_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
