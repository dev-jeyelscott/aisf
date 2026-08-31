<?php

use App\Jobs\ProcessTaskCoding;
use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\Task;
use App\Models\WorkRequest;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResult;
use App\Services\ProjectAgentProvisioner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class Feature05FakeAgentHarness extends AgentHarness
{
    public string $prompt = '';

    public string $repositoryPath = '';

    public bool $writable = false;

    public int $executions = 0;

    /**
     * Create a deterministic fake Coder harness with an optional worktree side effect.
     *
     * @param  (callable(string $worktreePath): void)|null  $sideEffect
     */
    public function __construct(
        private readonly string|Throwable $result,
        private $sideEffect = null,
    ) {}

    /**
     * Keep Feature 05 tests on deterministic fresh context rather than provider continuation.
     */
    public function canResume(ProjectAgent $agent): bool
    {
        return false;
    }

    /**
     * Capture Coder execution inputs, optionally mutate the worktree, and replace the external process.
     *
     * @param  array<string, mixed>|null  $schema
     */
    public function start(
        ProjectAgent $agent,
        string $repositoryPath,
        string $prompt,
        ?array $schema = null,
        bool $writable = false,
    ): AgentHarnessResult {
        $this->executions++;
        $this->prompt = $prompt;
        $this->repositoryPath = $repositoryPath;
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

    /**
     * Reuse the deterministic fake response if a test explicitly exercises resume.
     *
     * @param  array<string, mixed>|null  $schema
     */
    public function resume(
        ProjectAgent $agent,
        string $repositoryPath,
        string $providerSessionId,
        string $prompt,
        ?array $schema = null,
        bool $writable = false,
    ): AgentHarnessResult {
        return $this->start(
            $agent,
            $repositoryPath,
            $prompt,
            $schema,
            $writable,
        );
    }
}

test('starting a queued Task creates an isolated branch and worktree without changing the Project working tree', function () {
    [$project, , $task] = feature05TaskFixture();
    feature05FakeHarness(feature05Completion());

    app()->call([new ProcessTaskCoding($task), 'handle']);

    $task->refresh();
    $projectStatus = feature05GitStatus($project->path);

    expect($task->status)->toBe('ready_for_qa')
        ->and($task->base_branch)->toBe('main')
        ->and($task->base_sha)->toBe($projectStatus['head'])
        ->and($task->branch_name)->toBe("aisf/task-{$task->id}")
        ->and($task->worktree_path)->not->toBeNull()
        ->and(is_dir($task->worktree_path))->toBeTrue()
        ->and($projectStatus['branch'])->toBe('main')
        ->and($projectStatus['clean'])->toBeTrue();

    $worktreeIsGitDir = Process::path($task->worktree_path)
        ->run(['git', 'rev-parse', '--is-inside-work-tree'])
        ->output();

    expect(trim($worktreeIsGitDir))->toBe('true');
});

test('a queued Task starts coding, runs the Coder with writable worktree access under a no-commit contract, and reaches ready for QA', function () {
    [$project, , $task, $coder] = feature05TaskFixture();
    $harness = feature05FakeHarness(feature05Completion([
        'summary' => 'Implemented the requested change.',
        'verification_performed' => ['php artisan test --filter=Example'],
    ]), function (string $worktreePath): void {
        File::put($worktreePath.'/NEW_FILE.php', '<?php // implemented change');
    });

    app()->call([new ProcessTaskCoding($task), 'handle']);

    $task->refresh();
    $session = $task->agentSessions()->sole();
    $run = $session->runs()->sole();

    expect($task->status)->toBe('ready_for_qa')
        ->and($session->projectAgent->id)->toBe($coder->id)
        ->and($run->purpose)->toBe('coder_implementation')
        ->and($run->status)->toBe('succeeded')
        ->and($run->output_summary)->toBe('Implemented the requested change.')
        ->and($harness->writable)->toBeTrue()
        ->and($harness->repositoryPath)->toBe($task->worktree_path)
        ->and($harness->prompt)->toContain('Do not commit')
        ->and($harness->prompt)->toContain($task->implementation_spec);

    $response = $this->get(route('projects.show', $project));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('projects/show')
        ->where('workRequests.0.tasks.0.status', 'ready_for_qa')
        ->where('workRequests.0.tasks.0.branch_name', $task->branch_name)
        ->where('workRequests.0.tasks.0.base_sha', $task->base_sha)
        ->where('workRequests.0.tasks.0.worktree_path', $task->worktree_path)
        ->where('workRequests.0.tasks.0.changed_files', ['NEW_FILE.php'])
        ->where('workRequests.0.tasks.0.agent_sessions.0.runs.0.output_summary', 'Implemented the requested change.'));
});

test('an accidental pre-QA commit blocks the Task with an explicit Git boundary error', function () {
    [, , $task] = feature05TaskFixture();
    feature05FakeHarness(feature05Completion(), function (string $worktreePath): void {
        File::put($worktreePath.'/NEW_FILE.php', '<?php // implemented change');
        Process::path($worktreePath)->run(['git', 'add', 'NEW_FILE.php'])->throw();
        Process::path($worktreePath)->run([
            'git', '-c', 'user.name=AISF Tests', '-c', 'user.email=aisf-tests@example.test',
            'commit', '-m', 'Accidental pre-QA commit',
        ])->throw();
    });

    app()->call([new ProcessTaskCoding($task), 'handle']);

    $task->refresh();

    expect($task->status)->toBe('blocked')
        ->and($task->blocked_reason)->toContain('must not commit');

    $headAfterViolation = trim(
        Process::path($task->worktree_path)->run(['git', 'rev-parse', 'HEAD'])->output(),
    );

    expect($headAfterViolation)->not->toBe($task->base_sha);
});

