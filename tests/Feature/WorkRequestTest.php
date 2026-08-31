<?php

use App\Jobs\ProcessWorkRequest;
use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\Task;
use App\Models\WorkRequest;
use App\Services\AgentHarness;
use App\Services\ProjectAgentProvisioner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class Feature03FakeAgentHarness extends AgentHarness
{
    public string $prompt = '';

    /** @var array<string, mixed> */
    public array $schema = [];

    public int $executions = 0;

    /**
     * Create a deterministic harness response or failure for Feature 03 tests.
     */
    public function __construct(private readonly string|Throwable $result) {}

    /**
     * Capture PM execution inputs while replacing the external Codex or Claude process.
     *
     * @param  array<string, mixed>  $schema
     */
    public function execute(ProjectAgent $agent, string $repositoryPath, string $prompt, array $schema): string
    {
        $this->executions++;
        $this->prompt = $prompt;
        $this->schema = $schema;

        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}

test('a work request is persisted as submitted and planning is queued', function () {
    Queue::fake();
    $project = Project::factory()->create();

    $response = $this->post(route('projects.work-requests.store', $project), [
        'prompt' => 'Add a project activity timeline.',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('projects.show', $project));

    $workRequest = WorkRequest::query()->sole();

    expect($workRequest->prompt)->toBe('Add a project activity timeline.')
        ->and($workRequest->status)->toBe('submitted');

    Queue::assertPushed(ProcessWorkRequest::class, fn (ProcessWorkRequest $job) => $job->workRequest->is($workRequest));
});

test('the PM prompt contains configured identity context workflow skills project facts and the original request', function () {
    [$project, $workRequest, $agent] = feature03PlanningFixture();
    $firstSkill = $project->skills()->create([
        'name' => 'Repository inspection',
        'description' => 'Inspect before deciding.',
        'instructions' => 'Read the implementation before planning.',
        'enabled' => true,
    ]);
    $secondSkill = $project->skills()->create([
        'name' => 'Browser verification',
        'description' => 'Keep increments testable.',
        'instructions' => 'Require an observable browser result.',
        'enabled' => true,
    ]);
    $disabledSkill = $project->skills()->create([
        'name' => 'Disabled skill',
        'instructions' => 'This must not be included.',
        'enabled' => false,
    ]);
    $agent->skills()->sync([
        $secondSkill->id => ['position' => 1],
        $firstSkill->id => ['position' => 2],
        $disabledSkill->id => ['position' => 3],
    ]);
    $harness = feature03FakeHarness($this, feature03OneTaskPlan());

    app()->call([new ProcessWorkRequest($workRequest), 'handle']);

    expect($harness->prompt)
        ->toContain('Feature 03 PM identity')
        ->toContain('Feature 03 default context')
        ->toContain('Feature 03 workflow')
        ->toContain('Browser verification')
        ->toContain('Repository inspection')
        ->not->toContain('Disabled skill')
        ->toContain($project->title)
        ->toContain($project->path)
        ->toContain($workRequest->prompt);

    expect(strpos($harness->prompt, 'Browser verification'))
        ->toBeLessThan(strpos($harness->prompt, 'Repository inspection'));
});

test('successful asynchronous PM planning persists one complete browser-testable task', function () {
    [, $workRequest] = feature03PlanningFixture();
    feature03FakeHarness($this, feature03OneTaskPlan());

    app()->call([new ProcessWorkRequest($workRequest), 'handle']);

    $workRequest->refresh();
    $task = $workRequest->tasks()->sole();

    expect($workRequest->status)->toBe('planned')
        ->and($workRequest->summary)->toBe('Implement the visible activity timeline in one browser-testable increment.')
        ->and($workRequest->evidence)->toBeNull()
        ->and($workRequest->failure_reason)->toBeNull()
        ->and($task->position)->toBe(1)
        ->and($task->title)->toBe('Add project activity timeline')
        ->and($task->objective)->toBe('Show recent project activity in the Project workspace.')
        ->and($task->implementation_spec)->toContain('Project workspace')
        ->and($task->acceptance_criteria)->toBe(['Recent activity is visible in the Project workspace.'])
        ->and($task->verification_commands)->toBe(['php artisan test --filter=ProjectActivity'])
        ->and($task->browser_steps)->toBe([
            'Open the Project workspace in the browser.',
            'Confirm the recent activity section is visible and shows the expected entry.',
        ])
        ->and($task->depends_on_task_id)->toBeNull();
});

test('multi-task PM planning persists returned order and resolves only earlier sequential dependencies', function () {
    [, $workRequest] = feature03PlanningFixture();
    feature03FakeHarness($this, feature03MultiTaskPlan());

    app()->call([new ProcessWorkRequest($workRequest), 'handle']);

    $tasks = $workRequest->refresh()->tasks()->get();

    expect($workRequest->status)->toBe('planned')
        ->and($tasks)->toHaveCount(2)
        ->and($tasks[0]->position)->toBe(1)
        ->and($tasks[0]->depends_on_task_id)->toBeNull()
        ->and($tasks[1]->position)->toBe(2)
        ->and($tasks[1]->depends_on_task_id)->toBe($tasks[0]->id);
});

test('already implemented planning requires existing repository evidence and creates no tasks', function () {
    [$project, $workRequest] = feature03PlanningFixture();
    File::ensureDirectoryExists($project->path.'/app/Services');
    File::put($project->path.'/app/Services/ExistingFeature.php', '<?php // existing feature');
    $reason = 'The requested behavior already exists in app/Services/ExistingFeature.php and is used by the current Project workflow.';

    feature03FakeHarness($this, feature03Plan([
        'summary' => 'The repository already contains the requested behavior.',
        'already_implemented' => true,
        'already_implemented_reason' => $reason,
        'tasks' => [],
    ]));

    app()->call([new ProcessWorkRequest($workRequest), 'handle']);

    $workRequest->refresh();

    expect($workRequest->status)->toBe('completed')
        ->and($workRequest->summary)->toBe('The repository already contains the requested behavior.')
        ->and($workRequest->evidence)->toBe([$reason])
        ->and($workRequest->tasks()->count())->toBe(0);
});

test('already implemented planning is rejected when cited repository evidence does not exist', function () {
    [, $workRequest] = feature03PlanningFixture();

    feature03FakeHarness($this, feature03Plan([
        'summary' => 'Already present.',
        'already_implemented' => true,
        'already_implemented_reason' => 'The implementation is in app/Services/MissingFeature.php.',
        'tasks' => [],
    ]));

    app()->call([new ProcessWorkRequest($workRequest), 'handle']);

    expect($workRequest->refresh()->status)->toBe('failed')
        ->and($workRequest->tasks()->count())->toBe(0)
        ->and($workRequest->failure_reason)->toContain('concrete evidence');
});

test('malformed PM output fails without persisting tasks', function () {
    [, $workRequest] = feature03PlanningFixture();
    feature03FakeHarness($this, '{not-json');

    app()->call([new ProcessWorkRequest($workRequest), 'handle']);

    expect($workRequest->refresh()->status)->toBe('failed')
        ->and($workRequest->failure_reason)->toContain('malformed JSON')
        ->and($workRequest->tasks()->count())->toBe(0);
});

test('contradictory already implemented output is rejected before persistence', function () {
    [, $workRequest] = feature03PlanningFixture();
    $task = feature03TaskPlan();

    feature03FakeHarness($this, feature03Plan([
        'summary' => 'Contradictory result.',
        'already_implemented' => true,
        'already_implemented_reason' => 'Existing implementation is in app/Services/Anything.php.',
        'tasks' => [$task],
    ]));

    app()->call([new ProcessWorkRequest($workRequest), 'handle']);

    expect($workRequest->refresh()->status)->toBe('failed')
        ->and($workRequest->failure_reason)->toContain('cannot contain Tasks')
        ->and($workRequest->tasks()->count())->toBe(0);
});

test('a PM task without a concrete browser-testable outcome is rejected', function () {
    [, $workRequest] = feature03PlanningFixture();
    $task = feature03TaskPlan();
    $task['browser_test_steps'] = ['Run php artisan test --filter=ProjectActivity.'];

    feature03FakeHarness($this, feature03Plan([
        'summary' => 'Invalid non-browser plan.',
        'already_implemented' => false,
        'already_implemented_reason' => null,
        'tasks' => [$task],
    ]));

    app()->call([new ProcessWorkRequest($workRequest), 'handle']);

    expect($workRequest->refresh()->status)->toBe('failed')
        ->and($workRequest->failure_reason)->toContain('browser-testable outcome')
        ->and($workRequest->tasks()->count())->toBe(0);
});

test('a task cannot depend on its own or a later position', function () {
    [, $workRequest] = feature03PlanningFixture();
    $task = feature03TaskPlan();
    $task['depends_on_position'] = 1;

    feature03FakeHarness($this, feature03Plan([
        'summary' => 'Invalid dependency plan.',
        'already_implemented' => false,
        'already_implemented_reason' => null,
        'tasks' => [$task],
    ]));

    app()->call([new ProcessWorkRequest($workRequest), 'handle']);

    expect($workRequest->refresh()->status)->toBe('failed')
        ->and($workRequest->failure_reason)->toContain('earlier Task position')
        ->and($workRequest->tasks()->count())->toBe(0);
});

test('worker failure handling records a failure without overwriting a completed result', function () {
    [, $workRequest] = feature03PlanningFixture();
    $exception = new RuntimeException('Harness temporarily unavailable.');
    feature03FakeHarness($this, $exception);
    $job = new ProcessWorkRequest($workRequest);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);

    expect($workRequest->refresh()->status)->toBe('processing');

    $job->failed($exception);

    expect($workRequest->refresh()->status)->toBe('failed')
        ->and($workRequest->failure_reason)->toBe('Harness temporarily unavailable.');

    $workRequest->update(['status' => 'completed', 'failure_reason' => null]);
    $job->failed(new RuntimeException('Late duplicate failure.'));

    expect($workRequest->refresh()->status)->toBe('completed')
        ->and($workRequest->failure_reason)->toBeNull();
});

test('task persistence is atomic when a later task insert fails', function () {
    [, $workRequest] = feature03PlanningFixture();
    feature03FakeHarness($this, feature03MultiTaskPlan());
    $creating = 0;

    Task::creating(function () use (&$creating): void {
        $creating++;

        if ($creating === 2) {
            throw new RuntimeException('Simulated task persistence failure.');
        }
    });

    $job = new ProcessWorkRequest($workRequest);

    try {
        expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);

        expect($workRequest->refresh()->status)->toBe('processing')
            ->and($workRequest->tasks()->count())->toBe(0);

        $job->failed(new RuntimeException('Simulated task persistence failure.'));

        expect($workRequest->refresh()->status)->toBe('failed');
    } finally {
        Task::flushEventListeners();
    }
});

