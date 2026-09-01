<?php

use App\Models\Project;
use App\Models\Task;
use App\Services\TaskCandidateFingerprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

test('an unchanged worktree produces the base tree without changing Git state', function () {
    $task = fingerprintTaskFixture();
    $beforeHead = trim(Process::path($task->worktree_path)->run(['git', 'rev-parse', 'HEAD'])->output());
    $beforeStatus = Process::path($task->worktree_path)->run(['git', 'status', '--porcelain=v1'])->output();

    $fingerprint = app(TaskCandidateFingerprint::class)->forTask($task);

    expect($fingerprint['kind'])->toBe('no_change')
        ->and($fingerprint['tree_sha'])->toBe($fingerprint['base_tree_sha'])
        ->and(trim(Process::path($task->worktree_path)->run(['git', 'rev-parse', 'HEAD'])->output()))->toBe($beforeHead)
        ->and(Process::path($task->worktree_path)->run(['git', 'status', '--porcelain=v1'])->output())->toBe($beforeStatus);
});

test('tracked staged unstaged deleted and untracked changes are fingerprinted without changing the real index', function () {
    $task = fingerprintTaskFixture();
    $path = (string) $task->worktree_path;
    File::put($path.'/staged.txt', "staged\n");
    Process::path($path)->run(['git', 'add', 'staged.txt'])->throw();
    File::put($path.'/staged.txt', "staged and unstaged\n");
    File::delete($path.'/.gitkeep');
    File::put($path.'/untracked.txt', "untracked\n");
    $beforeIndex = Process::path($path)->run(['git', 'diff', '--cached', '--binary'])->output();
    $beforeStatus = Process::path($path)->run(['git', 'status', '--porcelain=v1'])->output();

    $first = app(TaskCandidateFingerprint::class)->forTask($task);
    File::put($path.'/untracked.txt', "different\n");
    $second = app(TaskCandidateFingerprint::class)->forTask($task);

    expect($first['kind'])->toBe('changes')
        ->and($second['tree_sha'])->not->toBe($first['tree_sha'])
        ->and(Process::path($path)->run(['git', 'diff', '--cached', '--binary'])->output())->toBe($beforeIndex)
        ->and(Process::path($path)->run(['git', 'status', '--porcelain=v1'])->output())->not->toBe('')
        ->and($beforeStatus)->toContain('staged.txt')->toContain('untracked.txt');
});

function fingerprintTaskFixture(): Task
{
    $path = sys_get_temp_dir().'/aisf-fingerprint-'.Str::uuid();
    File::makeDirectory($path);
    Process::path($path)->run(['git', 'init', '--initial-branch=main'])->throw();
    File::put($path.'/.gitkeep', '');
    Process::path($path)->run(['git', 'add', '.gitkeep'])->throw();
    Process::path($path)->run([
        'git', '-c', 'user.name=AISF Tests', '-c', 'user.email=aisf-tests@example.test',
        'commit', '-m', 'chore: initialize fixture',
    ])->throw();
    $baseSha = trim(Process::path($path)->run(['git', 'rev-parse', 'HEAD'])->output());
    $project = Project::factory()->create(['path' => $path]);
    $workRequest = $project->workRequests()->create(['prompt' => 'Fingerprint this change.']);

    return $workRequest->tasks()->create([
        'position' => 1,
        'title' => 'Fingerprint candidate',
        'objective' => 'Fingerprint candidate',
        'implementation_spec' => '',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
        'base_sha' => $baseSha,
        'worktree_path' => $path,
    ]);
}
