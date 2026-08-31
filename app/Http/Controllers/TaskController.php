<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTaskCoding;
use App\Jobs\ProcessTaskCommit;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /**
     * Queue the Coder implementation run for one queued Task belonging to the Project.
     */
    public function start(Project $project, Task $task): RedirectResponse
    {
        abort_unless((int) $task->workRequest->project_id === $project->id, 404);

        if ($task->status === 'queued') {
            ProcessTaskCoding::dispatch($task);
        }

        return to_route('projects.show', $project);
    }

    /**
     * Resume the existing Coder session for a changes-required Task with only the latest QA findings and any new operator instruction.
     */
    public function resume(Request $request, Project $project, Task $task): RedirectResponse
    {
        abort_unless((int) $task->workRequest->project_id === $project->id, 404);

        $validated = $request->validate([
            'operator_instruction' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($task->status === 'changes_required') {
            ProcessTaskCoding::dispatch($task, $validated['operator_instruction'] ?? null);
        }

        return to_route('projects.show', $project);
    }

    /**
     * Queue the approved Task's one Coder-authored commit finalization and deterministic integration.
     */
    public function commit(Project $project, Task $task): RedirectResponse
    {
        abort_unless((int) $task->workRequest->project_id === $project->id, 404);

        if ($task->status === 'approved' && $task->approved_at !== null) {
            ProcessTaskCommit::dispatch($task);
        }

        return to_route('projects.show', $project);
    }
}