test('re-running a completed planning job does not create duplicate tasks', function () {
    [, $workRequest] = feature03PlanningFixture();
    $harness = feature03FakeHarness($this, feature03OneTaskPlan());
    $job = new ProcessWorkRequest($workRequest);

    app()->call([$job, 'handle']);
    $firstTaskId = $workRequest->refresh()->tasks()->sole()->id;

    app()->call([$job, 'handle']);

    expect($workRequest->refresh()->status)->toBe('planned')
        ->and($workRequest->tasks()->count())->toBe(1)
        ->and($workRequest->tasks()->sole()->id)->toBe($firstTaskId)
        ->and($harness->executions)->toBe(1);
});

test('the project workspace exposes PM evidence failures and complete ordered task details', function () {
    [$project, $workRequest] = feature03PlanningFixture();
    $firstTask = $workRequest->tasks()->create([
        'position' => 1,
        'title' => 'Visible first task',
        'objective' => 'Expose the first browser outcome.',
        'implementation_spec' => 'Implement the first visible workspace state.',
        'acceptance_criteria' => ['The state is visible.'],
        'verification_commands' => ['php artisan test --filter=VisibleFirstTask'],
        'browser_steps' => ['Open the Project workspace.', 'Confirm the first state is visible.'],
    ]);
    $workRequest->tasks()->create([
        'depends_on_task_id' => $firstTask->id,
        'position' => 2,
        'title' => 'Visible second task',
        'objective' => 'Expose the dependent browser outcome.',
        'implementation_spec' => 'Implement the second visible workspace state.',
        'acceptance_criteria' => ['The dependent state is visible.'],
        'verification_commands' => ['php artisan test --filter=VisibleSecondTask'],
        'browser_steps' => ['Refresh the Project workspace.', 'Verify the dependent state appears.'],
    ]);
    $workRequest->update([
        'status' => 'planned',
        'summary' => 'Two visible increments are planned.',
    ]);

    $response = $this->get(route('projects.show', $project));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('projects/show')
        ->where('workRequests.0.status', 'planned')
        ->where('workRequests.0.summary', 'Two visible increments are planned.')
        ->where('workRequests.0.tasks.0.implementation_spec', 'Implement the first visible workspace state.')
        ->where('workRequests.0.tasks.0.acceptance_criteria.0', 'The state is visible.')
        ->where('workRequests.0.tasks.0.verification_commands.0', 'php artisan test --filter=VisibleFirstTask')
        ->where('workRequests.0.tasks.0.browser_steps.0', 'Open the Project workspace.')
        ->where('workRequests.0.tasks.1.depends_on_task_id', $firstTask->id));
});

