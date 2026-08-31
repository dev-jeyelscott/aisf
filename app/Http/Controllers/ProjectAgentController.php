<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProjectAgentRequest;
use App\Models\Project;
use App\Models\ProjectAgent;
use App\Services\ProjectAgentProvisioner;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectAgentController extends Controller
{
    public function index(Project $project, ProjectAgentProvisioner $provisioner): Response
    {
        $provisioner->ensureFor($project);

        return Inertia::render('projects/agents/index', ['project' => $project->only('id', 'title'), 'agents' => $project->agents()->with('skills:id,name')->orderBy('id')->get()]);
    }

    public function edit(Project $project, ProjectAgent $agent, ProjectAgentProvisioner $provisioner): Response
    {
        $provisioner->ensureFor($project);
        abort_unless($agent->project_id === $project->id, 404);

        return Inertia::render('projects/agents/edit', ['project' => $project->only('id', 'title'), 'agent' => $agent->load('skills:id,name'), 'skills' => $project->skills()->orderBy('name')->get()]);
    }

    public function update(UpdateProjectAgentRequest $request, Project $project, ProjectAgent $agent): RedirectResponse
    {
        abort_unless($agent->project_id === $project->id, 404);
        $data = $request->validated();
        $data['settings'] = blank($data['settings'] ?? null) ? null : json_decode($data['settings'], true);
        $agent->update(collect($data)->except(['skill_ids', 'skill_positions'])->all());
        $assignments = [];
        foreach ($data['skill_ids'] ?? [] as $index => $id) {
            $assignments[$id] = ['position' => (int) ($data['skill_positions'][$id] ?? $index + 1)];
        }
        $agent->skills()->sync($assignments);

        return to_route('projects.agents.edit', [$project, $agent]);
    }
}
