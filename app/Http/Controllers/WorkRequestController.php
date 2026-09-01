<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkRequestRequest;
use App\Jobs\ProcessAgentExecution;
use App\Models\Project;
use App\Models\WorkRequest;
use App\Services\AgentSessionManager;
use Illuminate\Http\RedirectResponse;

class WorkRequestController extends Controller
{
    public function store(StoreWorkRequestRequest $request, Project $project, AgentSessionManager $sessionManager): RedirectResponse
    {
        $agents = $project->agents()->whereIn('role', ['project_manager', 'coder', 'qa'])->where('enabled', true)->get()->keyBy('role');
        foreach (['project_manager', 'coder', 'qa'] as $role) {
            if (! $agents->has($role)) {
                return back()->withErrors(['prompt' => "An enabled {$role} Agent is required before submitting work."]);
            }
        }

        $workRequest = $project->workRequests()->create($request->validated());
        foreach ($agents as $agent) {
            $sessionManager->forSubject($agent, $workRequest);
        }
        ProcessAgentExecution::dispatch($workRequest);

        return to_route('projects.show', $project);
    }

    /**
     * Re-enter a failed WorkRequest as pending so the dispatcher starts a fresh Foreman turn.
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
