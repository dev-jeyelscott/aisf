<?php

use App\Console\Commands\DispatchWorkflow;
use App\Jobs\ProcessAgentExecution;
use App\Models\Project;
use App\Models\WorkRequest;
use App\Services\AgentSessionManager;
use App\Services\CandidateAcceptanceGate;
use App\Services\ProjectAgentProvisioner;
use App\Services\TaskCandidateFingerprint;
use App\Services\TaskContextBuilder;
use App\Services\TaskWorkflowService;
use App\Services\TaskWorktreeManager;
use Illuminate\Support\Facades\Queue;
use UnexpectedValueException;

use function Pest\Laravel\mock;

test('a handoff is durable and idempotent for an active configured Agent run', function () {
    [$project, $task, $run] = taskRoleHandoffFixture('coder');
    $task->update(['base_sha' => 'base-sha', 'worktree_path' => '/tmp/aisf-task-worktree']);
    $run->update(['artifacts' => ['validation' => []]]);
    $task->update([
        'candidate_tree_sha' => 'candidate-tree-1',
        'candidate_created_by_run_id' => $run->id,
        'candidate_kind' => 'changes',
    ]);
    mock(TaskWorktreeManager::class)->shouldReceive('assertNoCommitBeforeQa')->once();

    $service = app(TaskWorkflowService::class);
    $first = $service->handoff($run, $task, 'qa', 'ready_for_review', 'handoff-1', [], $run->execution_token);
    $duplicate = $service->handoff($run, $task, 'qa', 'ready_for_review', 'handoff-1', [], $run->execution_token);

    expect($first->is($duplicate))->toBeTrue()
        ->and($task->refresh()->status)->toBe('waiting')
        ->and($task->handoffs()->count())->toBe(1)
        ->and($task->last_handoff['to_role'])->toBe('qa');
});

test('submission provisions all role sessions and dispatches only the Project Manager', function () {
    Queue::fake();
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);

    $this->post(route('projects.work-requests.store', $project), ['prompt' => 'Plan this change.'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('projects.show', $project));

    $workRequest = WorkRequest::query()->sole();
    expect($workRequest->agentSessions()->with('projectAgent')->get()->pluck('projectAgent.role')->sort()->values()->all())
        ->toBe(['coder', 'project_manager', 'qa']);
    Queue::assertPushed(ProcessAgentExecution::class, fn (ProcessAgentExecution $job) => $job->subject->is($workRequest));
});

test('submission rejects a Project without every enabled required role', function () {
    Queue::fake();
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $project->agents()->where('role', 'qa')->update(['enabled' => false]);

    $this->from(route('projects.show', $project))
        ->post(route('projects.work-requests.store', $project), ['prompt' => 'Plan this change.'])
        ->assertSessionHasErrors('prompt')
        ->assertRedirect(route('projects.show', $project));

    expect(WorkRequest::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('a Project Manager plan persists Tasks before creating Coder handoffs', function () {
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $workRequest = $project->workRequests()->create(['prompt' => 'Plan this change.', 'status' => 'pending']);
    $pm = $project->agents()->where('role', 'project_manager')->sole();
    $run = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($pm, $workRequest), 'project_manager', [
        'mode' => 'initial', 'input' => 'Plan.', 'sources' => [], 'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'project_manager',
    ]);
    $task = app(TaskWorkflowService::class)->savePlan($run, $workRequest, [[
        'title' => 'Implement the change',
        'objective' => 'Deliver the requested behavior.',
        'implementation_spec' => 'Follow existing conventions.',
        'acceptance_criteria' => ['The behavior works.'],
        'verification_commands' => ['vendor/bin/pest'],
        'browser_steps' => [],
        'depends_on_position' => null,
    ]], $run->execution_token)[0];
    app(TaskWorkflowService::class)->handoff($run, $task, 'coder', 'implementation_ready', 'pm-coder-1', [], $run->execution_token);

    expect($workRequest->refresh()->status)->toBe('pending')
        ->and($task->refresh()->status)->toBe('waiting')
        ->and($task->last_handoff['reason'])->toBe('implementation_ready')
        ->and($task->handoffs()->count())->toBe(1)
        ->and($task->agentSessions()->with('projectAgent')->get()->pluck('projectAgent.role')->sort()->values()->all())->toBe(['coder', 'qa']);
});

test('only the active Project Manager can save a Task plan', function () {
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $workRequest = $project->workRequests()->create(['prompt' => 'Plan this change.', 'status' => 'running']);
    $agent = $project->agents()->where('role', 'project_manager')->sole();
    $run = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($agent, $workRequest), 'project_manager', [
        'mode' => 'initial',
        'input' => 'Plan the work.',
        'sources' => [],
        'agent_snapshot' => [],
        'prompt_snapshot' => [],
        'role' => 'project_manager',
    ]);

    $tasks = app(TaskWorkflowService::class)->savePlan($run, $workRequest, [[
        'title' => 'Implement the change',
        'objective' => 'Deliver the requested behavior.',
        'implementation_spec' => 'Follow existing conventions.',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
        'depends_on_position' => null,
    ]], $run->execution_token);

    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]->last_handoff)->toBeNull();
});

