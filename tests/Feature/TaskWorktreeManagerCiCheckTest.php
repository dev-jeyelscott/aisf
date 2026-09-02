<?php

use App\Models\Project;
use App\Models\Task;
use App\Services\TaskWorktreeManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

it('treats a passing CI check as neither failed nor an environment failure', function () {
    $task = feature13TaskWithWorktree();

    feature13FakeCiCheck(exitCode: 0, output: '1086 tests passed.');

    $result = app(TaskWorktreeManager::class)->runCiCheck($task);

    expect($result['passed'])->toBeTrue()
        ->and($result['environment'])->toBeFalse();
});

it('treats a genuine code-level CI failure as not an environment failure', function () {
    $task = feature13TaskWithWorktree();

    feature13FakeCiCheck(exitCode: 1, output: 'FAILED: SupplierIndexTest::it_lists_suppliers');

    $result = app(TaskWorktreeManager::class)->runCiCheck($task);

    expect($result['passed'])->toBeFalse()
        ->and($result['environment'])->toBeFalse();
});

it('treats an unconfigured database connection during CI as an environment failure', function () {
    $task = feature13TaskWithWorktree();

    feature13FakeCiCheck(exitCode: 1, output: <<<'TXT'
   InvalidArgumentException

  Database connection [default] not configured.
TXT);

    $result = app(TaskWorktreeManager::class)->runCiCheck($task);

    expect($result['passed'])->toBeFalse()
        ->and($result['environment'])->toBeTrue();
});

it('treats exit codes 126 and 127 as environment failures regardless of output', function (int $exitCode) {
    $task = feature13TaskWithWorktree();

    feature13FakeCiCheck(exitCode: $exitCode, output: 'composer: command not found');

    $result = app(TaskWorktreeManager::class)->runCiCheck($task);

    expect($result['passed'])->toBeFalse()
        ->and($result['environment'])->toBeTrue();
})->with([126, 127]);

/**
 * Fake only the `composer ci:check` invocation while letting real Git commands run for real.
 */
function feature13FakeCiCheck(int $exitCode, string $output): void
{
    Process::fake(function ($process) use ($exitCode, $output) {
        $line = implode(' ', (array) $process->command);

        if (str_starts_with($line, 'composer ci:check')) {
            return Process::result(output: $output, exitCode: $exitCode);
        }

        $real = new Symfony\Component\Process\Process((array) $process->command, $process->path);
        $real->run();

        return Process::result(
            output: $real->getOutput(),
            errorOutput: $real->getErrorOutput(),
            exitCode: $real->getExitCode(),
        );
    });
}

/**
 * Build a Task with a real isolated Task Git worktree for CI-check tests.
 */
function feature13TaskWithWorktree(): Task
{
    $repositoryPath = sys_get_temp_dir().'/aisf-feature13-'.Str::uuid();
    File::makeDirectory($repositoryPath);

    Process::path($repositoryPath)->run(['git', 'init', '--initial-branch=main'])->throw();
    File::put($repositoryPath.'/.gitkeep', '');
    Process::path($repositoryPath)->run(['git', 'add', '.gitkeep'])->throw();
    Process::path($repositoryPath)->run([
        'git', '-c', 'user.name=AISF Tests', '-c', 'user.email=aisf-tests@example.test',
        'commit', '-m', 'chore: initialize fixture',
    ])->throw();

    $project = Project::factory()->create(['path' => $repositoryPath]);
    $workRequest = $project->workRequests()->create(['prompt' => 'Fix the CI check.']);
    $task = $workRequest->tasks()->create([
        'position' => 1,
        'title' => 'Fix the CI check',
        'objective' => 'Verify CI check environment classification.',
        'implementation_spec' => '',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
        'status' => 'running',
        'last_handoff' => ['to_role' => 'coder'],
    ]);

    app(TaskWorktreeManager::class)->ensureWorktree($task);

    return $task->refresh();
}
