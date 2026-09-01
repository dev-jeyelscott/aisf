<?php

namespace App\Console\Commands;

use App\Models\AgentRun;
use App\Models\AgentRunAction;
use App\Models\Task;
use App\Services\AgentRunActionRecorder;
use App\Services\TaskWorktreeManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('workflow:invalidate-legacy-candidates')]
#[Description('Invalidate nonterminal legacy candidates and return attributable Tasks to the Coder.')]
class InvalidateLegacyCandidates extends Command
{
    public function handle(
        TaskWorktreeManager $worktreeManager,
        AgentRunActionRecorder $actionRecorder,
    ): int {
        $recovered = 0;
        $blocked = 0;

        Task::query()
            ->whereNotNull('candidate_sha')
            ->whereNull('candidate_tree_sha')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with(['workRequest.project', 'agentSessions.projectAgent', 'agentSessions.runs'])
            ->orderBy('id')
            ->chunkById(100, function ($tasks) use ($worktreeManager, $actionRecorder, &$recovered, &$blocked): void {
                foreach ($tasks as $task) {
                    $candidateRun = $task->agentSessions
                        ->filter(fn ($session): bool => $session->projectAgent->role === 'coder')
                        ->flatMap->runs
                        ->sortByDesc('id')
                        ->first();

                    if ($task->pull_request_url !== null || ! $candidateRun instanceof AgentRun) {
                        $task->update([
                            'status' => 'failed',
                            'outcome' => 'blocked',
                            'blocked_reason' => 'Legacy candidate approval could not be safely attributed and requires operator review.',
                        ]);
                        $blocked++;

                        continue;
                    }

                    if (filled($task->commit_sha) && is_dir((string) $task->worktree_path)) {
                        $worktreeManager->resetToBasePreservingChanges($task);
                    }

                    DB::transaction(function () use ($task, $candidateRun, $actionRecorder): void {
                        $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
                        $idempotencyKey = "legacy-candidate-migration-{$locked->id}";
                        $coderAgentId = $candidateRun->agentSession->project_agent_id;
                        $handoff = $locked->handoffs()->firstOrCreate(
                            [
                                'from_agent_run_id' => $candidateRun->id,
                                'idempotency_key' => $idempotencyKey,
                            ],
                            [
                                'from_project_agent_id' => $coderAgentId,
                                'to_project_agent_id' => $coderAgentId,
                                'reason' => 'candidate_fingerprint_migration',
                                'payload' => ['legacy_candidate_sha' => $locked->candidate_sha],
                                'dispatched_at' => now(),
                            ],
                        );

                        if ($handoff->wasRecentlyCreated) {
                            $actionRecorder->record(
                                $candidateRun,
                                AgentRunAction::ACTION_HANDOFF_CREATED,
                                $handoff,
                            );
                        }

                        $locked->update([
                            'status' => 'waiting',
                            'outcome' => null,
                            'candidate_sha' => null,
                            'candidate_tree_sha' => null,
                            'candidate_created_by_run_id' => null,
                            'candidate_kind' => null,
                            'commit_sha' => null,
                            'protocol_recovery_count' => 0,
                            'blocked_reason' => null,
                            'last_handoff' => [
                                'id' => $handoff->id,
                                'to_role' => 'coder',
                                'reason' => 'candidate_fingerprint_migration',
                                'payload' => $handoff->payload,
                            ],
                        ]);
                    }, attempts: 3);
                    $recovered++;
                }
            });

        $this->components->info("Recovered {$recovered} legacy candidates; blocked {$blocked} for operator review.");

        return self::SUCCESS;
    }
}
