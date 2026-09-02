<?php

use App\Models\Project;
use App\Services\ProjectAgentProvisioner;
use Database\Seeders\ProjectSkillsSeeder;

/**
 * Verify that default skills are assigned to their intended roles without disturbing existing skills.
 */
test('seeds role skills and appends them after existing assignments', function () {
    $project = Project::factory()->create();

    app(ProjectAgentProvisioner::class)->ensureFor($project);

    $coder = $project->agents()
        ->where('role', 'coder')
        ->sole();

    $customSkill = $project->skills()->create([
        'name' => 'Project-Specific Coding',
        'description' => 'Existing custom project skill.',
        'instructions' => 'Preserve this existing assignment.',
        'enabled' => true,
    ]);

    $coder->skills()->attach($customSkill->id, [
        'position' => 1,
    ]);

    $this->seed(ProjectSkillsSeeder::class);

    expect($project->skills()->count())->toBe(10);

    $projectManager = $project->agents()
        ->where('role', 'project_manager')
        ->sole();

    expect($projectManager->skills()->pluck('project_skills.name')->all())
        ->toBe([
            'Requirements Analysis and Task Decomposition',
            'Acceptance Criteria and Verification Planning',
            'Workflow and Handoff Coordination',
        ]);

    expect($coder->skills()->pluck('project_skills.name')->all())
        ->toBe([
            'Project-Specific Coding',
            'Repository-Aware Implementation',
            'Verification and Evidence',
            'Git Candidate Discipline',
        ]);

    $qa = $project->agents()
        ->where('role', 'qa')
        ->sole();

    expect($qa->skills()->pluck('project_skills.name')->all())
        ->toBe([
            'Independent Candidate Review',
            'Regression and Browser Verification',
            'Defect Reporting and Repair Handoff',
        ]);
});

/**
 * Verify that rerunning the seeder does not duplicate records or overwrite user-customized skills.
 */
test('can be rerun without duplicating or overwriting seeded skills', function () {
    $project = Project::factory()->create();

    $this->seed(ProjectSkillsSeeder::class);

    $skill = $project->skills()
        ->where('name', 'Repository-Aware Implementation')
        ->sole();

    $skill->update([
        'instructions' => 'Project-specific customized coding instructions.',
        'enabled' => false,
    ]);

    $this->seed(ProjectSkillsSeeder::class);

    expect($project->skills()->count())->toBe(9);

    expect($skill->refresh()->instructions)
        ->toBe('Project-specific customized coding instructions.');

    expect($skill->enabled)->toBeFalse();

    foreach (['project_manager', 'coder', 'qa'] as $role) {
        $agent = $project->agents()
            ->where('role', $role)
            ->sole();

        expect($agent->skills()->count())->toBe(3);
    }
});
