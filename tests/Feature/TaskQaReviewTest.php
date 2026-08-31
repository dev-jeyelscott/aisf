<?php

use App\Jobs\ProcessTaskCoding;
use App\Jobs\ProcessTaskQaReview;
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

class Feature06FakeAgentHarness extends AgentHarness
{
    /** @var list<string> */
    public array $prompts = [];

    /** @var list<bool> */
    public array $writableFlags = [];

    /** @var list<string> */
    public array $modes = [];

    private int $callIndex = 0;

    /**
     * Create a deterministic fake harness that answers a queued sequence of Coder and QA calls.
     *
     * @param  list<string|Throwable>  $responses
     * @param  array<int, callable(string $worktreePath): void>  $sideEffects
     */
    public function __construct(
        private readonly array $responses,
        private readonly array $sideEffects = [],
    ) {}

    public function canResume(ProjectAgent $agent): bool
    {
        return true;
    }

    public function start(
        ProjectAgent $agent,
        string $repositoryPath,
        string $prompt,
        ?array $schema = null,
        bool $writable = false,
    ): AgentHarnessResult {
        return $this->respond('start', $repositoryPath, $prompt, $writable);
    }

    public function resume(
        ProjectAgent $agent,
        string $repositoryPath,
        string $providerSessionId,
        string $prompt,
        ?array $schema = null,
        bool $writable = false,
    ): AgentHarnessResult {
        return $this->respond('resume', $repositoryPath, $prompt, $writable);
    }

    private function respond(string $mode, string $repositoryPath, string $prompt, bool $writable): AgentHarnessResult
    {
        $index = $this->callIndex++;
        $this->prompts[] = $prompt;
        $this->writableFlags[] = $writable;
        $this->modes[] = $mode;

        if (isset($this->sideEffects[$index])) {
            ($this->sideEffects[$index])($repositoryPath);
        }

        if (! array_key_exists($index, $this->responses)) {
            throw new RuntimeException("No fake Feature 06 harness response queued for call {$index}.");
        }

        $response = $this->responses[$index];

        if ($response instanceof Throwable) {
            throw $response;
        }

        return new AgentHarnessResult(
            successful: true,
            output: $response,
            providerSessionId: 'feature06-provider-session',
            exitCode: 0,
        );
    }
}

test('a ready-for-QA Task moves through QA review to approved with visible verification and finding evidence', function () {
    [$project, , $task] = feature06ReadyForQaFixture();

    $harness = feature06FakeHarness([
        feature06QaCompletion($task, status: 'approved'),
    ]);

    app()->call([new ProcessTaskQaReview($task), 'handle']);

    $task->refresh();
    $qaSession = $task->agentSessions()->whereHas('projectAgent', fn ($q) => $q->where('role', 'quality_assurance_specialist'))->sole();
    $run = $qaSession->runs()->sole();
    $review = $task->qaReviews()->sole();

    expect($task->status)->toBe('approved')
        ->and($task->approved_at)->not->toBeNull()
        ->and($run->purpose)->toBe('qa_review')
        ->and($run->context_mode)->toBe('initial')
        ->and($harness->writableFlags[0])->toBeFalse()
        ->and($review->status)->toBe('approved')
        ->and($review->verification_results)->toHaveCount(1)
        ->and($review->findings)->toBe([]);

    $response = $this->get(route('projects.show', $project));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('projects/show')
        ->where('workRequests.0.tasks.0.status', 'approved')
        ->where('workRequests.0.tasks.0.qa_reviews.0.status', 'approved')
        ->where('workRequests.0.tasks.0.qa_reviews.0.summary', $review->summary)
        ->has('workRequests.0.tasks.0.qa_reviews.0.verification_results')
        ->has('workRequests.0.tasks.0.qa_reviews.0.findings'));
});

test('QA review is only started while a Task is ready for QA', function () {
    Queue::fake();
    [$project, , $task] = feature06ReadyForQaFixture();

    $task->update(['status' => 'coding']);
    $this->post(route('projects.tasks.qa-reviews.store', [$project, $task]))
        ->assertRedirect(route('projects.show', $project));
    Queue::assertNotPushed(ProcessTaskQaReview::class);

    $task->update(['status' => 'ready_for_qa']);
    $this->post(route('projects.tasks.qa-reviews.store', [$project, $task]))
        ->assertRedirect(route('projects.show', $project));
    Queue::assertPushed(ProcessTaskQaReview::class, fn (ProcessTaskQaReview $job) => $job->task->is($task));
});

test('QA changes-required findings are persisted and block Task approval', function () {
    [, , $task] = feature06ReadyForQaFixture();

    feature06FakeHarness([
        feature06QaCompletion($task, status: 'changes_required', findings: ['The button label is missing from the page.']),
    ]);

    app()->call([new ProcessTaskQaReview($task), 'handle']);

    $task->refresh();
    $review = $task->qaReviews()->sole();

    expect($task->status)->toBe('changes_required')
        ->and($task->approved_at)->toBeNull()
        ->and($review->findings)->toBe(['The button label is missing from the page.']);
});

