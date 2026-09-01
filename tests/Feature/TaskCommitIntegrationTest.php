<?php

use App\Jobs\DispatchWorkflowForProject;
use App\Jobs\ProcessAgentExecution;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Task;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResult;
use App\Services\AgentSessionManager;
use App\Services\CandidateAcceptanceGate;
use App\Services\ProjectAgentProvisioner;
use App\Services\TaskCommitIntegrator;
use App\Services\TaskWorkflowService;
use App\Services\TaskWorktreeManager;
use Illuminate\Support\Facades\Queue;
use UnexpectedValueException;

use function Pest\Laravel\mock;

test('QA cannot approve a candidate it produced itself', function () {
    [, $task, $run] = taskRoleHandoffFixture('coder');
    $task->update(['candidate_sha' => 'candidate-1']);

    expect(fn () => app(CandidateAcceptanceGate::class)->recordReview($task, $run, $run, 'candidate-1', 'approved', 'Looks fine.', []))
        ->toThrow(UnexpectedValueException::class);
});

test('a review may only evaluate the Task current candidate SHA', function () {
    [$project, $task, $coderRun] = taskRoleHandoffFixture('coder');
    $task->update(['candidate_sha' => 'candidate-1']);
    $qaAgent = $project->agents()->where('role', 'qa')->sole();
    $qaRun = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($qaAgent, $task), 'qa', [
        'mode' => 'initial', 'input' => 'Review.', 'sources' => [], 'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'qa',
    ]);

    expect(fn () => app(CandidateAcceptanceGate::class)->recordReview($task, $coderRun, $qaRun, 'stale-sha', 'approved', 'Looks fine.', []))
        ->toThrow(UnexpectedValueException::class);
});

test('the current approval gate uses the latest review of the candidate', function () {
    [$project, $task, $coderRun] = taskRoleHandoffFixture('coder');
    $task->update(['candidate_sha' => 'candidate-1']);
    $qaAgent = $project->agents()->where('role', 'qa')->sole();
    $qaSession = app(AgentSessionManager::class)->forSubject($qaAgent, $task);
    $gate = app(CandidateAcceptanceGate::class);

    $firstReview = app(AgentSessionManager::class)->startRun($qaSession, 'qa', [
        'mode' => 'initial', 'input' => 'Review.', 'sources' => [], 'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'qa',
    ]);
    $gate->recordReview($task, $coderRun, $firstReview, 'candidate-1', 'changes_requested', 'Needs fixes.', ['Handle nulls.']);
    expect($gate->hasCurrentApproval($task->refresh()))->toBeFalse();

    $secondReview = app(AgentSessionManager::class)->startRun($qaSession, 'qa', [
        'mode' => 'initial', 'input' => 'Review again.', 'sources' => [], 'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'qa',
    ]);
    $gate->recordReview($task, $coderRun, $secondReview, 'candidate-1', 'approved', 'Looks good now.', []);
    expect($gate->hasCurrentApproval($task->refresh()))->toBeTrue();
});

test('a Coder may not finalize a commit without a current QA approval', function () {
    [, $task, $coderRun] = taskRoleHandoffFixture('coder');
    $task->update(['candidate_sha' => 'candidate-1']);

    expect(fn () => app(TaskCommitIntegrator::class)->integrate($task, $coderRun, 'deadbeef', 'Implemented the change.'))
        ->toThrow(UnexpectedValueException::class);
});

test('a verified commit that passes CI completes the Task and opens a pull request', function () {
    [, $task, $coderRun, $qaRun] = taskWithApprovedCandidate();
    mock(TaskWorktreeManager::class)
        ->shouldReceive('verifyCommitExists')->once()->andReturn('commit-sha-1')
        ->shouldReceive('verifyHeadMatches')->once()
        ->shouldReceive('runCiCheck')->once()->andReturn(['passed' => true, 'output' => ''])
        ->shouldReceive('pushAndOpenPullRequest')->once()->andReturn(['commit_sha' => 'commit-sha-1', 'pull_request_url' => 'https://github.com/org/repo/pull/1']);

    app(TaskCommitIntegrator::class)->integrate($task, $coderRun, 'commit-sha-1', 'Implemented the change.');

    $fresh = $task->refresh();
    expect($fresh->status)->toBe('completed')
        ->and($fresh->commit_sha)->toBe('commit-sha-1')
        ->and($fresh->pull_request_url)->toBe('https://github.com/org/repo/pull/1')
        ->and($fresh->last_handoff)->toBeNull();
});

