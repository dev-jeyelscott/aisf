<?php

use App\Models\Project;

test('skills are isolated to their project', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $skill = $project->skills()->create(['name' => 'Testing', 'instructions' => 'Test carefully', 'enabled' => true]);
    $response = $this->put(route('projects.skills.update', [$otherProject, $skill]), ['name' => 'Changed', 'instructions' => 'No', 'enabled' => true]);

    $response->assertNotFound();
    expect($skill->refresh()->name)->toBe('Testing');
});
