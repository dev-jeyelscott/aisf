<?php

use App\Jobs\DispatchWorkflowForProject;
use App\Jobs\ProcessAgentExecution;
use App\Models\AgentRun;
use App\Models\AgentRunAction;
use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\Task;
use App\Models\WorkRequest;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResult;
use App\Services\AgentRunActionRecorder;
use App\Services\AgentSessionManager;
use App\Services\AgentTurnExecution;
use App\Services\AgentTurnReconciler;
use App\Services\ProjectAgentProvisioner;
use App\Services\TaskWorkflowService;
use Illuminate\Support\Facades\Queue;

class ReconciliationFakeHarness extends AgentHarness
{
    /**
     * Build a deterministic harness result with an optional durable-state side effect.
     *
     * @param  callable(string): void|null  $sideEffect
     */
    public function __construct(
        private readonly AgentHarnessResult $result,
        private $sideEffect = null,
    ) {}

    /**
     * Keep reconciliation tests on fresh turns instead of provider session resumption.
     */
    public function canResume(ProjectAgent $agent): bool
    {
        return false;
    }

    /**
     * Apply the configured durable-state side effect and return the predetermined provider result.
     */
    public function start(ProjectAgent $agent, string $repositoryPath, string $prompt, ?array $schema = null, bool $writable = false): AgentHarnessResult
    {
        if ($this->sideEffect !== null) {
            ($this->sideEffect)($prompt);
        }

        return $this->result;
    }
}

test('durable PM actions plus documentation win over malformed provider output', function () {
    Queue::fake([DispatchWorkflowForProject::class]);
    $workRequest = reconciliationWorkRequest();
    app()->instance(AgentHarness::class, new ReconciliationFakeHarness(
        new AgentHarnessResult(true, '{malformed', null, 0),
        reconciliationPlanSideEffect($workRequest),
    ));

    app()->call([new ProcessAgentExecution($workRequest), 'handle']);

    $run = AgentRun::query()->latest('id')->firstOrFail();
    $task = $workRequest->tasks()->sole();
    expect($workRequest->refresh()->status)->toBe('waiting')
        ->and($task->status)->toBe('waiting')
        ->and($run->status)->toBe('succeeded')
        ->and($run->reconciliation_status)->toBe('satisfied');
});

test('durable PM actions plus documentation win over an empty response and provider exit failure', function () {
    Queue::fake([DispatchWorkflowForProject::class]);
    $workRequest = reconciliationWorkRequest();
    app()->instance(AgentHarness::class, new ReconciliationFakeHarness(
        new AgentHarnessResult(false, null, null, 17, 'Provider exited after tool calls.'),
        reconciliationPlanSideEffect($workRequest),
    ));

    app()->call([new ProcessAgentExecution($workRequest), 'handle']);

    $run = AgentRun::query()->latest('id')->firstOrFail();
    expect($workRequest->refresh()->status)->toBe('waiting')
        ->and($run->status)->toBe('succeeded')
        ->and($run->exit_code)->toBe(17)
        ->and($run->execution_metadata['provider_successful'])->toBeFalse();
});

test('otherwise satisfied durable PM actions without documentation use protocol recovery even after provider failure', function () {
    Queue::fake([DispatchWorkflowForProject::class]);
    $workRequest = reconciliationWorkRequest();
    app()->instance(AgentHarness::class, new ReconciliationFakeHarness(
        new AgentHarnessResult(false, null, null, 17, 'Provider exited after tool calls.'),
        reconciliationUndocumentedPlanSideEffect($workRequest),
    ));

    app()->call([new ProcessAgentExecution($workRequest), 'handle']);

    $run = AgentRun::query()->latest('id')->firstOrFail();
    expect($workRequest->refresh()->status)->toBe('waiting')
        ->and($workRequest->protocol_recovery_count)->toBe(1)
        ->and($run->status)->toBe('failed')
        ->and($run->reconciliation_status)->toBe('recoverable')
        ->and($run->failure_class)->toBe('protocol_recoverable')
        ->and($run->actions()->where('action', AgentRunAction::ACTION_VAULT_NOTE_WRITTEN)->count())->toBe(0);
});

test('otherwise satisfied Coder result and handoff evidence without documentation use protocol recovery', function () {
    [$project, $task, $run] = taskRoleHandoffFixture('coder');
    $task->update([
        'status' => 'running',
        'candidate_tree_sha' => 'candidate-tree-1',
        'candidate_created_by_run_id' => $run->id,
        'candidate_kind' => 'changes',
    ]);

    app(AgentRunActionRecorder::class)->record(
        $run,
        AgentRunAction::ACTION_TASK_RESULT_SAVED,
        $task,
    );
    reconciliationCreateHandoffEvidence(
        $project,
        $task,
        $run,
        'qa',
        'ready_for_review',
    );

    $reconciliation = app(AgentTurnReconciler::class)->reconcile(
        $task->refresh(),
        new AgentTurnExecution(
            $run,
            new AgentHarnessResult(true, '', null, 0),
            'Coder durable actions persisted without documentation.',
        ),
    );

    expect($reconciliation->classification)->toBe('recoverable')
        ->and($task->refresh()->status)->toBe('waiting')
        ->and($task->protocol_recovery_count)->toBe(1)
        ->and($run->refresh()->failure_class)->toBe('protocol_recoverable');
});

