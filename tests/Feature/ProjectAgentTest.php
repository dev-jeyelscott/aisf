<?php

use App\Models\Project;
use App\Services\ProjectAgentProvisioner;

test('projects provision exactly the three required agents', function () {
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    app(ProjectAgentProvisioner::class)->ensureFor($project);

    expect($project->agents()->pluck('role')->sort()->values()->all())->toBe(['coder', 'project_manager', 'quality_assurance_specialist']);
});

test('agent configuration and ordered project skills persist', function () {
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $agent = $project->agents()->where('role', 'coder')->sole();
    $skills = $project->skills()->createMany([['name' => 'First', 'instructions' => 'One', 'enabled' => true], ['name' => 'Second', 'instructions' => 'Two', 'enabled' => true]]);
    $response = $this->put(route('projects.agents.update', [$project, $agent]), ['name' => 'Build Coder', 'harness' => 'codex', 'model' => 'gpt-5', 'settings' => '{"temperature":0}', 'enabled' => true, 'skill_ids' => [$skills[1]->id, $skills[0]->id], 'skill_positions' => [$skills[1]->id => 1, $skills[0]->id => 2]]);
    $response->assertSessionHasNoErrors();
    $agent->refresh();
    expect($agent->name)->toBe('Build Coder')->and($agent->skills()->pluck('name')->all())->toBe(['Second', 'First']);
});
