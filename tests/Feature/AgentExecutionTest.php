<?php

use App\Exceptions\AgentCapabilityException;
use App\Jobs\DispatchWorkflowForProject;
use App\Jobs\ProcessAgentExecution;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\Task;
use App\Models\WorkRequest;
use App\Services\AgentCapabilityPreflight;
use App\Services\AgentExecutionRunner;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResult;
use App\Services\AgentRuntimeEnvironment;
use App\Services\AgentSessionManager;
use App\Services\AgentTurnReconciler;
use App\Services\CandidateAcceptanceGate;
use App\Services\ProjectAgentProvisioner;
use App\Services\TaskWorkflowService;
use App\Services\TaskWorktreeManager;
use App\Services\WorkflowOutcomeService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class Feature09PermissiveCapabilityPreflight extends AgentCapabilityPreflight
{
    public function verify(ProjectAgent $agent, string $repositoryPath): void
    {
        // Permissive by default: these fixtures test workflow behavior, not host capability checks.
    }
}

class Feature09FailingCapabilityPreflight extends AgentCapabilityPreflight
{
    public function __construct(private readonly string $message) {}

    public function verify(ProjectAgent $agent, string $repositoryPath): void
    {
        throw new AgentCapabilityException($this->message);
    }
}

class Feature09FakeAgentHarness extends AgentHarness
{
    public string $prompt = '';

    public bool $writable = false;

    public int $startCalls = 0;

    public int $resumeCalls = 0;

    public ?string $lastResumedProviderSessionId = null;

    /**
     * Configure deterministic provider behavior for Agent execution feature tests.
     *
     * @param  (callable(string $repositoryPath, string $prompt): void)|null  $sideEffect
     */
    public function __construct(
        private readonly string|Throwable $result,
        private $sideEffect = null,
        private readonly bool $supportsResume = false,
        private readonly ?string $resumeProviderSessionId = null,
    ) {}

    /**
     * Report whether the fake provider supports persistent session resume.
     */
    public function canResume(ProjectAgent $agent): bool
    {
        return $this->supportsResume;
    }

    /**
     * Simulate starting a fresh provider conversation and optionally return a persistent session identifier.
     */
    public function start(
        ProjectAgent $agent,
        string $repositoryPath,
        string $prompt,
        ?array $schema = null,
        bool $writable = false,
    ): AgentHarnessResult {
        $this->startCalls++;

        return $this->fakeResult(
            $repositoryPath,
            $prompt,
            $writable,
            $this->supportsResume ? 'feature09-provider-session-'.$this->startCalls : null,
        );
    }

    /**
     * Simulate resuming the requested provider conversation without creating a fresh session.
     */
    public function resume(
        ProjectAgent $agent,
        string $repositoryPath,
        string $providerSessionId,
        string $prompt,
        ?array $schema = null,
        bool $writable = false,
    ): AgentHarnessResult {
        $this->resumeCalls++;
        $this->lastResumedProviderSessionId = $providerSessionId;

        return $this->fakeResult(
            $repositoryPath,
            $prompt,
            $writable,
            $this->resumeProviderSessionId ?? $providerSessionId,
        );
    }

    /**
     * Execute the shared fake provider behavior while recording prompt and writable access.
     */
    private function fakeResult(
        string $repositoryPath,
        string $prompt,
        bool $writable,
        ?string $providerSessionId,
    ): AgentHarnessResult {
        $this->prompt = $prompt;
        $this->writable = $writable;

        if ($this->sideEffect !== null) {
            ($this->sideEffect)($repositoryPath, $prompt);
        }

        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return new AgentHarnessResult(
            successful: true,
            output: $this->result,
            providerSessionId: $providerSessionId,
            exitCode: 0,
        );
    }
}

beforeEach(function (): void {
    $this->agentExecutionVaultPath = storage_path(
        'framework/testing/vaults/'.Str::uuid(),
    );

    File::ensureDirectoryExists(
        $this->agentExecutionVaultPath,
    );

    File::put(
        $this->agentExecutionVaultPath.'/AGENTS.md',
        "Agent execution test vault governance.\n",
    );

    config()->set(
        'aisf.obsidian_vault_path',
        $this->agentExecutionVaultPath,
    );

    app()->instance(AgentCapabilityPreflight::class, new Feature09PermissiveCapabilityPreflight(
        app(AgentRuntimeEnvironment::class),
    ));
});

