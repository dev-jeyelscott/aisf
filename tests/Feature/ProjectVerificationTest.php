<?php

use App\Models\AgentRun;
use App\Models\Project;
use App\Models\ProjectVerificationRun;
use App\Models\Task;
use App\Services\AgentSessionManager;
use App\Services\ProjectAgentProvisioner;
use App\Services\ProjectVerificationService;
use App\Services\TaskCandidateFingerprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

test('host verification persists exact candidate passed evidence without mutating Task workflow state', function () {
    [$project, $task, $qaRun] = projectVerificationFixture([
        'git',
        'status',
        '--short',
    ]);

    $first = app(ProjectVerificationService::class)->run(
        $qaRun,
        $qaRun->execution_token,
        'ci',
        'exact-candidate-pass',
    );

    $second = app(ProjectVerificationService::class)->run(
        $qaRun,
        $qaRun->execution_token,
        'ci',
        'exact-candidate-pass',
    );

    expect($first->status)->toBe(ProjectVerificationRun::STATUS_PASSED);
    expect($first->project_id)->toBe($project->id);
    expect($first->task_id)->toBe($task->id);
    expect($first->profile)->toBe('ci');
    expect($first->target_type)->toBe('task_candidate');
    expect($first->candidate_tree_sha)->toBe($task->candidate_tree_sha);
    expect($second->id)->toBe($first->id);

    expect(
        ProjectVerificationRun::query()
            ->where('agent_run_id', $qaRun->id)
            ->where('idempotency_key', 'exact-candidate-pass')
            ->count(),
    )->toBe(1);

    $freshTask = $task->refresh();

    expect($freshTask->status)->toBe('running');
    expect($freshTask->outcome)->toBeNull();
    expect($freshTask->handoffs()->count())->toBe(0);
});

test('host verification marks a changed worktree as stale candidate without mutating Task workflow state', function () {
    [, $task, $qaRun] = projectVerificationFixture([
        'git',
        'status',
        '--short',
    ]);

    File::append(
        $task->worktree_path.'/README.md',
        "\nCandidate changed after durable fingerprint.\n",
    );

    $verification = app(ProjectVerificationService::class)->run(
        $qaRun,
        $qaRun->execution_token,
        'ci',
        'stale-candidate',
    );

    expect($verification->status)
        ->toBe(ProjectVerificationRun::STATUS_STALE_CANDIDATE);

    expect($task->refresh()->status)->toBe('running');
    expect($task->refresh()->outcome)->toBeNull();
    expect($task->handoffs()->where('reason', 'ci_failed')->count())->toBe(0);
});

test('host verification records environment unavailable without creating a Coder repair', function () {
    [, $task, $qaRun] = projectVerificationFixture([
        'aisf-command-that-does-not-exist',
    ]);

    $verification = app(ProjectVerificationService::class)->run(
        $qaRun,
        $qaRun->execution_token,
        'ci',
        'environment-unavailable',
    );

    expect($verification->status)
        ->toBe(ProjectVerificationRun::STATUS_ENVIRONMENT_UNAVAILABLE);

    expect($task->refresh()->status)->toBe('running');
    expect($task->refresh()->outcome)->toBeNull();
    expect($task->handoffs()->where('reason', 'ci_failed')->count())->toBe(0);
});

test('host verification records a genuine command failure as failed evidence without directly mutating the Task', function () {
    [, $task, $qaRun] = projectVerificationFixture([
        'git',
        'rev-parse',
        '--verify',
        'refs/heads/does-not-exist',
    ]);

    $verification = app(ProjectVerificationService::class)->run(
        $qaRun,
        $qaRun->execution_token,
        'ci',
        'genuine-ci-failure',
    );

    expect($verification->status)
        ->toBe(ProjectVerificationRun::STATUS_FAILED);

    expect($task->refresh()->status)->toBe('running');
    expect($task->refresh()->outcome)->toBeNull();
    expect($task->handoffs()->where('reason', 'ci_failed')->count())->toBe(0);
});

/**
 * Build an isolated Project, Git checkout, Task candidate, and active QA verification run.
 *
 * @param  list<string>  $command
 * @return array{0: Project, 1: Task, 2: AgentRun}
 */
function projectVerificationFixture(array $command): array
{
    config([
        'aisf.allow_trusted_native_verification' => true,
    ]);

    [$repositoryPath, $baseSha] = projectVerificationRepository();

    $project = Project::factory()->create([
        'path' => $repositoryPath,
        'verification_profiles' => [
            'ci' => [
                'driver' => 'native',
                'command' => $command,
                'timeout' => 30,
            ],
        ],
    ]);

    app(ProjectAgentProvisioner::class)->ensureFor($project);

    $workRequest = $project->workRequests()->create([
        'prompt' => 'Verify this exact candidate.',
        'status' => 'waiting',
    ]);

    $task = $workRequest->tasks()->create([
        'position' => 1,
        'title' => 'Verify candidate',
        'objective' => 'Exercise authoritative host verification.',
        'implementation_spec' => 'Use the configured CI profile.',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
        'status' => 'running',
        'base_sha' => $baseSha,
        'base_branch' => 'main',
        'branch_name' => 'test/verification',
        'worktree_path' => $repositoryPath,
    ]);

    $task->update([
        'candidate_tree_sha' => app(TaskCandidateFingerprint::class)
            ->currentTreeSha($task),
        'candidate_kind' => 'no_change',
    ]);

    $qa = $project->agents()
        ->where('role', 'qa')
        ->sole();

    $qaRun = app(AgentSessionManager::class)->startRun(
        app(AgentSessionManager::class)->forSubject($qa, $task),
        'qa',
        [
            'mode' => 'initial',
            'input' => 'Verify the Task candidate.',
            'sources' => [],
            'agent_snapshot' => [],
            'prompt_snapshot' => [],
            'role' => 'qa',
        ],
    );

    return [$project, $task->fresh(), $qaRun];
}

/**
 * Create an isolated Git repository suitable for real native verification tests.
 *
 * @return array{0: string, 1: string}
 */
function projectVerificationRepository(): array
{
    $path = sys_get_temp_dir().'/aisf-project-verification-'.Str::uuid();

    File::makeDirectory($path);

    Process::path($path)
        ->run(['git', 'init', '--initial-branch=main'])
        ->throw();

    File::put($path.'/README.md', "# AISF Verification Test\n");

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
            'Initial verification fixture',
        ])
        ->throw();

    $baseSha = trim(
        Process::path($path)
            ->run(['git', 'rev-parse', 'HEAD'])
            ->throw()
            ->output(),
    );

    return [$path, $baseSha];
}
