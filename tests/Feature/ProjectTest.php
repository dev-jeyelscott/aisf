<?php

use App\Models\Project;
use App\Services\RepositoryInspector;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('projects are publicly listed with an empty state', function () {
    $response = $this->get(route('projects.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/index')
            ->has('projects', 0));
});

test('projects are publicly listed', function () {
    Project::factory()->create(['title' => 'AISF']);

    $response = $this->get(route('projects.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/index')
            ->has('projects', 1)
            ->where('projects.0.title', 'AISF'));
});

test('a project can be created for a valid Git repository', function () {
    $repositoryPath = temporaryGitRepository();

    $response = $this->post(route('projects.store'), [
        'title' => 'AISF',
        'description' => 'AI Software Factory',
        'path' => $repositoryPath,
        'enabled' => true,
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $project = Project::query()->sole();

    $response->assertRedirect(route('projects.show', $project));

    expect($project)
        ->title->toBe('AISF')
        ->description->toBe('AI Software Factory')
        ->path->toBe($repositoryPath)
        ->enabled->toBeTrue();
});

test('a tilde path is expanded before a project is created', function () {
    $homeDirectory = sys_get_temp_dir().'/aisf-home-'.Str::uuid();
    File::makeDirectory($homeDirectory);
    $repositoryPath = $homeDirectory.'/repository';
    File::makeDirectory($repositoryPath);
    initializeGitRepository($repositoryPath);
    $originalHomeDirectory = getenv('HOME');

    try {
        putenv('HOME='.$homeDirectory);

        $response = $this->post(route('projects.store'), [
            'title' => 'Tilde project',
            'path' => '~/repository',
            'enabled' => true,
        ]);

        $response->assertSessionHasNoErrors();

        expect(Project::query()->sole()->path)->toBe($repositoryPath);
    } finally {
        putenv($originalHomeDirectory === false ? 'HOME' : 'HOME='.$originalHomeDirectory);
    }
});

test('a project can be edited', function () {
    $project = Project::factory()->create([
        'path' => temporaryGitRepository(),
    ]);
    $repositoryPath = temporaryGitRepository();

    $response = $this->put(route('projects.update', $project), [
        'title' => 'Renamed project',
        'description' => 'Updated description',
        'path' => $repositoryPath,
        'enabled' => true,
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('projects.show', $project));

    expect($project->refresh())
        ->title->toBe('Renamed project')
        ->description->toBe('Updated description')
        ->path->toBe($repositoryPath);
});

test('enabled projects reject missing paths, files, and non-Git directories', function (string $kind, string $error) {
    $path = match ($kind) {
        'missing' => sys_get_temp_dir().'/aisf-project-missing-'.Str::uuid(),
        'file' => tap(sys_get_temp_dir().'/aisf-project-file-'.Str::uuid(), fn (string $path) => File::put($path, 'not a directory')),
        'directory' => tap(sys_get_temp_dir().'/aisf-project-directory-'.Str::uuid(), fn (string $path) => File::makeDirectory($path)),
    };

    $this->from(route('projects.create'))
        ->post(route('projects.store'), [
            'title' => 'Invalid project',
            'path' => $path,
            'enabled' => true,
        ])
        ->assertSessionHasErrors(['path' => $error])
        ->assertRedirect(route('projects.create'));
})->with([
    'missing path' => ['missing', 'The project path does not exist.'],
    'file path' => ['file', 'The project path must be a directory.'],
    'non-Git directory' => ['directory', 'The project path must be a valid Git working tree.'],
]);

test('disabled projects may use a path that cannot be inspected', function () {
    $response = $this->post(route('projects.store'), [
        'title' => 'Disabled project',
        'path' => '/path/that/does/not/exist',
        'enabled' => false,
    ]);

    $response->assertSessionHasNoErrors();

    expect(Project::query()->sole()->enabled)->toBeFalse();
});

test('the project workspace displays live repository status and placeholders', function () {
    $repositoryPath = temporaryGitRepository();
    $project = Project::factory()->create(['path' => $repositoryPath]);

    $response = $this->get(route('projects.show', $project));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.path', $repositoryPath)
            ->where('repositoryStatus.branch', 'main')
            ->where('repositoryStatus.headSha', fn (string $sha) => strlen($sha) >= 7)
            ->where('repositoryStatus.isClean', true));
});

test('the project workspace recovers when repository inspection fails', function () {
    $project = Project::factory()->create(['path' => '/path/that/no/longer/exists']);

    $response = $this->get(route('projects.show', $project));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('repositoryStatus', null));
});

test('repository inspection reports clean and dirty working trees', function () {
    $repositoryPath = temporaryGitRepository();
    $repositoryInspector = app(RepositoryInspector::class);

    expect($repositoryInspector->status($repositoryPath))
        ->toMatchArray(['branch' => 'main', 'isClean' => true]);

    File::put($repositoryPath.'/uncommitted.txt', 'dirty');

    expect($repositoryInspector->status($repositoryPath))
        ->toMatchArray(['branch' => 'main', 'isClean' => false]);
});

function temporaryGitRepository(): string
{
    $path = sys_get_temp_dir().'/aisf-project-'.Str::uuid();
    File::makeDirectory($path);

    initializeGitRepository($path);

    return $path;
}

function initializeGitRepository(string $path): void
{
    Process::path($path)->run(['git', 'init', '--initial-branch=main'])->throw();
    File::put($path.'/README.md', '# Test repository');
    Process::path($path)->run(['git', 'add', 'README.md'])->throw();
    Process::path($path)->run([
        'git',
        '-c', 'user.name=AISF Tests',
        '-c', 'user.email=aisf-tests@example.test',
        'commit', '-m', 'Initial commit',
    ])->throw();
}
