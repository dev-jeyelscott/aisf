<?php

use App\Jobs\ProcessAgentExecution;
use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\Task;
use App\Models\WorkRequest;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResult;
use App\Services\ProjectAgentProvisioner;
use App\Services\TaskWorktreeManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class Feature09FakeAgentHarness extends AgentHarness
{
    public string $prompt = '';

    public bool $writable = false;

    /**
     * @param  (callable(string $worktreePath): void)|null  $sideEffect
     */
    public function __construct(
        private readonly string|Throwable $result,
        private $sideEffect = null,
    ) {}

    public function canResume(ProjectAgent $agent): bool
    {
        return false;
    }

    public function start(
        ProjectAgent $agent,
        string $repositoryPath,
        string $prompt,
        ?array $schema = null,
        bool $writable = false,
    ): AgentHarnessResult {
        $this->prompt = $prompt;
        $this->writable = $writable;

        if ($this->sideEffect !== null) {
            ($this->sideEffect)($repositoryPath);
        }

        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return new AgentHarnessResult(
            successful: true,
            output: $this->result,
            providerSessionId: null,
            exitCode: 0,
        );
    }

    public function resume(
        ProjectAgent $agent,
        string $repositoryPath,
        string $providerSessionId,
        string $prompt,
        ?array $schema = null,
        bool $writable = false,
    ): AgentHarnessResult {
        return $this->start($agent, $repositoryPath, $prompt, $schema, $writable);
    }
}

test('PM completion creates loosely-specified Tasks without requiring acceptance criteria or browser steps', function () {
    [, $workRequest] = feature09Fixture();
    feature09FakeHarness(feature09PmCompletion([
        'summary' => 'Add a root README.',
        'already_implemented' => false,
        'tasks' => [
            ['title' => 'Add README.md', 'objective' => 'Document the project.'],
        ],
    ]));

    app()->call([new ProcessAgentExecution($workRequest), 'handle']);

    $workRequest->refresh();
    $task = $workRequest->tasks()->sole();

    expect($workRequest->status)->toBe('completed')
        ->and($workRequest->summary)->toBe('Add a root README.')
        ->and($task->position)->toBe(1)
        ->and($task->title)->toBe('Add README.md')
        ->and($task->status)->toBe('pending')
        ->and($task->acceptance_criteria)->toBe([]);
});

test('PM already-implemented completion marks the WorkRequest completed without creating Tasks', function () {
    [, $workRequest] = feature09Fixture();
    feature09FakeHarness(feature09PmCompletion([
        'summary' => 'The README already exists.',
        'already_implemented' => true,
        'tasks' => [],
    ]));

    app()->call([new ProcessAgentExecution($workRequest), 'handle']);

    $workRequest->refresh();

    expect($workRequest->status)->toBe('completed')
        ->and($workRequest->evidence)->toBe(['The README already exists.'])
        ->and($workRequest->tasks()->count())->toBe(0);
});

test('a trivial documentation Task completes directly without review or a commit', function () {
    [, , $task] = feature09TaskFixture();
    feature09FakeHarness(feature09Completion([
        'status' => 'completed',
        'summary' => 'Added the README.',
    ]));

    app()->call([new ProcessAgentExecution($task), 'handle']);

    expect($task->refresh()->status)->toBe('completed')
        ->and($task->last_handoff)->toBeNull()
        ->and($task->commit_sha)->toBeNull();
});

test('ensureWorktree recovers from a stale branch and worktree left by a previous failed attempt', function () {
    [$project, , $task] = feature09TaskFixture();
    $repositoryPath = $project->path;
    $branchName = "aisf/task-{$task->id}";
    $worktreePath = rtrim((string) config('aisf.worktree_base_path'), '/')."/task-{$task->id}";

    if (is_dir($worktreePath)) {
        File::deleteDirectory($worktreePath);
    }

    // Simulate a prior attempt that created the branch and worktree, then had its directory
    // removed without going through `git worktree remove` (leaving the branch and the worktree's
    // admin metadata behind) — exactly what made a retry fail with "Unable to create the isolated
    // Task Git worktree." even though nothing was actually still using that branch or directory.
    Process::path($repositoryPath)->run(['git', 'worktree', 'add', '-b', $branchName, $worktreePath, 'HEAD'])->throw();
    File::deleteDirectory($worktreePath);

    app(TaskWorktreeManager::class)->ensureWorktree($task);
    $task->refresh();

    expect($task->worktree_path)->toBe($worktreePath)
        ->and(is_dir($worktreePath))->toBeTrue();
});

