<?php

use App\Models\Project;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Verify the Project workspace exposes the configuration required by the
 * redesigned project-level controls.
 */
test('project workspace exposes repository and project control data', function () {
    $repositoryPath = projectWorkspaceRepository();

    $project = Project::factory()->create([
        'path' => $repositoryPath,
        'merge_policy' => 'automatic',
    ]);

    $this->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.id', $project->id)
            ->where('project.enabled', true)
            ->where('project.merge_policy', 'automatic')
            ->where('project.path', $repositoryPath)
            ->where('repositoryStatus.branch', 'main')
            ->where('repositoryStatus.isClean', true)
            ->has('workRequests'));
});

/**
 * Verify Pause and Resume can safely reuse the existing complete Project
 * update contract without introducing a new endpoint or workflow state.
 */
test('project enabled state can be paused and resumed through the existing update contract', function () {
    $repositoryPath = projectWorkspaceRepository();

    $project = Project::factory()->create([
        'title' => 'AISF',
        'description' => 'AI software factory',
        'path' => $repositoryPath,
        'enabled' => true,
        'merge_policy' => 'human',
    ]);

    $this->put(route('projects.update', $project), [
        'title' => $project->title,
        'description' => $project->description,
        'path' => $project->path,
        'enabled' => false,
        'merge_policy' => $project->merge_policy,
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('projects.show', $project));

    expect($project->refresh()->enabled)->toBeFalse();

    $this->put(route('projects.update', $project), [
        'title' => $project->title,
        'description' => $project->description,
        'path' => $project->path,
        'enabled' => true,
        'merge_policy' => $project->merge_policy,
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('projects.show', $project));

    expect($project->refresh()->enabled)->toBeTrue();
});

/**
 * Verify Resume surfaces repository validation rather than enabling a Project
 * whose configured Git working tree is no longer valid.
 */
test('project resume keeps the project disabled when repository validation fails', function () {
    $project = Project::factory()->create([
        'title' => 'Unavailable project',
        'description' => 'Repository is currently unavailable.',
        'path' => '/path/that/does/not/exist',
        'enabled' => false,
        'merge_policy' => 'human',
    ]);

    $this->from(route('projects.show', $project))
        ->put(route('projects.update', $project), [
            'title' => $project->title,
            'description' => $project->description,
            'path' => $project->path,
            'enabled' => true,
            'merge_policy' => $project->merge_policy,
        ])
        ->assertSessionHasErrors(['path'])
        ->assertRedirect(route('projects.show', $project));

    expect($project->refresh()->enabled)->toBeFalse();
});

/**
 * Create an isolated Git repository suitable for Project workspace feature
 * tests.
 */
function projectWorkspaceRepository(): string
{
    $path = sys_get_temp_dir().'/aisf-project-workspace-'.Str::uuid();

    File::makeDirectory($path);

    initializeProjectWorkspaceRepository($path);

    return $path;
}

/**
 * Initialize and commit the minimal repository state required by
 * RepositoryInspector.
 */
function initializeProjectWorkspaceRepository(string $path): void
{
    Process::path($path)
        ->run(['git', 'init', '--initial-branch=main'])
        ->throw();

    File::put($path.'/README.md', '# Project Workspace Test');

    Process::path($path)
        ->run(['git', 'add', 'README.md'])
        ->throw();

    Process::path($path)
        ->run([
            'git',
            '-c',
            'user.name=AISF Tests',
            '-c',
            'user.email=aisf-tests@example.test',
            'commit',
            '-m',
            'Initial commit',
        ])
        ->throw();
}