test('otherwise satisfied QA review and handoff evidence without documentation use protocol recovery', function () {
    [$project, $task, $run] = taskRoleHandoffFixture('qa');
    $coder = $project->agents()->where('role', 'coder')->sole();
    $candidateRun = app(AgentSessionManager::class)->startRun(
        app(AgentSessionManager::class)->forSubject($coder, $task),
        'coder',
        [
            'mode' => 'initial',
            'input' => 'Create candidate evidence.',
            'sources' => [],
            'agent_snapshot' => [],
            'prompt_snapshot' => [],
            'role' => 'coder',
        ],
    );
    $task->update([
        'status' => 'running',
        'candidate_tree_sha' => 'candidate-tree-1',
        'candidate_created_by_run_id' => $candidateRun->id,
        'candidate_kind' => 'changes',
    ]);
    $review = $task->candidateReviews()->create([
        'candidate_agent_run_id' => $candidateRun->id,
        'reviewer_agent_run_id' => $run->id,
        'candidate_tree_sha' => 'candidate-tree-1',
        'status' => 'approved',
        'summary' => 'Approved durable candidate.',
        'findings' => [],
    ]);

    app(AgentRunActionRecorder::class)->record(
        $run,
        AgentRunAction::ACTION_QA_REVIEW_SAVED,
        $review,
    );
    reconciliationCreateHandoffEvidence(
        $project,
        $task,
        $run,
        'coder',
        'approved',
    );

    $reconciliation = app(AgentTurnReconciler::class)->reconcile(
        $task->refresh(),
        new AgentTurnExecution(
            $run,
            new AgentHarnessResult(true, '', null, 0),
            'QA durable actions persisted without documentation.',
        ),
    );

    expect($reconciliation->classification)->toBe('recoverable')
        ->and($task->refresh()->status)->toBe('waiting')
        ->and($task->protocol_recovery_count)->toBe(1)
        ->and($run->refresh()->failure_class)->toBe('protocol_recoverable');
});
test('missing durable postconditions consume protocol recovery without failing the subject', function () {
    Queue::fake([DispatchWorkflowForProject::class]);
    $workRequest = reconciliationWorkRequest();
    app()->instance(AgentHarness::class, new ReconciliationFakeHarness(
        new AgentHarnessResult(true, 'I forgot the tools.', null, 0),
    ));

    app()->call([new ProcessAgentExecution($workRequest), 'handle']);

    $run = AgentRun::query()->latest('id')->firstOrFail();
    expect($workRequest->refresh()->status)->toBe('waiting')
        ->and($workRequest->protocol_recovery_count)->toBe(1)
        ->and($run->status)->toBe('failed')
        ->and($run->failure_class)->toBe('protocol_recoverable');
});

test('documented recovery-limit failure records a durable blocked outcome', function () {
    Queue::fake([DispatchWorkflowForProject::class]);
    config(['aisf.max_protocol_recoveries' => 0]);
    $workRequest = reconciliationWorkRequest();
    app()->instance(AgentHarness::class, new ReconciliationFakeHarness(
        new AgentHarnessResult(true, '', null, 0),
        reconciliationDocumentationSideEffect(),
    ));

    app()->call([new ProcessAgentExecution($workRequest), 'handle']);

    $run = AgentRun::query()->latest('id')->firstOrFail();
    expect($workRequest->refresh()->status)->toBe('failed')
        ->and($workRequest->outcome)->toBe('blocked')
        ->and($run->reconciliation_status)->toBe('terminal')
        ->and($run->actions()->where('action', AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED)->count())->toBe(1);
});

test('undocumented recovery-limit failure parks the subject for operator retry without a blocked outcome', function () {
    Queue::fake([DispatchWorkflowForProject::class]);
    config(['aisf.max_protocol_recoveries' => 0]);
    $workRequest = reconciliationWorkRequest();
    app()->instance(AgentHarness::class, new ReconciliationFakeHarness(
        new AgentHarnessResult(true, '', null, 0),
    ));

    app()->call([new ProcessAgentExecution($workRequest), 'handle']);

    $run = AgentRun::query()->latest('id')->firstOrFail();
    $fresh = $workRequest->refresh();

    expect($fresh->status)->toBe('failed')
        ->and($fresh->outcome)->toBeNull()
        ->and($fresh->protocol_recovery_count)->toBe(1)
        ->and($fresh->failure_reason)->toContain('required vault work note')
        ->and($run->status)->toBe('failed')
        ->and($run->reconciliation_status)->toBe('recoverable')
        ->and($run->failure_class)->toBe('protocol_recoverable')
        ->and($run->actions()->where('action', AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED)->count())->toBe(0);
});