test('a Coder completion with a reported commit SHA is verified, pushed, and opened as a pull request', function () {
    [, , $task] = feature09TaskFixture();

    app(TaskWorktreeManager::class)->ensureWorktree($task);
    $task->refresh();
    $worktreePath = (string) $task->worktree_path;

    File::put($worktreePath.'/README.md', "# MiseLedger\n");
    Process::path($worktreePath)->run(['git', 'add', 'README.md'])->throw();
    Process::path($worktreePath)->run([
        'git', '-c', 'user.name=AISF Tests', '-c', 'user.email=aisf-tests@example.test',
        'commit', '-m', 'docs: add readme',
    ])->throw();
    $commitSha = trim(Process::path($worktreePath)->run(['git', 'rev-parse', 'HEAD'])->output());

    feature09FakeHarness(feature09Completion([
        'status' => 'completed',
        'summary' => 'Added and committed the README.',
        'commit_sha' => $commitSha,
    ]));

    feature09FakeRemoteGit([
        'composer ci:check' => fn () => Process::result(exitCode: 0),
        'git push' => fn () => Process::result(exitCode: 0),
        'gh pr create' => fn () => Process::result(output: "https://github.com/example/aisf/pull/42\n"),
    ]);

    app()->call([new ProcessAgentExecution($task), 'handle']);

    $task->refresh();

    expect($task->status)->toBe('waiting')
        ->and($task->commit_sha)->toBe($commitSha)
        ->and($task->candidate_sha)->toBe($commitSha)
        ->and($task->last_handoff['to_role'])->toBe('foreman')
        ->and($task->pull_request_url)->toBe('https://github.com/example/aisf/pull/42');
});

test('a pull request is reused when one already exists for the Task branch', function () {
    [, , $task] = feature09TaskFixture();

    app(TaskWorktreeManager::class)->ensureWorktree($task);
    $task->refresh();
    $worktreePath = (string) $task->worktree_path;

    File::put($worktreePath.'/README.md', "# MiseLedger\n");
    Process::path($worktreePath)->run(['git', 'add', 'README.md'])->throw();
    Process::path($worktreePath)->run([
        'git', '-c', 'user.name=AISF Tests', '-c', 'user.email=aisf-tests@example.test',
        'commit', '-m', 'docs: add readme',
    ])->throw();
    $commitSha = trim(Process::path($worktreePath)->run(['git', 'rev-parse', 'HEAD'])->output());

    feature09FakeHarness(feature09Completion([
        'status' => 'completed',
        'summary' => 'Added and committed the README.',
        'commit_sha' => $commitSha,
    ]));

    feature09FakeRemoteGit([
        'composer ci:check' => fn () => Process::result(exitCode: 0),
        'git push' => fn () => Process::result(exitCode: 0),
        'gh pr create' => fn () => Process::result(exitCode: 1, errorOutput: 'a pull request for branch already exists'),
        'gh pr view' => fn () => Process::result(output: "https://github.com/example/aisf/pull/7\n"),
    ]);

    app()->call([new ProcessAgentExecution($task), 'handle']);

    expect($task->refresh()->pull_request_url)->toBe('https://github.com/example/aisf/pull/7');
});

