<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectSkillRequest;
use App\Http\Requests\UpdateProjectSkillRequest;
use App\Models\Project;
use App\Models\ProjectSkill;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectSkillController extends Controller
{
    public function index(Project $project): Response
    {
        return Inertia::render('projects/skills/index', ['project' => $project->only('id', 'title'), 'skills' => $project->skills()->orderBy('name')->get()]);
    }

    public function store(StoreProjectSkillRequest $request, Project $project): RedirectResponse
    {
        $project->skills()->create($request->validated());

        return to_route('projects.skills.index', $project);
    }

    public function edit(Project $project, ProjectSkill $skill): Response
    {
        abort_unless($skill->project_id === $project->id, 404);

        return Inertia::render('projects/skills/edit', ['project' => $project->only('id', 'title'), 'skill' => $skill]);
    }

    public function update(UpdateProjectSkillRequest $request, Project $project, ProjectSkill $skill): RedirectResponse
    {
        abort_unless($skill->project_id === $project->id, 404);
        $skill->update($request->validated());

        return to_route('projects.skills.index', $project);
    }

    public function destroy(Project $project, ProjectSkill $skill): RedirectResponse
    {
        abort_unless($skill->project_id === $project->id, 404);
        $skill->delete();

        return to_route('projects.skills.index', $project);
    }
}
