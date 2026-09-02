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
    /**
     * Render the Project Agents workspace with complete configuration data.
     */
    public function index(
        Project $project,
        ProjectAgentProvisioner $provisioner,
    ): Response {
        $provisioner->ensureFor($project);

        $agents = $project->agents()
            ->with('skills:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (ProjectAgent $agent): array => [
                'id' => $agent->id,
                'role' => $agent->role,
                'name' => $agent->name,
                'identity' => $agent->identity,
                'harness' => $agent->harness,
                'model' => $agent->model,
                'settings' => $agent->settings,
                'default_context' => $agent->default_context,
                'workflow_instructions' => $agent->workflow_instructions,
                'enabled' => $agent->enabled,
                'skills' => $agent->skills
                    ->map(fn ($skill): array => [
                        'id' => $skill->id,
                        'name' => $skill->name,
                        'position' => (int) $skill
                            ->getRelation('pivot')
                            ->getAttribute('position'),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        $skills = $project->skills()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($skill): array => [
                'id' => $skill->id,
                'name' => $skill->name,
            ])
            ->values()
            ->all();

        return Inertia::render('projects/agents/index', [
            'project' => $project->only('id', 'title'),
            'agents' => $agents,
            'skills' => $skills,
        ]);
    }

    /**
     * Persist one Project Agent configuration and its ordered Skill assignments.
     */
    public function update(
        UpdateProjectAgentRequest $request,
        Project $project,
        ProjectAgent $agent,
    ): RedirectResponse {
        abort_unless($agent->project_id === $project->id, 404);

        $data = $request->validated();
        $settings = blank($data['settings'] ?? null)
            ? null
            : json_decode($data['settings'], true);

        if (! is_array($settings)) {
            $settings = [];
        }

        if (filled($data['reasoning'] ?? null)) {
            $settings['reasoning'] = $data['reasoning'];
        }

        $data['settings'] = $settings === [] ? null : $settings;
        unset($data['reasoning']);

        $agent->update(
            collect($data)
                ->except(['skill_ids', 'skill_positions'])
                ->all(),
        );

        $assignments = [];

        foreach ($data['skill_ids'] ?? [] as $index => $id) {
            $assignments[$id] = [
                'position' => (int) (
                    $data['skill_positions'][$id] ?? $index + 1
                ),
            ];
        }

        $agent->skills()->sync($assignments);

        return to_route('projects.agents.index', $project);
    }
}