test('the dispatcher queues only a Task with an accepted PM to Coder handoff', function () {
    Queue::fake();
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $workRequest = $project->workRequests()->create(['prompt' => 'Plan this change.', 'status' => 'running']);
    $pm = $project->agents()->where('role', 'project_manager')->sole();
    $run = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($pm, $workRequest), 'project_manager', [
        'mode' => 'initial',
        'input' => 'Plan the work.',
        'sources' => [],
        'agent_snapshot' => [],
        'prompt_snapshot' => [],
        'role' => 'project_manager',
    ]);
    $task = app(TaskWorkflowService::class)->savePlan($run, $workRequest, [[
        'title' => 'Implement the change',
        'objective' => 'Deliver the requested behavior.',
        'implementation_spec' => 'Follow existing conventions.',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
        'depends_on_position' => null,
    ]], $run->execution_token)[0];
    app(TaskWorkflowService::class)->handoff(
        $run,
        $task,
        'coder',
        'implementation_ready',
        'pm-coder-1',
        [],
        $run->execution_token,
    );
    $task->update(['last_handoff' => null]);
    $workRequest->update(['status' => 'completed']);

    app()->call([app(DispatchWorkflow::class), 'handle']);

    expect($task->refresh()->status)->toBe('running');
    Queue::assertPushed(ProcessAgentExecution::class, fn (ProcessAgentExecution $job) => $job->subject->is($task));
});

test('a stale run cannot hand off a Task', function () {
    [, $task, $run] = taskRoleHandoffFixture('coder');
    $run->update(['status' => 'succeeded']);

    expect(fn () => app(TaskWorkflowService::class)->handoff($run, $task, 'qa', 'ready_for_review', 'handoff-1', [], $run->execution_token))
        ->toThrow(UnexpectedValueException::class);
});

test('a Coder result records structured evidence without committing before QA', function () {
    [, $task, $run] = taskRoleHandoffFixture('coder');
    $task->update(['base_sha' => 'base-sha', 'worktree_path' => '/tmp/aisf-task-worktree']);
    mock(TaskWorktreeManager::class)
        ->shouldReceive('ensureWorktree')->once()
        ->shouldReceive('assertNoCommitBeforeQa')->once()
        ->shouldReceive('changedFiles')->once()->andReturn(['app/Services/Example.php']);
    mock(TaskCandidateFingerprint::class)->shouldReceive('forTask')->once()->andReturn([
        'tree_sha' => 'candidate-tree-1',
        'base_tree_sha' => 'base-tree',
        'kind' => 'changes',
    ]);

    $artifacts = app(TaskWorkflowService::class)->saveResult($run, $task, [
        'summary' => 'Implemented the requested behavior.',
        'validation' => [['command' => 'vendor/bin/pest', 'passed' => true]],
        'assumptions' => ['The existing contract remains stable.'],
        'risks' => [],
    ], $run->execution_token);

    expect($artifacts['changed_files'])->toBe(['app/Services/Example.php'])
        ->and($run->refresh()->artifacts['summary'])->toBe('Implemented the requested behavior.')
        ->and($task->refresh()->candidate_tree_sha)->toBe('candidate-tree-1');
});

test('task context cannot be read by an Agent run from another Project', function () {
    [, $task] = taskRoleHandoffFixture('coder');
    [, , $otherRun] = taskRoleHandoffFixture('qa');

    expect(fn () => app(TaskContextBuilder::class)->forTask($task, $otherRun, $otherRun->execution_token))
        ->toThrow(UnexpectedValueException::class);
});

test('a Coder repair turn receives the newest durable QA findings', function () {
    [$project, $task, $qaRun] = taskRoleHandoffFixture('qa');
    $coder = $project->agents()->where('role', 'coder')->sole();
    $candidateRun = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($coder, $task), 'coder', [
        'mode' => 'initial', 'input' => 'Implement.', 'sources' => [], 'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'coder',
    ]);
    $task->update(['candidate_tree_sha' => 'candidate-tree-1', 'candidate_created_by_run_id' => $candidateRun->id]);
    app(CandidateAcceptanceGate::class)->recordReview(
        $task, $candidateRun, $qaRun, 'candidate-tree-1', 'changes_requested', 'Needs repair.', ['Handle the empty result.'],
    );
    $handoff = app(TaskWorkflowService::class)->handoff(
        $qaRun,
        $task,
        'coder',
        'changes_requested',
        'qa-repair-1',
        ['findings' => ['Handle the empty result.']],
        $qaRun->execution_token,
    );
    $coderRun = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($coder, $task), 'coder', [
        'mode' => 'initial',
        'input' => 'Repair the Task.',
        'sources' => [],
        'agent_snapshot' => [],
        'prompt_snapshot' => [],
        'role' => 'coder',
    ]);

    $context = app(TaskContextBuilder::class)->forTask($task, $coderRun, $coderRun->execution_token);

    expect($handoff->toProjectAgent->role)->toBe('coder')
        ->and($context['latest_handoff']['payload']['findings'])->toBe(['Handle the empty result.']);
});
