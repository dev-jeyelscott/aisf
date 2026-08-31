<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkRequestRequest;
use App\Jobs\ProcessAgentExecution;
use App\Models\Project;
use App\Models\WorkRequest;
use Illuminate\Http\RedirectResponse;

class WorkRequestController extends Controller
{
    public function store(StoreWorkRequestRequest $request, Project $project): RedirectResponse
    {
        $workRequest = $project->workRequests()->create($request->validated());
        ProcessAgentExecution::dispatch($workRequest);

        return to_route('projects.show', $project);
    }

    /**
     * Re-enter a failed WorkRequest as pending so the dispatcher retries Project Manager planning.
     */
    public function retry(Project $project, WorkRequest $workRequest): RedirectResponse
    {
        abort_unless((int) $workRequest->project_id === $project->id, 404);

        if ($workRequest->status === 'failed') {
            $workRequest->update([
                'status' => 'pending',
                'failure_reason' => null,
                'last_handoff' => null,
            ]);
        }

        return to_route('projects.show', $project);
    }
}
