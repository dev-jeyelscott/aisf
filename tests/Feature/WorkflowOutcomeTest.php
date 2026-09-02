<?php

use App\Models\AgentRun;
use App\Models\AgentRunAction;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkRequest;
use App\Services\AgentSessionManager;
use App\Services\ProjectAgentProvisioner;
use App\Services\WorkflowOutcomeService;
use UnexpectedValueException;

test('the Project Manager records an already-implemented request without Tasks', function () {
    [$project, $workRequest] = workflowOutcomeFixture();
    $run = workflowOutcomeRun($project, $workRequest, 'project_manager');
    markAgentRunDocumented($run);

    $recorded = app(WorkflowOutcomeService::class)->record(
        $run,
        $workRequest,
        'already_implemented',
        'The requested behavior already exists.',
        ['Verified in the repository.'],
        $run->execution_token,
    );

    expect($recorded->status)->toBe('completed')
        ->and($recorded->outcome)->toBe('already_implemented')
        ->and($recorded->evidence)->toBe(['Verified in the repository.'])
        ->and($run->actions()->where('action', 'workflow_outcome_recorded')->count())->toBe(1);
});

test('an active Task Agent records a deterministic blocker', function () {
    [$project, $workRequest] = workflowOutcomeFixture();
    $task = $workRequest->tasks()->create([
        'position' => 1,
        'title' => 'Blocked Task',
        'objective' => 'Blocked Task',
        'implementation_spec' => '',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
        'status' => 'running',
    ]);
    $run = workflowOutcomeRun($project, $task, 'coder');
    markAgentRunDocumented($run);

    app(WorkflowOutcomeService::class)->record(
        $run,
        $task,
        'blocked',
        'A required external contract is unavailable.',
        [],
        $run->execution_token,
    );

    expect($task->refresh()->status)->toBe('failed')
        ->and($task->outcome)->toBe('blocked')
        ->and($task->blocked_reason)->toBe('A required external contract is unavailable.');
});

test('an undocumented Project Manager cannot record a terminal WorkRequest outcome', function (string $outcome) {
    [$project, $workRequest] = workflowOutcomeFixture();
    $run = workflowOutcomeRun($project, $workRequest, 'project_manager');

    expect(fn () => app(WorkflowOutcomeService::class)->record(
        $run,
        $workRequest,
        $outcome,
        'The WorkRequest reached a terminal outcome.',
        ['Verified in the repository.'],
        $run->execution_token,
    ))->toThrow(UnexpectedValueException::class);

    expect($workRequest->refresh()->status)->toBe('running')
        ->and($workRequest->outcome)->toBeNull()
        ->and($workRequest->summary)->toBeNull()
        ->and($run->actions()->where('action', AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED)->count())->toBe(0);
})->with([
    'already implemented' => 'already_implemented',
    'blocked' => 'blocked',
]);

test('an undocumented Task Agent cannot record a blocked outcome', function () {
    [$project, $workRequest] = workflowOutcomeFixture();
    $task = $workRequest->tasks()->create([
        'position' => 1,
        'title' => 'Blocked Task',
        'objective' => 'Blocked Task',
        'implementation_spec' => '',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
        'status' => 'running',
    ]);
    $run = workflowOutcomeRun($project, $task, 'coder');

    expect(fn () => app(WorkflowOutcomeService::class)->record(
        $run,
        $task,
        'blocked',
        'A required external contract is unavailable.',
        [],
        $run->execution_token,
    ))->toThrow(UnexpectedValueException::class);

    expect($task->refresh()->status)->toBe('running')
        ->and($task->outcome)->toBeNull()
        ->and($task->blocked_reason)->toBeNull()
        ->and($run->actions()->where('action', AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED)->count())->toBe(0);
});

test('a non-PM Agent cannot complete a WorkRequest outcome', function () {
    [$project, $workRequest] = workflowOutcomeFixture();
    $run = workflowOutcomeRun($project, $workRequest, 'coder');

    expect(fn () => app(WorkflowOutcomeService::class)->record(
        $run,
        $workRequest,
        'already_implemented',
        'Done.',
        [],
        $run->execution_token,
    ))->toThrow(UnexpectedValueException::class);
});

/**
 * Create a running WorkRequest with every configured Project Agent role.
 *
 * @return array{0: Project, 1: WorkRequest}
 */
function workflowOutcomeFixture(): array
{
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);

    return [$project, $project->workRequests()->create(['prompt' => 'Inspect this request.', 'status' => 'running'])];
}

/**
 * Start one active AgentRun for the requested WorkRequest or Task outcome test.
 */
function workflowOutcomeRun(Project $project, Task|WorkRequest $subject, string $role): AgentRun
{
    $agent = $project->agents()->where('role', $role)->sole();
    $session = app(AgentSessionManager::class)->forSubject($agent, $subject);

    return app(AgentSessionManager::class)->startRun($session, $role, [
        'mode' => 'initial',
        'input' => 'Record the outcome.',
        'sources' => [],
        'agent_snapshot' => [],
        'prompt_snapshot' => [],
        'role' => $role,
    ]);
}