test('resuming the Coder after changes required continues the same logical session with only the latest QA findings, and QA re-review resumes its own session with a fix delta', function () {
    [$project, , $task] = feature06ReadyForQaFixture();

    $harness = feature06FakeHarness([
        feature06QaCompletion($task, status: 'changes_required', findings: ['The confirmation banner never appears.']),
        feature06CoderFixCompletion(),
        feature06QaCompletion($task, status: 'approved'),
    ]);

    app()->call([new ProcessTaskQaReview($task), 'handle']);
    expect($task->refresh()->status)->toBe('changes_required');

    app()->call([new ProcessTaskCoding($task, 'Also update the changelog.'), 'handle']);

    $task->refresh();
    $coderSession = $task->agentSessions()->whereHas('projectAgent', fn ($q) => $q->where('role', 'coder'))->sole();
    $fixRun = $coderSession->runs()->where('purpose', 'coder_fix')->sole();

    expect($task->status)->toBe('ready_for_qa')
        ->and($coderSession->runs()->count())->toBe(2)
        ->and($fixRun->context_mode)->toBe('delta')
        ->and($fixRun->submitted_input)->toContain('The confirmation banner never appears.')
        ->and($fixRun->submitted_input)->toContain('Also update the changelog.')
        ->and($fixRun->submitted_input)->not->toContain('AGENT IDENTITY')
        ->and($fixRun->submitted_input)->not->toContain($task->implementation_spec)
        ->and($harness->modes[1])->toBe('resume')
        ->and($harness->writableFlags[1])->toBeTrue();

    app()->call([new ProcessTaskQaReview($task), 'handle']);

    $task->refresh();
    $qaSession = $task->agentSessions()->whereHas('projectAgent', fn ($q) => $q->where('role', 'quality_assurance_specialist'))->sole();
    $rereviewRun = $qaSession->runs()->where('purpose', 'qa_rereview')->sole();

    expect($task->status)->toBe('approved')
        ->and($qaSession->runs()->count())->toBe(2)
        ->and($rereviewRun->context_mode)->toBe('delta')
        ->and($rereviewRun->submitted_input)->toContain('LATEST CODER FIX SUMMARY')
        ->and($rereviewRun->submitted_input)->toContain('The confirmation banner never appears.')
        ->and($harness->modes[2])->toBe('resume');
});

test('a Task without automated browser tooling requires an explicit operator confirmation before QA approval', function () {
    [$project, , $task] = feature06ReadyForQaFixture();

    feature06FakeHarness([
        feature06QaCompletion($task, status: 'manual_browser_check_required', browserMode: 'manual', browserPassed: null),
    ]);

    app()->call([new ProcessTaskQaReview($task), 'handle']);

    $task->refresh();
    $review = $task->qaReviews()->sole();

    expect($task->status)->toBe('manual_browser_check_required')
        ->and($task->approved_at)->toBeNull()
        ->and($review->browser_result['mode'])->toBe('manual')
        ->and($review->operator_confirmed_at)->toBeNull();

    $response = $this->get(route('projects.show', $project));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('workRequests.0.tasks.0.status', 'manual_browser_check_required')
        ->where('workRequests.0.tasks.0.browser_steps', $task->browser_steps));

    $this->post(route('projects.tasks.qa-reviews.confirm-browser-check', [$project, $task]))
        ->assertRedirect(route('projects.show', $project));

    $task->refresh();
    $review->refresh();

    expect($task->status)->toBe('approved')
        ->and($task->approved_at)->not->toBeNull()
        ->and($review->operator_confirmed_at)->not->toBeNull();
});

test('QA approves a Task without browser test steps when its other checks pass', function () {
    [, , $task] = feature06ReadyForQaFixture();
    $task->update(['browser_steps' => []]);

    feature06FakeHarness([
        feature06QaCompletion($task, status: 'approved', browserMode: 'not_required', browserPassed: null),
    ]);

    app()->call([new ProcessTaskQaReview($task), 'handle']);

    $review = $task->refresh()->qaReviews()->sole();

    expect($task->status)->toBe('approved')
        ->and($review->browser_result['mode'])->toBe('not_required')
        ->and($review->browser_result['passed'])->toBeNull();
});

test('QA cannot approve directly while acceptance criteria, verification, or the browser check remain unmet', function () {
    [, , $task] = feature06ReadyForQaFixture();

    feature06FakeHarness([
        feature06QaCompletion($task, status: 'approved', criteriaMet: false),
    ]);

    app()->call([new ProcessTaskQaReview($task), 'handle']);

    expect($task->refresh()->status)->toBe('blocked')
        ->and($task->blocked_reason)->toContain('cannot approve');
});

test('a queue retry after a transient QA harness failure re-runs the review instead of leaving the Task stuck in qa_reviewing', function () {
    [, , $task] = feature06ReadyForQaFixture();

    feature06FakeHarness([
        new RuntimeException('The process exceeded the timeout of 70 seconds.'),
    ]);

    expect(fn () => app()->call([new ProcessTaskQaReview($task), 'handle']))
        ->toThrow(RuntimeException::class);

    expect($task->refresh()->status)->toBe('qa_reviewing');

    feature06FakeHarness([
        feature06QaCompletion($task, status: 'approved'),
    ]);

    app()->call([new ProcessTaskQaReview($task), 'handle']);

    expect($task->refresh()->status)->toBe('approved')
        ->and($task->qaReviews()->sole()->status)->toBe('approved');
});