afterEach(function (): void {
    if (
        isset($this->agentExecutionVaultPath)
        && is_string($this->agentExecutionVaultPath)
        && is_dir($this->agentExecutionVaultPath)
    ) {
        File::deleteDirectory(
            $this->agentExecutionVaultPath,
        );
    }
});

test('PM completion creates loosely-specified Tasks without requiring acceptance criteria or browser steps', function () {
    Queue::fake([DispatchWorkflowForProject::class]);
    [, $workRequest] = feature09Fixture();
    feature09FakeHarness('Planning persisted.', function (string $path, string $prompt) use ($workRequest): void {
        [$run, $token] = feature09RunAuthorization($prompt);
        $task = app(TaskWorkflowService::class)->savePlan($run, $workRequest, [[
            'title' => 'Add README.md', 'objective' => 'Document the project.',
            'implementation_spec' => '', 'acceptance_criteria' => [],
            'verification_commands' => [], 'browser_steps' => [], 'depends_on_position' => null,
        ]], $token)[0];
        markAgentRunDocumented($run);
        app(TaskWorkflowService::class)->handoff($run, $task, 'coder', 'implementation_ready', 'pm-coder-'.$run->id, [], $token);
    });

    app()->call([new ProcessAgentExecution($workRequest), 'handle']);

    $workRequest->refresh();
    $task = $workRequest->tasks()->sole();

    expect($workRequest->status)->toBe('waiting')
        ->and($task->position)->toBe(1)
        ->and($task->title)->toBe('Add README.md')
        ->and($task->status)->toBe('waiting')
        ->and($task->last_handoff['reason'])->toBe('implementation_ready')
        ->and($task->acceptance_criteria)->toBe([]);
});

test('PM already-implemented completion marks the WorkRequest completed without creating Tasks', function () {
    Queue::fake([DispatchWorkflowForProject::class]);
    [, $workRequest] = feature09Fixture();
    feature09FakeHarness('The README already exists.', function (string $path, string $prompt) use ($workRequest): void {
        [$run, $token] = feature09RunAuthorization($prompt);
        markAgentRunDocumented($run);
        app(WorkflowOutcomeService::class)->record(
            $run, $workRequest, 'already_implemented', 'The README already exists.',
            ['The README already exists.'], $token,
        );
    });

    app()->call([new ProcessAgentExecution($workRequest), 'handle']);

    $workRequest->refresh();

    expect($workRequest->status)->toBe('completed')
        ->and($workRequest->evidence)->toBe(['The README already exists.'])
        ->and($workRequest->outcome)->toBe('already_implemented')
        ->and($workRequest->tasks()->count())->toBe(0);
});

test('a completion reporting an approved review with zero findings is accepted', function () {
    [$project, , $task] = feature09TaskFixture();
    $coder = $project->agents()->where('role', 'coder')->sole();
    $qa = $project->agents()->where('role', 'qa')->sole();
    $sessions = app(AgentSessionManager::class);
    $coderRun = $sessions->startRun($sessions->forSubject($coder, $task), 'coder', feature09RunContext('coder'));
    $qaRun = $sessions->startRun($sessions->forSubject($qa, $task), 'qa', feature09RunContext('qa'));
    $task->update(['candidate_tree_sha' => 'deadbeef', 'candidate_created_by_run_id' => $coderRun->id]);

    $review = app(CandidateAcceptanceGate::class)->recordReview(
        $task, $coderRun, $qaRun, 'deadbeef', 'approved', 'No blocking issues found.', [],
    );

    expect($review->findings)->toBe([])
        ->and(app(CandidateAcceptanceGate::class)->hasCurrentApproval($task->refresh()))->toBeTrue();
});

