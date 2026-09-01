<?php

namespace App\Services;

use App\Models\Task;

/**
 * Bound the QA <-> Coder repair loop so an unrecoverable Task durably fails instead of consuming
 * tokens forever. A repair cycle is either a QA "changes_requested" review or a CI-failure repair
 * handoff created after an approved candidate failed the Project's CI check.
 */
class RepairCycleGuard
{
    public function repairCycleCount(Task $task): int
    {
        return $task->candidateReviews()->where('status', 'changes_requested')->count()
            + $task->handoffs()->where('reason', 'ci_failed')->count();
    }

    public function limitExceeded(Task $task): bool
    {
        return $this->repairCycleCount($task) >= (int) config('aisf.max_repair_cycles');
    }
}
