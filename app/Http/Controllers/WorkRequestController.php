<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkRequestRequest;
use App\Models\Project;
use App\Models\WorkRequest;
use App\Services\WorkRequestIngestion;
use Illuminate\Http\RedirectResponse;
use UnexpectedValueException;

class WorkRequestController extends Controller
{
    /**
     * A manual submission is the same durable WorkRequest contract a GitHub issue or Notion task
     * produces — see WorkRequestIngestion — just without a stable external identity.
     */
    public function store(StoreWorkRequestRequest $request, Project $project, WorkRequestIngestion $ingestion): RedirectResponse
    {
        try {
            $ingestion->ingest($project, 'manual', $request->validated('prompt'));
        } catch (UnexpectedValueException $exception) {
            return back()->withErrors(['prompt' => $exception->getMessage()]);
        }

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
                'outcome' => null,
                'protocol_recovery_count' => 0,
                'failure_reason' => null,
                'last_handoff' => null,
            ]);
        }

        return to_route('projects.show', $project);
    }
}