test('a Task cannot execute without a durable PM handoff', function () {
    [, , $task] = feature09TaskFixture();
    $task->update(['last_handoff' => null]);
    feature09FakeHarness(feature09Completion([
        'status' => 'completed',
        'summary' => 'Added the README.',
    ]));

    expect(fn () => app()->call([new ProcessAgentExecution($task), 'handle']))
        ->toThrow(UnexpectedValueException::class);
});

test('Agents execute from the Project directory without creating a Task worktree', function () {
    [$project, , $task] = feature09TaskFixture();
    $executionPath = null;
    $harness = feature09FakeHarness('Implemented.', function (string $path) use (&$executionPath): void {
        $executionPath = $path;
    });

    $execution = app(AgentExecutionRunner::class)->run($task);

    expect($executionPath)->toBe($project->path)
        ->and($harness->writable)->toBeTrue()
        ->and($task->worktree_path)->toBeNull()
        ->and($execution->harnessResult->successful)->toBeTrue();
});

test('Task Agents execute from the isolated worktree once it exists', function () {
    [$project, , $task] = feature09TaskFixture();
    app(TaskWorktreeManager::class)->ensureWorktree($task);
    $task->refresh();
    $executionPath = null;
    $harness = feature09FakeHarness('Implemented.', function (string $path) use (&$executionPath): void {
        $executionPath = $path;
    });

    $execution = app(AgentExecutionRunner::class)->run($task);

    expect($executionPath)->toBe($task->worktree_path)
        ->and($executionPath)->not->toBe($project->path)
        ->and($harness->writable)->toBeTrue()
        ->and($execution->harnessResult->successful)->toBeTrue();
});

test('a resumable Coder session persists and resumes the same provider conversation for the same Task', function () {
    [, , $task] = feature09TaskFixture();
    $harness = feature09FakeHarness('Implemented.', supportsResume: true);
    $runner = app(AgentExecutionRunner::class);

    $firstExecution = $runner->run($task);
    $firstSession = $firstExecution->run->agentSession()->firstOrFail();
    $secondExecution = $runner->run($task);
    $secondSession = $secondExecution->run->agentSession()->firstOrFail();

    expect($harness->startCalls)->toBe(1)
        ->and($harness->resumeCalls)->toBe(1)
        ->and($harness->lastResumedProviderSessionId)->toBe('feature09-provider-session-1')
        ->and($firstSession->id)->toBe($secondSession->id)
        ->and($secondSession->provider_session_id)->toBe('feature09-provider-session-1')
        ->and($firstExecution->run->attempt)->toBe(1)
        ->and($firstExecution->run->context_mode)->toBe('initial')
        ->and($secondExecution->run->attempt)->toBe(2)
        ->and($secondExecution->run->context_mode)->toBe('delta')
        ->and($secondSession->runs()->count())->toBe(2);
});

test('different Tasks use separate Coder sessions and start separate provider conversations', function () {
    [, $workRequest, $firstTask] = feature09TaskFixture();
    $secondTask = $workRequest->tasks()->create([
        'position' => 2,
        'title' => 'Add CONTRIBUTING.md',
        'objective' => 'Document contribution rules.',
        'implementation_spec' => '',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
        'status' => 'waiting',
        'last_handoff' => ['to_role' => 'coder'],
    ]);
    $harness = feature09FakeHarness('Implemented.', supportsResume: true);
    $runner = app(AgentExecutionRunner::class);

    $firstExecution = $runner->run($firstTask);
    $secondExecution = $runner->run($secondTask);
    $firstSession = $firstExecution->run->agentSession()->firstOrFail();
    $secondSession = $secondExecution->run->agentSession()->firstOrFail();

    expect($harness->startCalls)->toBe(2)
        ->and($harness->resumeCalls)->toBe(0)
        ->and($firstSession->id)->not->toBe($secondSession->id)
        ->and($firstSession->provider_session_id)->toBe('feature09-provider-session-1')
        ->and($secondSession->provider_session_id)->toBe('feature09-provider-session-2');
});

