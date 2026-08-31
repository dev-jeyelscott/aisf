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

class ProcessTaskCoding implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Let the Coder Agent harness run to completion without an artificial wall-clock kill.
     */
    public int $timeout = 0;

    /**
     * Allow one retry for transient harness or worktree infrastructure failures.
     */
    public int $tries = 2;

    /**
     * Delay a transient retry briefly instead of immediately repeating external execution.
     */
    public int $backoff = 5;

    /**
     * Expire a stale uniqueness lock after the full retry window instead of leaving a crashed job locked indefinitely.
     */
    public int $uniqueFor = 300;

    /**
     * Create a new queued Task Coder implementation job, optionally carrying a new operator instruction for the fix loop.
     */
    public function __construct(public Task $task, public ?string $operatorInstruction = null) {}

    /**
     * Keep at most one active Coder Task execution per Project.
     */
    public function uniqueId(): string
    {
        $this->task->loadMissing('workRequest');

        return (string) $this->task->workRequest->project_id;
    }

    /**
     * Create the isolated Task worktree, run the Coder, and enforce the pre-QA Git boundary.
     */
    public function handle(TaskWorktreeManager $worktreeManager, TaskCoder $coder): void
    {
        $taskId = (int) $this->task->getKey();
        $task = Task::query()->findOrFail($taskId);

        if (! in_array($task->status, ['queued', 'changes_required', 'coding'], true)) {
            return;
        }

        $task->update([
            'status' => 'coding',
            'blocked_reason' => null,
        ]);

        try {
            $worktreeManager->ensureWorktree($task);
            $coder->run($task, $this->operatorInstruction);

            if (! $worktreeManager->headMatchesBase($task)) {
                $this->markBlocked(
                    $taskId,
                    'The Task worktree HEAD moved before Quality Assurance review. The Coder must not commit before QA approves the work.',
                );

                return;
            }
        } catch (UnexpectedValueException $exception) {
            $this->markBlocked($taskId, $exception->getMessage());

            return;
        }

        $task->update([
            'status' => 'ready_for_qa',
            'blocked_reason' => null,
        ]);
    }

    /**
     * Mark a terminal queue failure without overwriting a Task another retry already moved to QA.
     */
    public function failed(?Throwable $exception): void
    {
        $this->markBlocked(
            (int) $this->task->getKey(),
            $exception?->getMessage() ?? 'Coder implementation failed.',
        );
    }

    /**
     * Persist a concise terminal block reason only while the Task has not already progressed past this Coder run.
     */
    private function markBlocked(int $taskId, string $message): void
    {
        Task::query()
            ->whereKey($taskId)
            ->whereNotIn('status', [
                'ready_for_qa',
                'qa_reviewing',
                'changes_required',
                'manual_browser_check_required',
                'approved',
            ])
            ->update([
                'status' => 'blocked',
                'blocked_reason' => Str::limit($message, 10000, ''),
            ]);
    }
}
