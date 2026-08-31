<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Services\ProjectAgentProvisioner;
use App\Services\RepositoryInspector;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        return Inertia::render('projects/index', [
            'projects' => Project::query()->orderBy('title')->get(['id', 'title', 'description', 'path', 'enabled']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('projects/create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProjectRequest $request, ProjectAgentProvisioner $provisioner): RedirectResponse
    {
        $project = Project::query()->create($request->validated());
        $provisioner->ensureFor($project);

        return to_route('projects.show', $project);
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project, RepositoryInspector $repositoryInspector, ProjectAgentProvisioner $provisioner): Response
    {
        $provisioner->ensureFor($project);

        return Inertia::render('projects/show', [
            'project' => $project->only(['id', 'title', 'description', 'path', 'enabled']),
            'repositoryStatus' => $project->enabled ? $repositoryInspector->status($project->path) : null,
            'workRequests' => $project->workRequests()->with('tasks')->get(),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Project $project): Response
    {
        return Inertia::render('projects/edit', [
            'project' => $project->only(['id', 'title', 'description', 'path', 'enabled']),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        return to_route('projects.show', $project);
    }
}