test('different Agent roles use separate logical sessions for the same Task', function () {
    [, , $task] = feature09TaskFixture();
    $harness = feature09FakeHarness('Completed.', supportsResume: true);
    $runner = app(AgentExecutionRunner::class);

    $coderExecution = $runner->run($task);
    $task->update(['last_handoff' => ['to_role' => 'qa']]);
    $qaExecution = $runner->run($task->refresh());
    $coderSession = $coderExecution->run->agentSession()->firstOrFail();
    $qaSession = $qaExecution->run->agentSession()->firstOrFail();

    expect($harness->startCalls)->toBe(2)
        ->and($harness->resumeCalls)->toBe(0)
        ->and($coderExecution->run->role)->toBe('coder')
        ->and($qaExecution->run->role)->toBe('qa')
        ->and(substr_count($harness->prompt, 'VAULT DOCUMENTATION INVARIANT'))->toBe(1)
        ->and(substr_count($harness->prompt, 'write_vault_work_log exactly once'))->toBe(1)
        ->and($coderSession->id)->not->toBe($qaSession->id)
        ->and($coderSession->project_agent_id)->not->toBe($qaSession->project_agent_id);
});

test('all current execution modes inherit one identical generic vault documentation invariant', function () {
    $prompts = [
        'pm_initial' => feature09WorkRequestPrompt(false),
        'pm_dependency_handoff' => feature09WorkRequestPrompt(true),
        'coder_implementation' => feature09TaskPrompt('coder', 'implementation_ready'),
        'coder_repair' => feature09TaskPrompt('coder', 'changes_requested'),
        'qa_review' => feature09TaskPrompt('qa', 'implementation_ready'),
        'approved_finalization' => feature09TaskPrompt('coder', 'approved'),
    ];

    $contracts = [];

    foreach ($prompts as $name => $prompt) {
        expect(substr_count($prompt, 'VAULT DOCUMENTATION INVARIANT'))
            ->toBe(1);

        expect(substr_count($prompt, 'write_vault_work_log exactly once'))
            ->toBe(1);

        $contracts[$name] = feature09VaultDocumentationInvariant($prompt);
    }

    expect(array_unique(array_values($contracts)))
        ->toHaveCount(1);

    $contract = $contracts['pm_initial'];

    expect($contract)
        ->toContain('Perform your normal role-specific work first')
        ->toContain('save_task_plan, save_task_result, or save_qa_review')
        ->toContain('use get_vault_rules')
        ->toContain('write_vault_work_log exactly once')
        ->toContain('summary, not a transcript')
        ->toContain('handoff_task, finalize_task, or record_workflow_outcome');

    expect($prompts['pm_initial'])
        ->toContain('For a new request, call save_task_plan.');

    expect($prompts['pm_dependency_handoff'])
        ->toContain('For an existing plan, hand off each newly dependency-ready Task');

    expect($prompts['coder_implementation'])
        ->toContain('Coder mode: implementation_ready.')
        ->toContain('call save_task_result.');

    expect($prompts['coder_repair'])
        ->toContain('Coder mode: changes_requested.')
        ->toContain('Use record_workflow_outcome only for a deterministic blocker');

    expect($prompts['qa_review'])
        ->toContain('call save_qa_review with "changes_requested"')
        ->toContain('call save_qa_review with "approved"')
        ->toContain('Do not edit or commit.');

    expect($prompts['approved_finalization'])
        ->toContain('This is approved finalization mode.')
        ->toContain('Use finalize_task as the workflow-ending action');
});

test('a future Agent role inherits the generic vault contract without a vault-specific role branch', function () {
    $prompt = feature09TaskPrompt(
        'future_specialist',
        'future_specialist_review',
    );

    expect(substr_count($prompt, 'VAULT DOCUMENTATION INVARIANT'))
        ->toBe(1);

    expect(substr_count($prompt, 'write_vault_work_log exactly once'))
        ->toBe(1);

    expect(feature09VaultDocumentationInvariant($prompt))
        ->toContain('use get_vault_rules')
        ->toContain('write_vault_work_log exactly once')
        ->toContain('handoff_task, finalize_task, or record_workflow_outcome');

    expect($prompt)
        ->toContain('Future Specialist');
});

