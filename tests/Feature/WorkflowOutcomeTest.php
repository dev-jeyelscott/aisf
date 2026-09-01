<?php

use App\Models\AgentRun;
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

/** @return array{0: Project, 1: WorkRequest} */
function workflowOutcomeFixture(): array
{
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);

    return [$project, $project->workRequests()->create(['prompt' => 'Inspect this request.', 'status' => 'running'])];
}

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
