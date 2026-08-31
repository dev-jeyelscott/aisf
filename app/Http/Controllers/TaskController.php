<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAgentExecution;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /**
     * Queue the next Agent execution for a pending or waiting Task, optionally carrying an operator instruction.
     */
    public function run(Request $request, Project $project, Task $task): RedirectResponse
    {
        abort_unless((int) $task->workRequest->project_id === $project->id, 404);

        $validated = $request->validate([
            'operator_instruction' => ['nullable', 'string', 'max:5000'],
        ]);

        if (in_array($task->status, ['pending', 'waiting'], true)) {
            ProcessAgentExecution::dispatch($task, $validated['operator_instruction'] ?? null);
        }

        return to_route('projects.show', $project);
    }

    /**
     * Re-enter a failed Task as pending so the dispatcher gives it a fresh Coder turn.
     */
    public function retry(Project $project, Task $task): RedirectResponse
    {
        abort_unless((int) $task->workRequest->project_id === $project->id, 404);

        if ($task->status === 'failed') {
            $task->update([
                'status' => 'pending',
                'blocked_reason' => null,
                'last_handoff' => null,
            ]);
        }

        return to_route('projects.show', $project);
    }
}