test('vault preflight prevents a fresh provider turn and reconciles the AgentRun as infrastructure failure', function () {
    [, , $task] = feature09TaskFixture();
    $harness = feature09FakeHarness('This provider must not run.');
    config()->set('aisf.obsidian_vault_path', null);
    $job = new ProcessAgentExecution($task);
    $caught = null;

    try {
        app()->call([$job, 'handle']);
    } catch (RuntimeException $exception) {
        $caught = $exception;
    }

    expect($caught)
        ->toBeInstanceOf(RuntimeException::class);

    expect($caught?->getMessage())
        ->toContain('The Obsidian vault path is not configured.');

    expect($harness->startCalls)
        ->toBe(0);

    expect($harness->resumeCalls)
        ->toBe(0);

    $run = AgentRun::query()->latest('id')->firstOrFail();

    expect($run->status)
        ->toBe('failed')
        ->and($run->reconciliation_status)->toBe('recoverable')
        ->and($run->failure_class)->toBe('infrastructure_recoverable');

    $job->failed($caught);

    expect($task->refresh()->status)
        ->toBe('failed')
        ->and($task->blocked_reason)
        ->toContain('The Obsidian vault path is not configured.');
});

test('vault preflight prevents provider resume before the resumed provider turn starts', function () {
    [, , $task] = feature09TaskFixture();
    $harness = feature09FakeHarness(
        'Provider turn completed.',
        supportsResume: true,
    );
    $runner = app(AgentExecutionRunner::class);
    $reconciler = app(AgentTurnReconciler::class);

    $firstExecution = $runner->run($task);
    $firstReconciliation = $reconciler->reconcile(
        $task,
        $firstExecution,
    );

    expect($firstReconciliation->classification)
        ->toBe('recoverable');

    expect($harness->startCalls)
        ->toBe(1);

    expect($harness->resumeCalls)
        ->toBe(0);

    File::delete(
        $this->agentExecutionVaultPath.'/AGENTS.md',
    );

    $secondExecution = $runner->run($task->refresh());
    $secondReconciliation = $reconciler->reconcile(
        $task->refresh(),
        $secondExecution,
    );

    expect($harness->startCalls)
        ->toBe(1);

    expect($harness->resumeCalls)
        ->toBe(0);

    expect($secondExecution->harnessResult->successful)
        ->toBeFalse();

    expect($secondExecution->harnessResult->failureMessage)
        ->toContain(
            'The Obsidian vault root must contain a readable AGENTS.md governance file.',
        );

    expect($secondReconciliation->classification)
        ->toBe('recoverable')
        ->and($secondReconciliation->failureClass)
        ->toBe('infrastructure_recoverable')
        ->and($secondReconciliation->retryInfrastructure)
        ->toBeTrue();

    expect($secondExecution->run->fresh()->status)
        ->toBe('failed');
});

test('QA starts a fresh provider conversation for every review attempt', function () {
    [, , $task] = feature09TaskFixture();
    $task->update(['last_handoff' => ['to_role' => 'qa']]);
    $harness = feature09FakeHarness('Review complete.', supportsResume: true);
    $runner = app(AgentExecutionRunner::class);

    $runner->run($task->refresh());
    $runner->run($task->refresh());

    expect($harness->startCalls)->toBe(2)
        ->and($harness->resumeCalls)->toBe(0)
        ->and($task->agentSessions()->whereHas('projectAgent', fn ($query) => $query->where('role', 'qa'))->sole()->provider_session_id)
        ->toBeNull();
});

test('a non-resumable provider keeps using fresh fallback invocations without persisting provider identity', function () {
    [, , $task] = feature09TaskFixture();
    $harness = feature09FakeHarness('Implemented.');
    $runner = app(AgentExecutionRunner::class);

    $firstExecution = $runner->run($task);
    $secondExecution = $runner->run($task);
    $session = $secondExecution->run->agentSession()->firstOrFail();

    expect($harness->startCalls)->toBe(2)
        ->and($harness->resumeCalls)->toBe(0)
        ->and($session->provider_session_id)->toBeNull()
        ->and($firstExecution->run->context_mode)->toBe('initial')
        ->and($secondExecution->run->context_mode)->toBe('initial');
});

