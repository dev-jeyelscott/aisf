<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTaskQaReview;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class QaReviewController extends Controller
{
    /**
     * Queue the QA Agent review run for one ready-for-QA Task belonging to the Project.
     */
    public function store(Project $project, Task $task): RedirectResponse
    {
        abort_unless((int) $task->workRequest->project_id === $project->id, 404);

        if ($task->status === 'ready_for_qa') {
            ProcessTaskQaReview::dispatch($task);
        }

        return to_route('projects.show', $project);
    }

    /**
     * Persist the single operator browser confirmation that unlocks QA approval when automation is unavailable.
     */
    public function confirmBrowserCheck(Project $project, Task $task): RedirectResponse
    {
        abort_unless((int) $task->workRequest->project_id === $project->id, 404);

        if ($task->status === 'manual_browser_check_required') {
            DB::transaction(function () use ($task): void {
                $locked = Task::query()->lockForUpdate()->findOrFail($task->getKey());

                if ($locked->status !== 'manual_browser_check_required') {
                    return;
                }

                $review = $locked->qaReviews()
                    ->where('status', 'manual_browser_check_required')
                    ->latest('id')
                    ->first();

                if ($review === null) {
                    return;
                }

                $review->update(['operator_confirmed_at' => now()]);

                $locked->update([
                    'status' => 'approved',
                    'approved_at' => now(),
                ]);
            });
        }

        return to_route('projects.show', $project);
    }
}
