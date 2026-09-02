<?php

namespace App\Services;

use App\Models\Task;

/**
 * Bound each autonomous QA and Coder repair episode without deleting historical evidence.
 */
class RepairCycleGuard
{
    /**
     * Count only repair evidence created after the Task current operator retry boundaries.
     */
    public function repairCycleCount(Task $task): int
    {
        $reviewBoundaryId = (int) ($task->repair_cycle_review_boundary_id ?? 0);
        $handoffBoundaryId = (int) ($task->repair_cycle_handoff_boundary_id ?? 0);

        return $task->candidateReviews()
            ->where('id', '>', $reviewBoundaryId)
            ->where('status', 'changes_requested')
            ->count()
            + $task->handoffs()
                ->where('id', '>', $handoffBoundaryId)
                ->where('reason', 'ci_failed')
                ->count();
    }

    /**
     * Determine whether the active autonomous repair episode has reached its configured limit.
     */
    public function limitExceeded(Task $task): bool
    {
        return $this->repairCycleCount($task)
            >= (int) config('aisf.max_repair_cycles');
    }

    /**
     * Start a fresh repair episode by advancing boundaries beyond all existing durable evidence.
     *
     * The caller must hold the Task row lock so Retry state and boundaries change atomically.
     */
    public function startNewCycleForLockedTask(Task $task): void
    {
        $task->update([
            'repair_cycle_review_boundary_id' => (int) (
                $task->candidateReviews()->max('id') ?? 0
            ),
            'repair_cycle_handoff_boundary_id' => (int) (
                $task->handoffs()->max('id') ?? 0
            ),
        ]);
    }
}