test('QA output that fails to evaluate every acceptance criterion or verification command blocks the Task', function () {
    [, , $task] = feature06ReadyForQaFixture();

    $incompleteCompletion = json_encode([
        'status' => 'approved',
        'summary' => 'Looks fine.',
        'acceptance_criteria_results' => [],
        'verification_results' => [],
        'browser_result' => ['mode' => 'automated', 'passed' => true, 'notes' => ''],
        'findings' => [],
    ], JSON_THROW_ON_ERROR);

    feature06FakeHarness([$incompleteCompletion]);

    app()->call([new ProcessTaskQaReview($task), 'handle']);

    expect($task->refresh()->status)->toBe('blocked')
        ->and($task->blocked_reason)->toContain('every');
});

/**
 * Drive a queued Task through a fake Coder run to reach ready-for-QA with a real worktree and changed file.
 *
 * @return array{0: Project, 1: WorkRequest, 2: Task}
 */
function feature06ReadyForQaFixture(): array
{
    $repositoryPath = feature06TemporaryGitRepository();
    $project = Project::factory()->create([
        'title' => 'AISF Feature 06 Test Project',
        'description' => 'Feature 06 QA review loop fixture.',
        'path' => $repositoryPath,
    ]);
    app(ProjectAgentProvisioner::class)->ensureFor($project);

    $project->agents()->where('role', 'coder')->sole()->update([
        'identity' => 'Feature 06 Coder identity',
        'default_context' => 'Feature 06 Coder default context',
        'workflow_instructions' => 'Feature 06 Coder workflow',
        'enabled' => true,
    ]);
    $project->agents()->where('role', 'quality_assurance_specialist')->sole()->update([
        'identity' => 'Feature 06 QA identity',
        'default_context' => 'Feature 06 QA default context',
        'workflow_instructions' => 'Feature 06 QA workflow',
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

    feature06FakeHarness([
        json_encode([
            'summary' => 'Implemented the requested change.',
            'verification_performed' => ['php artisan test --filter=Example'],
        ], JSON_THROW_ON_ERROR),
    ], [
        function (string $worktreePath): void {
            File::put($worktreePath.'/NEW_FILE.php', '<?php // implemented change');
        },
    ]);

    app()->call([new ProcessTaskCoding($task), 'handle']);

    return [$project, $workRequest, $task->refresh()];
}

/**
 * Bind a deterministic fake sequential Coder/QA harness through the container and return it for assertions.
 *
 * @param  list<string|Throwable>  $responses
 * @param  array<int, callable(string $worktreePath): void>  $sideEffects
 */
function feature06FakeHarness(array $responses, array $sideEffects = []): Feature06FakeAgentHarness
{
    $harness = new Feature06FakeAgentHarness($responses, $sideEffects);
    app()->instance(AgentHarness::class, $harness);

    return $harness;
}

/**
 * Encode one valid QA completion payload covering every Task acceptance criterion and verification command.
 *
 * @param  list<string>  $findings
 */
function feature06QaCompletion(
    Task $task,
    string $status,
    array $findings = [],
    bool $criteriaMet = true,
    string $browserMode = 'automated',
    ?bool $browserPassed = true,
): string {
    $criteriaResults = array_map(
        static fn (string $criterion): array => ['criterion' => $criterion, 'met' => $criteriaMet, 'note' => 'Verified in the worktree.'],
        $task->acceptance_criteria,
    );

    $verificationResults = array_map(
        static fn (string $command): array => ['command' => $command, 'passed' => true, 'notes' => 'Command output was clean.'],
        $task->verification_commands,
    );

    return json_encode([
        'status' => $status,
        'summary' => "QA review concluded with status {$status}.",
        'acceptance_criteria_results' => $criteriaResults,
        'verification_results' => $verificationResults,
        'browser_result' => ['mode' => $browserMode, 'passed' => $browserPassed, 'notes' => 'Browser result notes.'],
        'findings' => $findings,
    ], JSON_THROW_ON_ERROR);
}

/**
 * Encode one valid Coder fix-loop completion payload.
 */
function feature06CoderFixCompletion(): string
{
    return json_encode([
        'summary' => 'Fixed the confirmation banner regression.',
        'verification_performed' => ['php artisan test --filter=Example'],
    ], JSON_THROW_ON_ERROR);
}

/**
 * Create a disposable Git working tree for Feature 06 QA review loop tests.
 */
function feature06TemporaryGitRepository(): string
{
    $path = sys_get_temp_dir().'/aisf-feature06-'.Str::uuid();
    File::makeDirectory($path);

    Process::path($path)->run(['git', 'init', '--initial-branch=main'])->throw();
    File::put($path.'/README.md', '# Feature 06 test repository');
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
