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

test('a trivial documentation Task completes directly from the Coder with no QA handoff and no commit', function () {
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

test('a Coder completion with a reported commit SHA is verified and integrated into the Project branch', function () {
    [$project, , $task] = feature09TaskFixture();

    app(TaskWorktreeManager::class)->ensureWorktree($task);
    $task->refresh();
    $worktreePath = (string) $task->worktree_path;
    $head = trim(Process::path($worktreePath)->run(['git', 'rev-parse', 'HEAD'])->output());

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

    app()->call([new ProcessAgentExecution($task), 'handle']);

    $task->refresh();

    expect($task->status)->toBe('completed')
        ->and($task->commit_sha)->toBe($commitSha)
        ->and($task->integrated_sha)->toBe($commitSha);

    $projectHead = trim(Process::path($project->path)->run(['git', 'rev-parse', 'HEAD'])->output());
    expect($projectHead)->toBe($commitSha)
        ->and($head)->not->toBe($commitSha);
});

test('a Coder hand-off to QA moves the Task to waiting and the next execution invokes the QA Agent', function () {
    [, , $task] = feature09TaskFixture();
    feature09FakeHarness(feature09Completion([
        'status' => 'waiting',
        'summary' => 'Implementation complete, ready for review.',
        'handoff' => ['to_role' => 'quality_assurance_specialist', 'note' => 'Please review the changes.'],
    ]));

    app()->call([new ProcessAgentExecution($task), 'handle']);

    $task->refresh();

    expect($task->status)->toBe('waiting')
        ->and($task->last_handoff['to_role'])->toBe('quality_assurance_specialist');

    $qaHarness = feature09FakeHarness(feature09Completion([
        'status' => 'completed',
        'summary' => 'Looks good.',
    ]));

    app()->call([new ProcessAgentExecution($task), 'handle']);

    expect($task->refresh()->status)->toBe('completed')
        ->and($qaHarness->prompt)->toContain('Please review the changes.');

    $qaSession = $task->agentSessions()
        ->whereHas('projectAgent', fn ($query) => $query->where('role', 'quality_assurance_specialist'))
        ->sole();
    expect($qaSession)->not->toBeNull();
});

test('a QA changes-requested loop hands back to the Coder and completes after a fix', function () {
    [, , $task] = feature09TaskFixture();

    feature09FakeHarness(feature09Completion([
        'status' => 'waiting',
        'summary' => 'Ready for review.',
        'handoff' => ['to_role' => 'quality_assurance_specialist'],
    ]));
    app()->call([new ProcessAgentExecution($task), 'handle']);

    feature09FakeHarness(feature09Completion([
        'status' => 'waiting',
        'summary' => 'Found an issue.',
        'handoff' => ['to_role' => 'coder', 'note' => 'The heading is missing.'],
    ]));
    app()->call([new ProcessAgentExecution($task), 'handle']);

    expect($task->refresh()->status)->toBe('waiting')
        ->and($task->last_handoff['to_role'])->toBe('coder');

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
    $task->update(['status' => 'failed', 'blocked_reason' => 'Coder implementation failed.', 'last_handoff' => ['to_role' => 'coder']]);

    $response = $this->post(route('projects.tasks.retry', [$project, $task]));

    $response->assertRedirect(route('projects.show', $project));

    expect($task->refresh()->status)->toBe('pending')
        ->and($task->blocked_reason)->toBeNull()
        ->and($task->last_handoff)->toBeNull();
});

test('an operator Retry re-enters a failed WorkRequest as pending', function () {
    Queue::fake();
    [$project, $workRequest] = feature09Fixture();
    $workRequest->update(['status' => 'failed', 'failure_reason' => 'Project Manager planning failed.']);

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
