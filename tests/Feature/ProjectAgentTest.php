<?php

use App\Models\Project;
use App\Services\ProjectAgentProvisioner;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Verify Projects receive exactly one default Agent for each supported role.
 */
test('projects provision Project Manager, Coder, and QA Agents', function () {
    $project = Project::factory()->create();

    app(ProjectAgentProvisioner::class)->ensureFor($project);
    app(ProjectAgentProvisioner::class)->ensureFor($project);

    expect(
        $project->agents()->pluck('role')->sort()->values()->all(),
    )->toBe(['coder', 'project_manager', 'qa']);
});

/**
 * Verify the Agents workspace exposes persisted configuration and Project Skills.
 */
test('agents index exposes complete configuration and project skills', function () {
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);

    $skills = $project->skills()->createMany([
        [
            'name' => 'Beta Skill',
            'instructions' => 'Beta',
            'enabled' => true,
        ],
        [
            'name' => 'Alpha Skill',
            'instructions' => 'Alpha',
            'enabled' => true,
        ],
    ]);

    $agent = $project->agents()->where('role', 'coder')->sole();

    $agent->update([
        'name' => 'Forge',
        'identity' => 'Implementation Engineer',
        'harness' => 'codex',
        'model' => 'gpt-5',
        'settings' => [
            'temperature' => 0.2,
        ],
        'default_context' => 'Build maintainable production code.',
        'workflow_instructions' => 'Implement, verify, and hand off.',
        'enabled' => true,
    ]);

    $agent->skills()->sync([
        $skills[1]->id => ['position' => 1],
        $skills[0]->id => ['position' => 2],
    ]);

    $response = $this->get(
        route('projects.agents.index', $project),
    );

    $response->assertOk();

    $response->assertInertia(
        fn (Assert $page) => $page
            ->component('projects/agents/index')
            ->where('project.id', $project->id)
            ->where('project.title', $project->title)
            ->has('agents', 3)
            ->where('agents.1.id', $agent->id)
            ->where('agents.1.role', 'coder')
            ->where('agents.1.name', 'Forge')
            ->where('agents.1.identity', 'Implementation Engineer')
            ->where('agents.1.harness', 'codex')
            ->where('agents.1.model', 'gpt-5')
            ->where('agents.1.settings.temperature', 0.2)
            ->where(
                'agents.1.default_context',
                'Build maintainable production code.',
            )
            ->where(
                'agents.1.workflow_instructions',
                'Implement, verify, and hand off.',
            )
            ->where('agents.1.enabled', true)
            ->has('agents.1.skills', 2)
            ->where('agents.1.skills.0.id', $skills[1]->id)
            ->where('agents.1.skills.0.position', 1)
            ->where('agents.1.skills.1.id', $skills[0]->id)
            ->where('agents.1.skills.1.position', 2)
            ->has('skills', 2)
            ->where('skills.0.id', $skills[1]->id)
            ->where('skills.0.name', 'Alpha Skill')
            ->where('skills.1.id', $skills[0]->id)
            ->where('skills.1.name', 'Beta Skill'),
    );
});

/**
 * Verify Agent configuration and ordered Project Skills persist through update.
 */
test('agent configuration and ordered project skills persist', function () {
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);

    $agent = $project->agents()->where('role', 'coder')->sole();

    $skills = $project->skills()->createMany([
        [
            'name' => 'First',
            'instructions' => 'One',
            'enabled' => true,
        ],
        [
            'name' => 'Second',
            'instructions' => 'Two',
            'enabled' => true,
        ],
    ]);

    $response = $this->put(
        route('projects.agents.update', [$project, $agent]),
        [
            'name' => 'Build Coder',
            'identity' => 'Senior implementation agent',
            'harness' => 'codex',
            'model' => 'gpt-5',
            'settings' => '{"temperature":0}',
            'reasoning' => 'high',
            'default_context' => 'Project-specific context.',
            'workflow_instructions' => 'Implement and test.',
            'enabled' => true,
            'skill_ids' => [
                $skills[1]->id,
                $skills[0]->id,
            ],
            'skill_positions' => [
                $skills[1]->id => 1,
                $skills[0]->id => 2,
            ],
        ],
    );

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('projects.agents.index', $project));

    $agent->refresh();

    expect($agent->name)
        ->toBe('Build Coder')
        ->and($agent->identity)
        ->toBe('Senior implementation agent')
        ->and($agent->settings)
        ->toBe(['temperature' => 0, 'reasoning' => 'high'])
        ->and($agent->default_context)
        ->toBe('Project-specific context.')
        ->and($agent->workflow_instructions)
        ->toBe('Implement and test.')
        ->and($agent->skills()->pluck('name')->all())
        ->toBe(['Second', 'First']);
});

/**
 * Verify an Agent belonging to another Project cannot be updated.
 */
test('agent update rejects an agent from another project', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();

    app(ProjectAgentProvisioner::class)->ensureFor($project);
    app(ProjectAgentProvisioner::class)->ensureFor($otherProject);

    $otherAgent = $otherProject->agents()
        ->where('role', 'coder')
        ->sole();

    $originalName = $otherAgent->name;

    $response = $this->put(
        route('projects.agents.update', [$project, $otherAgent]),
        [
            'name' => 'Cross Project Change',
            'harness' => 'codex',
            'model' => null,
            'settings' => null,
            'enabled' => true,
        ],
    );

    $response->assertNotFound();

    expect($otherAgent->fresh()->name)->toBe($originalName);
});