test('a failing CI check starts a fresh Foreman recovery turn instead of opening a pull request', function () {
    [, , $task] = feature09TaskFixture();

    app(TaskWorktreeManager::class)->ensureWorktree($task);
    $task->refresh();
    $worktreePath = (string) $task->worktree_path;

    File::put($worktreePath.'/README.md', "# MiseLedger\n");
    Process::path($worktreePath)->run(['git', 'add', 'README.md'])->throw();
    Process::path($worktreePath)->run([
        'git', '-c', 'user.name=AISF Tests', '-c', 'user.email=aisf-tests@example.test',
        'commit', '-m', 'docs: add readme',
    ])->throw();
    $commitSha = trim(Process::path($worktreePath)->run(['git', 'rev-parse', 'HEAD'])->output());

    feature09FakeHarness(feature09Completion([
        'status' => 'completed',
        'summary' => 'Added and committed the README.',
        'commit_sha' => $commitSha,
    ]));

    feature09FakeRemoteGit([
        'composer ci:check' => fn () => Process::result(exitCode: 1, errorOutput: 'Pint found 3 style violations.'),
    ]);

    app()->call([new ProcessAgentExecution($task), 'handle']);

    $task->refresh();

    expect($task->status)->toBe('waiting')
        ->and($task->last_handoff['to_role'])->toBe('foreman')
        ->and($task->last_handoff['note'])->toContain('Pint found 3 style violations.')
        ->and($task->commit_sha)->toBeNull()
        ->and($task->pull_request_url)->toBeNull();
});

test('a specialist hand-off routes the next execution to the assigned reviewer', function () {
    [, , $task] = feature09TaskFixture();
    feature09FakeHarness(feature09Completion([
        'status' => 'waiting',
        'summary' => 'Implementation complete, ready for review.',
        'handoff' => ['to_role' => 'independent_reviewer', 'note' => 'Please review the changes.'],
    ]));

    app()->call([new ProcessAgentExecution($task), 'handle']);

    $task->refresh();

    expect($task->status)->toBe('waiting')
        ->and($task->last_handoff['to_role'])->toBe('independent_reviewer');

    $qaHarness = feature09FakeHarness(feature09Completion([
        'status' => 'completed',
        'summary' => 'Looks good.',
    ]));

    app()->call([new ProcessAgentExecution($task), 'handle']);

    expect($task->refresh()->status)->toBe('completed')
        ->and($qaHarness->prompt)->toContain('Please review the changes.');

    $qaSession = $task->agentSessions()
        ->whereHas('projectAgent', fn ($query) => $query->where('role', 'independent_reviewer'))
        ->sole();
    expect($qaSession)->not->toBeNull();
});

test('a review changes-requested loop returns to an implementation specialist and completes after a fix', function () {
    [, , $task] = feature09TaskFixture();

    feature09FakeHarness(feature09Completion([
        'status' => 'waiting',
        'summary' => 'Ready for review.',
        'handoff' => ['to_role' => 'independent_reviewer'],
    ]));
    app()->call([new ProcessAgentExecution($task), 'handle']);

    feature09FakeHarness(feature09Completion([
        'status' => 'waiting',
        'summary' => 'Found an issue.',
        'handoff' => ['to_role' => 'implementation_specialist', 'note' => 'The heading is missing.'],
    ]));
    app()->call([new ProcessAgentExecution($task), 'handle']);

    expect($task->refresh()->status)->toBe('waiting')
        ->and($task->last_handoff['to_role'])->toBe('implementation_specialist');

    $coderHarness = feature09FakeHarness(feature09Completion([
        'status' => 'completed',
        'summary' => 'Fixed the heading.',
    ]));
    app()->call([new ProcessAgentExecution($task), 'handle']);

    expect($task->refresh()->status)->toBe('completed')
        ->and($coderHarness->prompt)->toContain('The heading is missing.');
});

test('an Agent execution failure retries then marks the Task failed with an operator-readable reason', function () {
    [, , $task] = feature09TaskFixture();
    $exception = new RuntimeException('Harness temporarily unavailable.');
    feature09FakeHarness($exception);
    $job = new ProcessAgentExecution($task);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
    expect($task->refresh()->status)->toBe('running');

    $job->failed($exception);

    expect($task->refresh()->status)->toBe('failed')
        ->and($task->blocked_reason)->toBe('Harness temporarily unavailable.');
});