/**
 * Create one WorkRequest whose project can run the complete role workflow.
 */
function reconciliationWorkRequest(): WorkRequest
{
    $project = Project::factory()->create(['path' => base_path()]);
    app(ProjectAgentProvisioner::class)->ensureFor($project);

    return $project->workRequests()->create(['prompt' => 'Plan durable work.']);
}

/**
 * Return a harness side effect that persists a complete documented PM plan and handoff.
 *
 * @return Closure(string): void
 */
function reconciliationPlanSideEffect(WorkRequest $workRequest): Closure
{
    return function (string $prompt) use ($workRequest): void {
        [$run, $token] = reconciliationRunFromPrompt($prompt);
        $task = reconciliationSavePlan($workRequest, $run, $token);
        markAgentRunDocumented($run);
        app(TaskWorkflowService::class)->handoff(
            $run,
            $task,
            'coder',
            'implementation_ready',
            'pm-coder-'.$run->id,
            [],
            $token,
        );
    };
}

/**
 * Return a harness side effect that creates otherwise-satisfied PM durable state without documentation.
 *
 * @return Closure(string): void
 */
function reconciliationUndocumentedPlanSideEffect(WorkRequest $workRequest): Closure
{
    return function (string $prompt) use ($workRequest): void {
        [$run, $token] = reconciliationRunFromPrompt($prompt);
        $task = reconciliationSavePlan($workRequest, $run, $token);
        $coder = $workRequest->project->agents()->where('role', 'coder')->sole();
        $handoff = $task->handoffs()->create([
            'from_project_agent_id' => $run->agentSession->project_agent_id,
            'to_project_agent_id' => $coder->id,
            'from_agent_run_id' => $run->id,
            'reason' => 'implementation_ready',
            'payload' => [],
            'idempotency_key' => 'undocumented-pm-coder-'.$run->id,
            'dispatched_at' => now(),
        ]);

        app(AgentRunActionRecorder::class)->record(
            $run,
            AgentRunAction::ACTION_HANDOFF_CREATED,
            $handoff,
        );

        $task->update([
            'status' => 'waiting',
            'last_handoff' => [
                'id' => $handoff->id,
                'to_role' => 'coder',
                'reason' => 'implementation_ready',
                'payload' => [],
            ],
        ]);
    };
}

/**
 * Return a harness side effect that records only the exact documentation evidence for the current run.
 *
 * @return Closure(string): void
 */
function reconciliationDocumentationSideEffect(): Closure
{
    return function (string $prompt): void {
        [$run] = reconciliationRunFromPrompt($prompt);
        markAgentRunDocumented($run);
    };
}

/**
 * Persist one direct handoff and matching action for reconciliation-only durable-state fixtures.
 */
function reconciliationCreateHandoffEvidence(
    Project $project,
    Task $task,
    AgentRun $run,
    string $toRole,
    string $reason,
): void {
    $target = $project->agents()->where('role', $toRole)->sole();
    $handoff = $task->handoffs()->create([
        'from_project_agent_id' => $run->agentSession->project_agent_id,
        'to_project_agent_id' => $target->id,
        'from_agent_run_id' => $run->id,
        'reason' => $reason,
        'payload' => [],
        'idempotency_key' => 'reconciliation-'.$run->id.'-'.$toRole,
        'dispatched_at' => now(),
    ]);

    app(AgentRunActionRecorder::class)->record(
        $run,
        AgentRunAction::ACTION_HANDOFF_CREATED,
        $handoff,
    );

    $task->update([
        'last_handoff' => [
            'id' => $handoff->id,
            'to_role' => $toRole,
            'reason' => $reason,
            'payload' => [],
        ],
    ]);
}
/**
 * Persist the standard PM plan used by reconciliation fixtures.
 */
function reconciliationSavePlan(
    WorkRequest $workRequest,
    AgentRun $run,
    string $token,
): Task {
    return app(TaskWorkflowService::class)->savePlan($run, $workRequest, [[
        'title' => 'Implement durable state',
        'objective' => 'Implement durable state',
        'implementation_spec' => '',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
        'depends_on_position' => null,
    ]], $token)[0];
}

/**
 * Extract the active AgentRun and execution token supplied in a generated Agent prompt.
 *
 * @return array{0: AgentRun, 1: string}
 */
function reconciliationRunFromPrompt(string $prompt): array
{
    preg_match('/Agent run ID: (\d+)/', $prompt, $runMatch);
    preg_match('/Agent run token: ([A-Za-z0-9]+)/', $prompt, $tokenMatch);

    return [
        AgentRun::query()->findOrFail((int) $runMatch[1]),
        $tokenMatch[1],
    ];
}