test('a commit that fails CI hands the Task back to the Coder instead of opening a pull request', function () {
    [, $task, $coderRun] = taskWithApprovedCandidate();
    mock(TaskWorktreeManager::class)
        ->shouldReceive('verifyCommitExists')->once()->andReturn('commit-sha-1')
        ->shouldReceive('verifyHeadMatches')->once()
        ->shouldReceive('runCiCheck')->once()->andReturn(['passed' => false, 'output' => 'FAILED: some test']);

    app(TaskCommitIntegrator::class)->integrate($task, $coderRun, 'commit-sha-1', 'Implemented the change.');

    $fresh = $task->refresh();
    expect($fresh->status)->toBe('waiting')
        ->and($fresh->last_handoff['to_role'])->toBe('coder')
        ->and($fresh->last_handoff['reason'])->toBe('ci_failed')
        ->and($fresh->handoffs()->where('reason', 'ci_failed')->count())->toBe(1)
        ->and($fresh->pull_request_url)->toBeNull();
});

test('repeated CI failures durably fail the Task once the repair cycle limit is exceeded', function () {
    config(['aisf.max_repair_cycles' => 1]);
    [, $task, $coderRun] = taskWithApprovedCandidate();
    mock(TaskWorktreeManager::class)
        ->shouldReceive('verifyCommitExists')->twice()->andReturn('commit-sha-1')
        ->shouldReceive('verifyHeadMatches')->twice()
        ->shouldReceive('runCiCheck')->twice()->andReturn(['passed' => false, 'output' => 'FAILED']);

    app(TaskCommitIntegrator::class)->integrate($task, $coderRun, 'commit-sha-1', 'Implemented the change.');
    $task->refresh()->update(['status' => 'running']);
    app(TaskCommitIntegrator::class)->integrate($task->refresh(), $coderRun, 'commit-sha-1', 'Implemented the change.');

    $fresh = $task->refresh();
    expect($fresh->status)->toBe('failed')
        ->and($fresh->blocked_reason)->toContain('repair cycle limit');
});

test('QA repair handoffs durably fail the Task once the repair cycle limit is exceeded', function () {
    config(['aisf.max_repair_cycles' => 1]);
    [$project, $task, $coderRun] = taskRoleHandoffFixture('coder');
    $task->update(['candidate_sha' => 'candidate-1']);
    $qaAgent = $project->agents()->where('role', 'qa')->sole();
    $qaRun = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($qaAgent, $task), 'qa', [
        'mode' => 'initial', 'input' => 'Review.', 'sources' => [], 'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'qa',
    ]);
    app(CandidateAcceptanceGate::class)->recordReview($task, $coderRun, $qaRun, 'candidate-1', 'changes_requested', 'Needs fixes.', ['Handle nulls.']);

    app(TaskWorkflowService::class)->handoff($qaRun, $task, 'coder', 'changes_requested', 'qa-repair-1', [], $qaRun->execution_token);

    $fresh = $task->refresh();
    expect($fresh->status)->toBe('failed')
        ->and($fresh->blocked_reason)->toContain('repair cycle limit');
});

test('completing an Agent execution immediately triggers the next dispatch attempt', function () {
    Queue::fake([DispatchWorkflowForProject::class]);
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $workRequest = $project->workRequests()->create(['prompt' => 'Plan this change.', 'status' => 'pending']);
    $output = json_encode(['status' => 'completed', 'summary' => 'Nothing to do.', 'tasks' => []], JSON_THROW_ON_ERROR);
    mock(AgentHarness::class)->shouldReceive('start')->once()->andReturn(new AgentHarnessResult(true, $output, null, 0));

    app()->call([new ProcessAgentExecution($workRequest), 'handle']);

    Queue::assertPushed(DispatchWorkflowForProject::class, fn (DispatchWorkflowForProject $job) => $job->projectId === $project->id);
});

/** @return array{0: Project, 1: Task, 2: AgentRun, 3: AgentRun} */
function taskWithApprovedCandidate(string $candidateSha = 'candidate-1'): array
{
    [$project, $task, $coderRun] = taskRoleHandoffFixture('coder');
    $task->update(['status' => 'running', 'candidate_sha' => $candidateSha]);
    $qaAgent = $project->agents()->where('role', 'qa')->sole();
    $qaRun = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($qaAgent, $task), 'qa', [
        'mode' => 'initial', 'input' => 'Review.', 'sources' => [], 'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'qa',
    ]);
    app(CandidateAcceptanceGate::class)->recordReview($task, $coderRun, $qaRun, $candidateSha, 'approved', 'Looks good.', []);

    return [$project, $task->refresh(), $coderRun, $qaRun];
}
