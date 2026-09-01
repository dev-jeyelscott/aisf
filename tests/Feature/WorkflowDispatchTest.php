<?php

use App\Console\Commands\DispatchWorkflow;
use App\Jobs\ProcessAgentExecution;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkRequest;
use Illuminate\Support\Facades\Queue;

test('the dispatcher claims a pending WorkRequest and dispatches its Agent execution exactly once', function () {
    Queue::fake();
    $project = feature10Project();
    $workRequest = feature10WorkRequest($project, ['status' => 'pending']);

    app()->call([app(DispatchWorkflow::class), 'handle']);
    app()->call([app(DispatchWorkflow::class), 'handle']);

    expect($workRequest->refresh()->status)->toBe('running');
    Queue::assertPushed(ProcessAgentExecution::class, 1);
});

test('the dispatcher prefers an accepted Task handoff over a waiting planned WorkRequest', function () {
    Queue::fake();
    $project = feature10Project();
    $workRequest = feature10WorkRequest($project, ['status' => 'waiting']);
    $task = feature10Task($workRequest, ['status' => 'pending']);

    app()->call([app(DispatchWorkflow::class), 'handle']);

    expect($workRequest->refresh()->status)->toBe('waiting')
        ->and($task->refresh()->status)->toBe('running');
});

test('the dispatcher claims the lowest-position eligible Task once the WorkRequest is no longer active', function () {
    Queue::fake();
    $project = feature10Project();
    $workRequest = feature10WorkRequest($project, ['status' => 'completed']);
    $blockedByDependency = feature10Task($workRequest, ['status' => 'pending', 'position' => 1]);
    $waitingOnDependency = feature10Task($workRequest, [
        'status' => 'pending',
        'position' => 2,
        'depends_on_task_id' => $blockedByDependency->id,
    ]);

    app()->call([app(DispatchWorkflow::class), 'handle']);

    expect($blockedByDependency->refresh()->status)->toBe('running')
        ->and($waitingOnDependency->refresh()->status)->toBe('pending');
    Queue::assertPushed(ProcessAgentExecution::class, 1);
});

test('a Task waiting on an incomplete dependency is skipped until that dependency completes', function () {
    Queue::fake();
    $project = feature10Project();
    $workRequest = feature10WorkRequest($project, ['status' => 'completed']);
    $dependency = feature10Task($workRequest, ['status' => 'running', 'position' => 1]);
    $dependent = feature10Task($workRequest, [
        'status' => 'pending',
        'position' => 2,
        'depends_on_task_id' => $dependency->id,
    ]);

    app()->call([app(DispatchWorkflow::class), 'handle']);

    expect($dependent->refresh()->status)->toBe('pending');
    Queue::assertNotPushed(ProcessAgentExecution::class);
});

test('the dispatcher never starts a second Agent execution while one is already active in the Project', function () {
    Queue::fake();
    $project = feature10Project();
    $workRequest = feature10WorkRequest($project, ['status' => 'completed']);
    feature10Task($workRequest, ['status' => 'running', 'position' => 1]);
    $pending = feature10Task($workRequest, ['status' => 'pending', 'position' => 2]);

    app()->call([app(DispatchWorkflow::class), 'handle']);

    expect($pending->refresh()->status)->toBe('pending');
    Queue::assertNotPushed(ProcessAgentExecution::class);
});

test('the dispatcher skips a disabled Project entirely', function () {
    Queue::fake();
    $project = feature10Project(['enabled' => false]);
    $workRequest = feature10WorkRequest($project, ['status' => 'pending']);

    app()->call([app(DispatchWorkflow::class), 'handle']);

    expect($workRequest->refresh()->status)->toBe('pending');
    Queue::assertNothingPushed();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function feature10Project(array $overrides = []): Project
{
    return Project::factory()->create(array_merge([
        'title' => 'AISF Feature 10 Test Project',
        'path' => sys_get_temp_dir().'/aisf-feature10',
        'enabled' => true,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function feature10WorkRequest(Project $project, array $overrides = []): WorkRequest
{
    return $project->workRequests()->create(array_merge([
        'prompt' => 'Implement the requested change.',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function feature10Task(WorkRequest $workRequest, array $overrides = []): Task
{
    return $workRequest->tasks()->create(array_merge([
        'position' => 1,
        'title' => 'Implement the requested change',
        'objective' => 'Deliver the increment.',
        'implementation_spec' => '',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
        'last_handoff' => ['to_role' => 'coder'],
    ], $overrides));
}