test('a resumed provider cannot silently replace the persisted provider session identifier', function () {
    [, , $task] = feature09TaskFixture();
    $harness = feature09FakeHarness(
        'Implemented.',
        supportsResume: true,
        resumeProviderSessionId: 'unexpected-provider-session',
    );
    $runner = app(AgentExecutionRunner::class);

    $firstExecution = $runner->run($task);
    $secondExecution = $runner->run($task);
    $session = $firstExecution->run->agentSession()->firstOrFail()->refresh();

    expect($secondExecution->harnessResult->successful)->toBeFalse()
        ->and($secondExecution->harnessResult->failureMessage)->toContain('different session identifier')
        ->and($session->provider_session_id)->toBe('feature09-provider-session-1')
        ->and($harness->resumeCalls)->toBe(1);
});

test('a capability preflight failure is recorded as a capability diagnostic, not a fabricated code defect', function () {
    [, , $task] = feature09TaskFixture();
    feature09FakeHarness('This provider must not run.');
    app()->instance(AgentCapabilityPreflight::class, new Feature09FailingCapabilityPreflight(
        'Docker is required for this Project but is not available to the Agent worker user.',
    ));

    $execution = app(AgentExecutionRunner::class)->run($task);

    expect($execution->harnessResult->successful)->toBeFalse()
        ->and($execution->harnessResult->failureMessage)->toBe(
            'Docker is required for this Project but is not available to the Agent worker user.',
        );
});

test('PM and QA prompt contracts keep the strengthened repository read-only sentence', function () {
    $pmPrompt = feature09WorkRequestPrompt(false);
    $qaPrompt = feature09TaskPrompt('qa', 'implementation_ready');

    $sentence = 'you are not authorized to modify, stage, or commit any file in this repository under any circumstance, trusted-local or not.';

    expect($pmPrompt)->toContain($sentence)
        ->and($qaPrompt)->toContain($sentence);
});

test('the sandboxed Docker-forcing verification contract is preserved by default', function () {
    config()->set('aisf.trusted_local_execution', false);
    $prompt = feature09TaskPrompt('qa', 'implementation_ready');

    expect($prompt)
        ->toContain('Never invoke Docker directly as a workaround.')
        ->toContain('you MUST call run_project_verification with profile "ci"');
});

test('the trusted-local verification contract replaces the Docker-forcing text when enabled', function () {
    config()->set('aisf.trusted_local_execution', true);
    $prompt = feature09TaskPrompt('qa', 'implementation_ready');

    expect($prompt)
        ->toContain('TERMINAL-PARITY LOCAL EXECUTION')
        ->toContain('run the commands a developer would run directly, including Docker')
        ->not->toContain('Never invoke Docker directly as a workaround.')
        ->not->toContain('you MUST call run_project_verification with profile "ci"');
});

test('Coder retains a writable execution target and normal command capability under trusted local execution', function () {
    config()->set('aisf.trusted_local_execution', true);
    [$project, , $task] = feature09TaskFixture();
    $harness = feature09FakeHarness('Implemented.');

    $execution = app(AgentExecutionRunner::class)->run($task);

    expect($harness->writable)->toBeTrue()
        ->and($execution->harnessResult->successful)->toBeTrue();
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
    // admin metadata behind), causing a retry to fail with "Unable to create the isolated
    // Task Git worktree." even though nothing was actually still using that branch or directory.
    Process::path($repositoryPath)->run(['git', 'worktree', 'add', '-b', $branchName, $worktreePath, 'HEAD'])->throw();
    File::deleteDirectory($worktreePath);

    app(TaskWorktreeManager::class)->ensureWorktree($task);
    $task->refresh();

    expect($task->worktree_path)->toBe($worktreePath)
        ->and(is_dir($worktreePath))->toBeTrue();
});