test('malformed Coder output blocks the Task without reaching ready for QA', function () {
    [, , $task] = feature05TaskFixture();
    feature05FakeHarness('{not-json');

    app()->call([new ProcessTaskCoding($task), 'handle']);

    expect($task->refresh()->status)->toBe('blocked')
        ->and($task->blocked_reason)->toContain('malformed JSON');
});

test('starting a Task only dispatches Coder processing when the Task is queued', function () {
    Queue::fake();
    [$project, , $task] = feature05TaskFixture();

    $this->post(route('projects.tasks.start', [$project, $task]))
        ->assertRedirect(route('projects.show', $project));

    Queue::assertPushed(ProcessTaskCoding::class, fn (ProcessTaskCoding $job) => $job->task->is($task));

    $task->update(['status' => 'ready_for_qa']);

    $this->post(route('projects.tasks.start', [$project, $task]))
        ->assertRedirect(route('projects.show', $project));

    Queue::assertPushed(ProcessTaskCoding::class, 1);
});

/**
 * Create an inspectable Project with a real Git repository, an enabled Coder Agent, and one queued Task.
 *
 * @return array{0: Project, 1: WorkRequest, 2: Task, 3: ProjectAgent}
 */
function feature05TaskFixture(): array
{
    $repositoryPath = feature05TemporaryGitRepository();
    $project = Project::factory()->create([
        'title' => 'AISF Feature 05 Test Project',
        'description' => 'Feature 05 worktree and Coder execution fixture.',
        'path' => $repositoryPath,
    ]);
    app(ProjectAgentProvisioner::class)->ensureFor($project);

    $coder = $project->agents()->where('role', 'coder')->sole();
    $coder->update([
        'identity' => 'Feature 05 Coder identity',
        'default_context' => 'Feature 05 Coder default context',
        'workflow_instructions' => 'Feature 05 Coder workflow',
        'enabled' => true,
    ]);

    $workRequest = $project->workRequests()->create([
        'prompt' => 'Implement the requested change.',
        'status' => 'planned',
        'summary' => 'One browser-testable increment.',
    ]);

    $task = $workRequest->tasks()->create([
        'position' => 1,
        'title' => 'Implement the requested change',
        'objective' => 'Deliver the browser-testable increment.',
        'implementation_spec' => 'Implement the smallest correct change satisfying the WorkRequest.',
        'acceptance_criteria' => ['The change is visible in the browser.'],
        'verification_commands' => ['php artisan test --filter=Example'],
        'browser_steps' => [
            'Open the Project workspace in the browser.',
            'Confirm the implemented change is visible.',
        ],
    ]);

    return [$project, $workRequest, $task, $coder];
}

/**
 * Bind a deterministic fake Coder harness through Laravel's container and return it for assertions.
 *
 * @param  (callable(string $worktreePath): void)|null  $sideEffect
 */
function feature05FakeHarness(string|Throwable $result, ?callable $sideEffect = null): Feature05FakeAgentHarness
{
    $harness = new Feature05FakeAgentHarness($result, $sideEffect);
    app()->instance(AgentHarness::class, $harness);

    return $harness;
}

/**
 * Encode one valid Coder completion payload using the exact Feature 05 response contract.
 *
 * @param  array<string, mixed>  $overrides
 */
function feature05Completion(array $overrides = []): string
{
    $completion = array_merge([
        'summary' => 'Implemented the requested change.',
        'verification_performed' => ['php artisan test --filter=Example'],
    ], $overrides);

    return json_encode($completion, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/**
 * Create a disposable Git working tree for Feature 05 worktree lifecycle tests.
 */
function feature05TemporaryGitRepository(): string
{
    $path = sys_get_temp_dir().'/aisf-feature05-'.Str::uuid();
    File::makeDirectory($path);

    Process::path($path)->run(['git', 'init', '--initial-branch=main'])->throw();
    File::put($path.'/README.md', '# Feature 05 test repository');
    Process::path($path)->run(['git', 'add', 'README.md'])->throw();
    Process::path($path)->run([
        'git',
        '-c',
        'user.name=AISF Tests',
        '-c',
        'user.email=aisf-tests@example.test',
        'commit',
        '-m',
        'Initial commit',
    ])->throw();

    return $path;
}

/**
 * Inspect the live branch, HEAD SHA, and cleanliness of a Git working tree.
 *
 * @return array{branch: string, head: string, clean: bool}
 */
function feature05GitStatus(string $path): array
{
    $branch = trim(Process::path($path)->run(['git', 'symbolic-ref', '--quiet', '--short', 'HEAD'])->output());
    $head = trim(Process::path($path)->run(['git', 'rev-parse', 'HEAD'])->output());
    $workingTree = trim(Process::path($path)->run(['git', '--no-optional-locks', 'status', '--porcelain=v1'])->output());

    return [
        'branch' => $branch,
        'head' => $head,
        'clean' => $workingTree === '',
    ];
}
