<?php

namespace App\Jobs;

use App\Models\AgentSession;
use App\Models\Task;
use App\Services\TaskQaReviewer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

class ProcessTaskQaReview implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Keep worker execution below the repository's default database queue retry_after value.
     */
    public int $timeout = 90;

    /**
     * Allow one retry for transient harness infrastructure failures.
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
     * Create a new queued Task QA review job.
     */
    public function __construct(public Task $task) {}

    /**
     * Keep at most one active QA review execution per Task.
     */
    public function uniqueId(): string
    {
        return (string) $this->task->getKey();
    }

    /**
     * Run the read-only QA Agent against the ready-for-QA Task and persist its structured review evidence.
     */
    public function handle(TaskQaReviewer $reviewer): void
    {
        $taskId = (int) $this->task->getKey();
        $task = Task::query()->findOrFail($taskId);

        if ($task->status !== 'ready_for_qa') {
            return;
        }

        $task->update([
            'status' => 'qa_reviewing',
            'blocked_reason' => null,
        ]);

        try {
            $result = $reviewer->run($task);
        } catch (UnexpectedValueException $exception) {
            $this->markBlocked($taskId, $exception->getMessage());

            return;
        }

        $this->persistReview($taskId, $result);
    }

    /**
     * Mark a terminal queue failure without overwriting a Task another retry already resolved.
     */
    public function failed(?Throwable $exception): void
    {
        $this->markBlocked(
            (int) $this->task->getKey(),
            $exception?->getMessage() ?? 'Quality Assurance review failed.',
        );
    }

    /**
     * Persist the validated QA review and transition the Task to its returned status.
     *
     * @param  array{session: AgentSession, completion: array{status: string, summary: string, acceptance_criteria_results: list<array{criterion: string, met: bool, note: string}>, verification_results: list<array{command: string, passed: bool, notes: string}>, browser_result: array{mode: string, passed: bool|null, notes: string}, findings: list<string>}}  $result
     */
    private function persistReview(int $taskId, array $result): void
    {
        DB::transaction(function () use ($taskId, $result): void {
            $task = Task::query()->lockForUpdate()->findOrFail($taskId);

            if ($task->status !== 'qa_reviewing') {
                return;
            }

            $completion = $result['completion'];
            $session = $result['session'];

            $task->qaReviews()->create([
                'agent_session_id' => $session->getKey(),
                'status' => $completion['status'],
                'summary' => $completion['summary'],
                'acceptance_criteria_results' => $completion['acceptance_criteria_results'],
                'verification_results' => $completion['verification_results'],
                'browser_result' => $completion['browser_result'],
                'findings' => $completion['findings'],
            ]);

            $task->update([
                'status' => $completion['status'],
                'blocked_reason' => null,
                'approved_at' => $completion['status'] === 'approved' ? now() : null,
            ]);
        }, attempts: 3);
    }

    /**
     * Persist a concise terminal block reason only while the Task has not already progressed past this review.
     */
    private function markBlocked(int $taskId, string $message): void
    {
        Task::query()
            ->whereKey($taskId)
            ->whereNotIn('status', [
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