test('ensureWorktree marks a tracked local SQLite database skip-worktree so Agent writes do not pollute status', function () {
    [$project, , $task] = feature09TaskFixture();
    $repositoryPath = $project->path;

    // Some Projects commit their local SQLite database (like the real miseledger repo) instead of
    // gitignoring it, so a fresh worktree checks it out as a tracked file under an arbitrary name.
    File::put($repositoryPath.'/app.sqlite', "SQLite format 3\x00".str_repeat("\x00", 100));
    Process::path($repositoryPath)->run(['git', 'add', 'app.sqlite'])->throw();
    Process::path($repositoryPath)->run([
        'git', '-c', 'user.name=AISF Tests', '-c', 'user.email=aisf-tests@example.test',
        'commit', '-m', 'chore: track local database',
    ])->throw();

    app(TaskWorktreeManager::class)->ensureWorktree($task);
    $task->refresh();
    $worktreePath = (string) $task->worktree_path;

    // Simulate an Agent running migrations/tests, which writes to the tracked database as a
    // side effect without intending it as part of the Task's diff.
    File::put($worktreePath.'/app.sqlite', "SQLite format 3\x00".str_repeat("\x01", 100));

    $status = Process::path($worktreePath)->run(['git', 'status', '--porcelain'])->output();

    expect(trim($status))->toBe('');
});

test('a Coder completion with a reported commit SHA is deferred to Phase 7', function () {
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
})->skip('Commit integration is intentionally implemented in roadmap Phase 7.');

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
})->skip('Pull-request integration is intentionally implemented in roadmap Phase 7.');

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
})->skip('CI recovery is intentionally implemented in roadmap Phase 7.');

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
})->skip('Independent QA review execution is intentionally implemented in roadmap Phase 6.');

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
})->skip('Independent QA repair loops are intentionally implemented in roadmap Phase 6.');

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
    Queue::fake();
    [$project, , $task] = feature09TaskFixture();
    $task->update(['status' => 'failed', 'outcome' => 'blocked', 'protocol_recovery_count' => 3, 'blocked_reason' => 'Implementation failed.', 'last_handoff' => ['id' => 42, 'to_role' => 'coder', 'reason' => 'ci_failed']]);

    $response = $this->post(route('projects.tasks.retry', [$project, $task]));

    $response->assertRedirect(route('projects.tasks.show', [$project, $task]));

    expect($task->refresh()->status)->toBe('pending')
        ->and($task->outcome)->toBeNull()
        ->and($task->protocol_recovery_count)->toBe(0)
        ->and($task->blocked_reason)->toBeNull()
        ->and($task->last_handoff['id'])->toBe(42);

    Queue::assertPushed(ProcessAgentExecution::class, fn (ProcessAgentExecution $job): bool => $job->subject->is($task));
});

test('an operator Retry re-enters a failed WorkRequest as pending', function () {
    Queue::fake();
    [$project, $workRequest] = feature09Fixture();
    $workRequest->update(['status' => 'failed', 'outcome' => 'blocked', 'protocol_recovery_count' => 3, 'failure_reason' => 'Foreman execution failed.']);

    $response = $this->post(route('projects.work-requests.retry', [$project, $workRequest]));

    $response->assertRedirect(route('projects.show', $project));

    expect($workRequest->refresh()->status)->toBe('pending')
        ->and($workRequest->outcome)->toBeNull()
        ->and($workRequest->protocol_recovery_count)->toBe(0)
        ->and($workRequest->failure_reason)->toBeNull();
});

test('Run now requires a durable handoff before dispatching a Task', function () {
    Queue::fake();
    [$project, , $task] = feature09TaskFixture();
    $task->update(['status' => 'running']);

    $this->post(route('projects.tasks.run', [$project, $task]));
    Queue::assertNotPushed(ProcessAgentExecution::class);

    $task->update(['status' => 'pending']);
    $this->post(route('projects.tasks.run', [$project, $task]));
    Queue::assertNotPushed(ProcessAgentExecution::class);
});

/**
 * Fake selected remote Git command prefixes while allowing TaskWorktreeManager verification commands to run for real.
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
 * Build a Project and WorkRequest fixture with the repository's default Agents provisioned.
 *
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
 * Build a Task fixture already carrying a durable handoff to the configured Coder.
 *
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
        'status' => 'waiting',
        'last_handoff' => ['to_role' => 'coder'],
    ]);

    return [$project, $workRequest, $task];
}

/**
 * Capture one Project Manager prompt in initial-planning or dependency-handoff mode.
 */