/**
 * Create an inspectable Project, enabled PM Agent, and submitted WorkRequest for Feature 03 tests.
 *
 * @return array{0: Project, 1: WorkRequest, 2: ProjectAgent}
 */
function feature03PlanningFixture(): array
{
    $repositoryPath = feature03TemporaryGitRepository();
    $project = Project::factory()->create([
        'title' => 'AISF Feature 03 Test Project',
        'description' => 'Feature 03 planning fixture.',
        'path' => $repositoryPath,
    ]);
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $agent = $project->agents()->where('role', 'project_manager')->sole();
    $agent->update([
        'identity' => 'Feature 03 PM identity',
        'default_context' => 'Feature 03 default context',
        'workflow_instructions' => 'Feature 03 workflow',
        'enabled' => true,
    ]);
    $workRequest = $project->workRequests()->create([
        'prompt' => 'Add a project activity timeline.',
    ]);

    return [$project, $workRequest, $agent];
}

/**
 * Bind a deterministic fake Agent harness for one test and return it for assertions.
 */
function feature03FakeHarness(object $testCase, string|Throwable $result): Feature03FakeAgentHarness
{
    $harness = new Feature03FakeAgentHarness($result);
    $testCase->app->instance(AgentHarness::class, $harness);

    return $harness;
}

