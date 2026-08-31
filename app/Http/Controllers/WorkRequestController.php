<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkRequestRequest;
use App\Jobs\ProcessWorkRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class WorkRequestController extends Controller
{
    public function store(StoreWorkRequestRequest $request, Project $project): RedirectResponse
    {
        $workRequest = $project->workRequests()->create($request->validated());
        ProcessWorkRequest::dispatch($workRequest);

        return to_route('projects.show', $project);
    }
}