function feature09WorkRequestPrompt(bool $withExistingPlan): string
{
    [, $workRequest] = feature09Fixture();

    if ($withExistingPlan) {
        $workRequest->tasks()->create([
            'position' => 1,
            'title' => 'Existing planned Task',
            'objective' => 'Preserve the existing plan.',
            'implementation_spec' => '',
            'acceptance_criteria' => [],
            'verification_commands' => [],
            'browser_steps' => [],
            'status' => 'waiting',
            'last_handoff' => [
                'to_role' => 'coder',
                'reason' => 'implementation_ready',
            ],
        ]);
    }

    $harness = feature09FakeHarness(
        'Prompt captured.',
    );

    app(AgentExecutionRunner::class)->run(
        $workRequest->refresh(),
    );

    return $harness->prompt;
}

/**
 * Capture one Task prompt for the requested role and durable handoff reason.
 */
function feature09TaskPrompt(string $role, string $reason): string
{
    [$project, , $task] = feature09TaskFixture();

    if (! $project->agents()->where('role', $role)->exists()) {
        $project->agents()->create([
            'role' => $role,
            'name' => 'Future Specialist',
            'harness' => 'codex',
            'enabled' => true,
        ]);
    }

    $task->update([
        'last_handoff' => [
            'to_role' => $role,
            'reason' => $reason,
        ],
    ]);

    $harness = feature09FakeHarness(
        'Prompt captured.',
    );

    app(AgentExecutionRunner::class)->run(
        $task->refresh(),
    );

    return $harness->prompt;
}

/**
 * Extract the generic vault documentation section from one generated provider prompt.
 */
function feature09VaultDocumentationInvariant(string $prompt): string
{
    $heading = 'VAULT DOCUMENTATION INVARIANT';
    $start = strpos($prompt, $heading);

    if ($start === false) {
        return '';
    }

    $end = strpos(
        $prompt,
        "\n\nACTIVE RUN AUTHORIZATION",
        $start,
    );

    if ($end === false) {
        return trim(substr($prompt, $start));
    }

    return trim(
        substr(
            $prompt,
            $start,
            $end - $start,
        ),
    );
}

/**
 * Bind a deterministic fake Agent harness for the current test.
 */
function feature09FakeHarness(
    string|Throwable $result,
    ?callable $sideEffect = null,
    bool $supportsResume = false,
    ?string $resumeProviderSessionId = null,
): Feature09FakeAgentHarness {
    $harness = new Feature09FakeAgentHarness(
        $result,
        $sideEffect,
        $supportsResume,
        $resumeProviderSessionId,
    );
    app()->instance(AgentHarness::class, $harness);

    return $harness;
}

/**
 * Build a Project Manager completion payload with the supplied overrides.
 *
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
 * Build a generic Agent completion payload with the supplied overrides.
 *
 * @param  array<string, mixed>  $overrides
 */
function feature09Completion(array $overrides): string
{
    return json_encode(array_merge([
        'status' => 'completed',
        'summary' => 'Done.',
    ], $overrides), JSON_THROW_ON_ERROR);
}

/**
 * Create a temporary initialized Git repository for filesystem-backed workflow tests.
 */
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

/**
 * Extract the durable AgentRun and execution token embedded in the provider prompt.
 *
 * @return array{0: AgentRun, 1: string}
 */
function feature09RunAuthorization(string $prompt): array
{
    preg_match('/Agent run ID: (\d+)/', $prompt, $runMatch);
    preg_match('/Agent run token: ([A-Za-z0-9]+)/', $prompt, $tokenMatch);

    return [AgentRun::query()->findOrFail((int) $runMatch[1]), $tokenMatch[1]];
}

/**
 * Build a valid durable AgentRun context for direct session-manager tests.
 *
 * @return array<string, mixed>
 */
function feature09RunContext(string $role): array
{
    return [
        'mode' => 'initial', 'input' => 'Execute.', 'sources' => [],
        'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => $role,
    ];
}