/**
 * Encode one valid one-task PM plan using the exact Feature 03 response contract.
 */
function feature03OneTaskPlan(): string
{
    return feature03Plan([
        'summary' => 'Implement the visible activity timeline in one browser-testable increment.',
        'already_implemented' => false,
        'already_implemented_reason' => null,
        'tasks' => [feature03TaskPlan()],
    ]);
}

/**
 * Encode a valid two-task PM plan with a simple dependency on the earlier position.
 */
function feature03MultiTaskPlan(): string
{
    $secondTask = feature03TaskPlan();
    $secondTask['title'] = 'Add timeline filtering';
    $secondTask['objective'] = 'Let the operator filter the visible activity timeline.';
    $secondTask['implementation_spec'] = 'Add a bounded timeline filter to the existing Project workspace activity UI.';
    $secondTask['acceptance_criteria'] = ['Filtering changes the visible activity rows.'];
    $secondTask['verification_commands'] = ['php artisan test --filter=ProjectActivityFilter'];
    $secondTask['browser_test_steps'] = [
        'Open the Project workspace and select an activity filter.',
        'Confirm the visible activity list shows only matching entries.',
    ];
    $secondTask['depends_on_position'] = 1;

    return feature03Plan([
        'summary' => 'Deliver the activity timeline in two ordered browser-testable increments.',
        'already_implemented' => false,
        'already_implemented_reason' => null,
        'tasks' => [feature03TaskPlan(), $secondTask],
    ]);
}

/**
 * Return one valid browser-testable Task payload.
 *
 * @return array<string, mixed>
 */
function feature03TaskPlan(): array
{
    return [
        'title' => 'Add project activity timeline',
        'objective' => 'Show recent project activity in the Project workspace.',
        'implementation_spec' => 'Add the smallest Project workspace timeline backed by existing project data and components.',
        'acceptance_criteria' => ['Recent activity is visible in the Project workspace.'],
        'verification_commands' => ['php artisan test --filter=ProjectActivity'],
        'browser_test_steps' => [
            'Open the Project workspace in the browser.',
            'Confirm the recent activity section is visible and shows the expected entry.',
        ],
        'depends_on_position' => null,
    ];
}

/**
 * Encode arbitrary PM plan data as strict JSON for the fake harness.
 *
 * @param  array<string, mixed>  $plan
 */
function feature03Plan(array $plan): string
{
    return json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/**
 * Create a disposable Git working tree for repository-inspection tests.
 */
function feature03TemporaryGitRepository(): string
{
    $path = sys_get_temp_dir().'/aisf-feature03-'.Str::uuid();
    File::makeDirectory($path);

    Process::path($path)->run(['git', 'init', '--initial-branch=main'])->throw();
    File::put($path.'/README.md', '# Feature 03 test repository');
    Process::path($path)->run(['git', 'add', 'README.md'])->throw();
    Process::path($path)->run([
        'git',
        '-c', 'user.name=AISF Tests',
        '-c', 'user.email=aisf-tests@example.test',
        'commit', '-m', 'Initial commit',
    ])->throw();

    return $path;
}
