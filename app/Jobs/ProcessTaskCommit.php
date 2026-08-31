<?php

namespace App\Jobs;

use App\Models\Task;
use App\Services\TaskCoder;
use App\Services\TaskWorktreeManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

class ProcessTaskCommit implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 90;

    public int $tries = 2;

    public int $backoff = 5;

    public int $uniqueFor = 300;

    /**
     * Create a queued Coder commit-finalization job for a QA-approved Task.
     */
    public function __construct(public Task $task) {}

    /**
     * Keep at most one commit finalization and integration operation active for a Task.
     */
    public function uniqueId(): string
    {
        return (string) $this->task->getKey();
    }

    /**
     * Let the Coder author one commit, verify it, then deterministically fast-forward and clean up.
     */
    public function handle(TaskCoder $coder, TaskWorktreeManager $worktreeManager): void
    {
        $taskId = (int) $this->task->getKey();
        $task = Task::query()->findOrFail($taskId);

        if ($task->status !== 'approved' || $task->approved_at === null) {
            return;
        }

        $task->update([
            'status' => 'committing',
            'blocked_reason' => null,
        ]);

        try {
            $finalization = $coder->finalizeCommit($task->refresh());
            $commit = $worktreeManager->verifyApprovedCommit($task->refresh());

            if (
                $finalization['commit_sha'] !== $commit['commit_sha']
                || $finalization['commit_message'] !== $commit['commit_message']
            ) {
                throw new UnexpectedValueException('The Coder finalization result does not match the verified Task commit.');
            }

            $task->update([
                'status' => 'integrating',
                'commit_sha' => $commit['commit_sha'],
                'commit_message' => $commit['commit_message'],
            ]);

            $integration = $worktreeManager->integrateApprovedCommit($task->refresh());

            $task->update([
                'status' => 'done',
                'integrated_sha' => $integration['commit_sha'],
                'integrated_at' => now(),
                'worktree_cleaned_at' => $integration['worktree_cleaned'] ? now() : null,
                'branch_deleted_at' => $integration['branch_deleted'] ? now() : null,
                'blocked_reason' => null,
            ]);
        } catch (UnexpectedValueException $exception) {
            $this->markBlocked($taskId, $exception->getMessage());
        }
    }

    /**
     * Record unrecoverable queue failures without replacing successful integration state.
     */
    public function failed(?Throwable $exception): void
    {
        $this->markBlocked(
            (int) $this->task->getKey(),
            $exception?->getMessage() ?? 'Coder commit finalization or Task integration failed.',
        );
    }

    /**
     * Persist an operator-visible reason while the Task has not completed integration.
     */
    private function markBlocked(int $taskId, string $message): void
    {
        Task::query()
            ->whereKey($taskId)
            ->where('status', '!=', 'done')
            ->update([
                'status' => 'blocked',
                'blocked_reason' => Str::limit($message, 10000, ''),
            ]);
    }
}