test('an operator Retry re-enters a failed Task as pending', function () {
    [$project, , $task] = feature09TaskFixture();
    $task->update(['status' => 'failed', 'blocked_reason' => 'Implementation failed.', 'last_handoff' => ['to_role' => 'implementation_specialist']]);

    $response = $this->post(route('projects.tasks.retry', [$project, $task]));

    $response->assertRedirect(route('projects.show', $project));

    expect($task->refresh()->status)->toBe('pending')
        ->and($task->blocked_reason)->toBeNull()
        ->and($task->last_handoff)->toBeNull();
});

test('an operator Retry re-enters a failed WorkRequest as pending', function () {
    Queue::fake();
    [$project, $workRequest] = feature09Fixture();
    $workRequest->update(['status' => 'failed', 'failure_reason' => 'Foreman execution failed.']);

    $response = $this->post(route('projects.work-requests.retry', [$project, $workRequest]));

    $response->assertRedirect(route('projects.show', $project));

    expect($workRequest->refresh()->status)->toBe('pending')
        ->and($workRequest->failure_reason)->toBeNull();
});

test('Run now only dispatches for a pending or waiting Task', function () {
    Queue::fake();
    [$project, , $task] = feature09TaskFixture();
    $task->update(['status' => 'running']);

    $this->post(route('projects.tasks.run', [$project, $task]));
    Queue::assertNotPushed(ProcessAgentExecution::class);

    $task->update(['status' => 'pending']);
    $this->post(route('projects.tasks.run', [$project, $task]));
    Queue::assertPushed(ProcessAgentExecution::class, fn (ProcessAgentExecution $job) => $job->subject->is($task));
});

/**
 * Fake only the given command prefixes (e.g. 'git push', 'gh pr create') while letting every other
 * Process call — the real git verification commands TaskWorktreeManager still needs — run for real.
 *
 * @param  array<string, (callable(): ProcessResult)>  $fakedPrefixes
 */
function feature09FakeRemoteGit(array $fakedPrefixes): void
{
    Process::fake(function ($process) use ($fakedPrefixes) {
        $line = implode(' ', (array) $process->command);

        foreach ($fakedPrefixes as $prefix => $result) {
            if (str_starts_with($line, $prefix)) {
                return $result();
            }
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
 * @return array{0: Project, 1: WorkRequest}
 */
function feature09Fixture(): array
{
    $repositoryPath = feature09TemporaryGitRepository();
    $project = Project::factory()->create([
        'title' => 'AISF Feature 09 Test Project',
        'path' => $repositoryPath,
    ]);
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $workRequest = $project->workRequests()->create([
        'prompt' => 'Add a root README.',
    ]);

    return [$project, $workRequest];
}

/**
 * @return array{0: Project, 1: WorkRequest, 2: Task}
 */
function feature09TaskFixture(): array
{
    [$project, $workRequest] = feature09Fixture();
    $task = $workRequest->tasks()->create([
        'position' => 1,
        'title' => 'Add README.md',
        'objective' => 'Document the project.',
        'implementation_spec' => '',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
    ]);

    return [$project, $workRequest, $task];
}

function feature09FakeHarness(string|Throwable $result, ?callable $sideEffect = null): Feature09FakeAgentHarness
{
    $harness = new Feature09FakeAgentHarness($result, $sideEffect);
    app()->instance(AgentHarness::class, $harness);

    return $harness;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function feature09PmCompletion(array $overrides): string
{
    return json_encode(array_merge([
        'status' => 'completed',
        'summary' => 'Planned.',
    ], $overrides), JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function feature09Completion(array $overrides): string
{
    return json_encode(array_merge([
        'status' => 'completed',
        'summary' => 'Done.',
    ], $overrides), JSON_THROW_ON_ERROR);
}

function feature09TemporaryGitRepository(): string
{
    $path = sys_get_temp_dir().'/aisf-feature09-'.Str::uuid();
    File::makeDirectory($path);

    Process::path($path)->run(['git', 'init', '--initial-branch=main'])->throw();
    File::put($path.'/.gitkeep', '');
    Process::path($path)->run(['git', 'add', '.gitkeep'])->throw();
    Process::path($path)->run([
        'git', '-c', 'user.name=AISF Tests', '-c', 'user.email=aisf-tests@example.test',
        'commit', '-m', 'chore: initialize fixture',
    ])->throw();

    return $path;
}
