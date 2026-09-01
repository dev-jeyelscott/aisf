<?php

use App\Jobs\DispatchWorkflowForProject;
use App\Jobs\ProcessAgentExecution;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\WorkRequest;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResult;
use App\Services\ProjectAgentProvisioner;
use App\Services\TaskWorkflowService;
use Illuminate\Support\Facades\Queue;

class ReconciliationFakeHarness extends AgentHarness
{
    /** @param callable(string): void|null $sideEffect */
    public function __construct(
        private readonly AgentHarnessResult $result,
        private $sideEffect = null,
    ) {}

    public function canResume(ProjectAgent $agent): bool
    {
        return false;
    }

    public function start(ProjectAgent $agent, string $repositoryPath, string $prompt, ?array $schema = null, bool $writable = false): AgentHarnessResult
    {
        if ($this->sideEffect !== null) {
            ($this->sideEffect)($prompt);
        }

        return $this->result;
    }
}

test('durable PM actions win over malformed provider output', function () {
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

test('durable PM actions win over an empty response and provider exit failure', function () {
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

test('exceeding protocol recovery records a durable blocked outcome', function () {
    Queue::fake([DispatchWorkflowForProject::class]);
    config(['aisf.max_protocol_recoveries' => 0]);
    $workRequest = reconciliationWorkRequest();
    app()->instance(AgentHarness::class, new ReconciliationFakeHarness(
        new AgentHarnessResult(true, '', null, 0),
    ));

    app()->call([new ProcessAgentExecution($workRequest), 'handle']);

    $run = AgentRun::query()->latest('id')->firstOrFail();
    expect($workRequest->refresh()->status)->toBe('failed')
        ->and($workRequest->outcome)->toBe('blocked')
        ->and($run->reconciliation_status)->toBe('terminal')
        ->and($run->actions()->where('action', 'workflow_outcome_recorded')->count())->toBe(1);
});

function reconciliationWorkRequest(): WorkRequest
{
    $project = Project::factory()->create(['path' => base_path()]);
    app(ProjectAgentProvisioner::class)->ensureFor($project);

    return $project->workRequests()->create(['prompt' => 'Plan durable work.']);
}

/** @return Closure(string): void */
function reconciliationPlanSideEffect(WorkRequest $workRequest): Closure
{
    return function (string $prompt) use ($workRequest): void {
        preg_match('/Agent run ID: (\d+)/', $prompt, $runMatch);
        preg_match('/Agent run token: ([A-Za-z0-9]+)/', $prompt, $tokenMatch);
        $run = AgentRun::query()->findOrFail((int) $runMatch[1]);
        $task = app(TaskWorkflowService::class)->savePlan($run, $workRequest, [[
            'title' => 'Implement durable state',
            'objective' => 'Implement durable state',
            'implementation_spec' => '',
            'acceptance_criteria' => [],
            'verification_commands' => [],
            'browser_steps' => [],
            'depends_on_position' => null,
        ]], $tokenMatch[1])[0];
        app(TaskWorkflowService::class)->handoff(
            $run,
            $task,
            'coder',
            'implementation_ready',
            'pm-coder-'.$run->id,
            [],
            $tokenMatch[1],
        );
    };
}
