<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTaskCoding;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;

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
}
