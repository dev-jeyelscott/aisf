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
use App\Services\TaskCandidateFingerprint;
use App\Services\TaskCommitIntegrator;
use App\Services\TaskWorkflowService;
use App\Services\TaskWorktreeManager;
use Illuminate\Support\Facades\Queue;
use UnexpectedValueException;

use function Pest\Laravel\mock;

test('QA cannot approve a candidate it produced itself', function () {
    [, $task, $run] = taskRoleHandoffFixture('coder');
    $task->update(['candidate_tree_sha' => 'candidate-1', 'candidate_created_by_run_id' => $run->id]);

    expect(fn () => app(CandidateAcceptanceGate::class)->recordReview($task, $run, $run, 'candidate-1', 'approved', 'Looks fine.', []))
        ->toThrow(UnexpectedValueException::class);
});

test('a review may only evaluate the Task current candidate SHA', function () {
    [$project, $task, $coderRun] = taskRoleHandoffFixture('coder');
    $task->update(['candidate_tree_sha' => 'candidate-1', 'candidate_created_by_run_id' => $coderRun->id]);
    $qaAgent = $project->agents()->where('role', 'qa')->sole();
    $qaRun = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($qaAgent, $task), 'qa', [
        'mode' => 'initial', 'input' => 'Review.', 'sources' => [], 'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'qa',
    ]);

    expect(fn () => app(CandidateAcceptanceGate::class)->recordReview($task, $coderRun, $qaRun, 'stale-sha', 'approved', 'Looks fine.', []))
        ->toThrow(UnexpectedValueException::class);
});

test('the current approval gate uses the latest review of the candidate', function () {
    [$project, $task, $coderRun] = taskRoleHandoffFixture('coder');
    $task->update(['candidate_tree_sha' => 'candidate-1', 'candidate_created_by_run_id' => $coderRun->id]);
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

test('changes requested requires at least one non-empty finding', function () {
    [$project, $task, $coderRun] = taskRoleHandoffFixture('coder');
    $task->update(['candidate_tree_sha' => 'candidate-1', 'candidate_created_by_run_id' => $coderRun->id]);
    $qaAgent = $project->agents()->where('role', 'qa')->sole();
    $qaRun = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($qaAgent, $task), 'qa', [
        'mode' => 'initial', 'input' => 'Review.', 'sources' => [], 'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'qa',
    ]);

    expect(fn () => app(CandidateAcceptanceGate::class)->recordReview(
        $task, $coderRun, $qaRun, 'candidate-1', 'changes_requested', 'Needs fixes.', ['  '],
    ))->toThrow(UnexpectedValueException::class);
});

test('a Coder may not finalize a commit without a current QA approval', function () {
    [, $task, $coderRun] = taskRoleHandoffFixture('coder');
    $task->update([
        'status' => 'running',
        'candidate_tree_sha' => 'candidate-1',
        'candidate_created_by_run_id' => $coderRun->id,
        'candidate_kind' => 'changes',
        'last_handoff' => ['id' => 99, 'to_role' => 'coder', 'reason' => 'approved'],
    ]);
    $coderRun->update(['execution_metadata' => ['accepted_handoff_id' => 99, 'execution_mode' => 'approved']]);

    expect(fn () => app(TaskCommitIntegrator::class)->finalize($task, $coderRun, 'deadbeef', 'Implemented the change.', $coderRun->execution_token))
        ->toThrow(UnexpectedValueException::class);
});

test('a verified commit that passes CI completes the Task and opens a pull request', function () {
    [, $task, $coderRun, $qaRun] = taskWithApprovedCandidate();
    mock(TaskWorktreeManager::class)
        ->shouldReceive('verifyCommitExists')->once()->andReturn('commit-sha-1')
        ->shouldReceive('verifyHeadMatches')->once()
        ->shouldReceive('runCiCheck')->once()->andReturn(['passed' => true, 'output' => ''])
        ->shouldReceive('pushAndOpenPullRequest')->once()->andReturn(['commit_sha' => 'commit-sha-1', 'pull_request_url' => 'https://github.com/org/repo/pull/1']);
    mock(TaskCandidateFingerprint::class)
        ->shouldReceive('currentTreeSha')->once()->andReturn('candidate-1')
        ->shouldReceive('commitTreeSha')->once()->andReturn('candidate-1');

    app(TaskCommitIntegrator::class)->finalize($task, $coderRun, 'commit-sha-1', 'Implemented the change.', $coderRun->execution_token);

    $fresh = $task->refresh();
    expect($fresh->status)->toBe('completed')
        ->and($fresh->commit_sha)->toBe('commit-sha-1')
        ->and($fresh->outcome)->toBe('implemented')
        ->and($fresh->pull_request_url)->toBe('https://github.com/org/repo/pull/1')
        ->and($fresh->last_handoff)->toBeNull();
});

test('code changes after QA approval are rejected before finalization', function () {
    [, $task, $coderRun] = taskWithApprovedCandidate();
    mock(TaskCandidateFingerprint::class)
        ->shouldReceive('currentTreeSha')->once()->andReturn('different-tree');

    expect(fn () => app(TaskCommitIntegrator::class)->finalize(
        $task, $coderRun, 'commit-sha-1', 'Implemented the change.', $coderRun->execution_token,
    ))->toThrow(UnexpectedValueException::class);

    expect($task->refresh()->status)->toBe('running');
});

test('an approved no-change candidate completes without a commit or pull request', function () {
    [, $task, $coderRun] = taskWithApprovedCandidate();
    $task->update(['candidate_kind' => 'no_change']);
    mock(TaskCandidateFingerprint::class)
        ->shouldReceive('currentTreeSha')->once()->andReturn('candidate-1')
        ->shouldReceive('forTask')->once()->andReturn([
            'tree_sha' => 'candidate-1', 'base_tree_sha' => 'candidate-1', 'kind' => 'no_change',
        ]);
    mock(TaskWorktreeManager::class)
        ->shouldReceive('runCiCheck')->once()->andReturn(['passed' => true, 'output' => '']);

    app(TaskCommitIntegrator::class)->finalize(
        $task, $coderRun, null, 'No repository change is required.', $coderRun->execution_token,
    );

    expect($task->refresh()->status)->toBe('completed')
        ->and($task->outcome)->toBe('no_change')
        ->and($task->commit_sha)->toBeNull()
        ->and($task->pull_request_url)->toBeNull();
});

test('a commit that fails CI hands the Task back to the Coder instead of opening a pull request', function () {
    [, $task, $coderRun] = taskWithApprovedCandidate();
    mock(TaskWorktreeManager::class)
        ->shouldReceive('verifyCommitExists')->once()->andReturn('commit-sha-1')
        ->shouldReceive('verifyHeadMatches')->once()
        ->shouldReceive('runCiCheck')->once()->andReturn(['passed' => false, 'output' => 'FAILED: some test'])
        ->shouldReceive('resetToBasePreservingChanges')->once();
    mock(TaskCandidateFingerprint::class)
        ->shouldReceive('currentTreeSha')->once()->andReturn('candidate-1')
        ->shouldReceive('commitTreeSha')->once()->andReturn('candidate-1');

    app(TaskCommitIntegrator::class)->finalize($task, $coderRun, 'commit-sha-1', 'Implemented the change.', $coderRun->execution_token);

    $fresh = $task->refresh();
    expect($fresh->status)->toBe('waiting')
        ->and($fresh->last_handoff['to_role'])->toBe('coder')
        ->and($fresh->last_handoff['reason'])->toBe('ci_failed')
        ->and($fresh->handoffs()->where('reason', 'ci_failed')->count())->toBe(1)
        ->and($fresh->pull_request_url)->toBeNull();
});

test('CI failure durably fails the Task once the repair cycle limit is exceeded', function () {
    config(['aisf.max_repair_cycles' => 0]);
    [, $task, $coderRun] = taskWithApprovedCandidate();
    mock(TaskWorktreeManager::class)
        ->shouldReceive('verifyCommitExists')->once()->andReturn('commit-sha-1')
        ->shouldReceive('verifyHeadMatches')->once()
        ->shouldReceive('runCiCheck')->once()->andReturn(['passed' => false, 'output' => 'FAILED']);
    mock(TaskCandidateFingerprint::class)
        ->shouldReceive('currentTreeSha')->once()->andReturn('candidate-1')
        ->shouldReceive('commitTreeSha')->once()->andReturn('candidate-1');

    app(TaskCommitIntegrator::class)->finalize($task, $coderRun, 'commit-sha-1', 'Implemented the change.', $coderRun->execution_token);

    $fresh = $task->refresh();
    expect($fresh->status)->toBe('failed')
        ->and($fresh->outcome)->toBe('blocked')
        ->and($fresh->blocked_reason)->toContain('repair cycle limit');
});

test('QA repair handoffs durably fail the Task once the repair cycle limit is exceeded', function () {
    config(['aisf.max_repair_cycles' => 1]);
    [$project, $task, $coderRun] = taskRoleHandoffFixture('coder');
    $task->update(['candidate_tree_sha' => 'candidate-1', 'candidate_created_by_run_id' => $coderRun->id]);
    $qaAgent = $project->agents()->where('role', 'qa')->sole();
    $qaRun = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($qaAgent, $task), 'qa', [
        'mode' => 'initial', 'input' => 'Review.', 'sources' => [], 'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'qa',
    ]);
    app(CandidateAcceptanceGate::class)->recordReview($task, $coderRun, $qaRun, 'candidate-1', 'changes_requested', 'Needs fixes.', ['Handle nulls.']);

    app(TaskWorkflowService::class)->handoff($qaRun, $task, 'coder', 'changes_requested', 'qa-repair-1', [], $qaRun->execution_token);

    $fresh = $task->refresh();
    expect($fresh->status)->toBe('failed')
        ->and($fresh->outcome)->toBe('blocked')
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
    [$project, $task, $candidateRun] = taskRoleHandoffFixture('coder');
    $task->update([
        'status' => 'running',
        'candidate_tree_sha' => $candidateSha,
        'candidate_created_by_run_id' => $candidateRun->id,
        'candidate_kind' => 'changes',
    ]);
    $qaAgent = $project->agents()->where('role', 'qa')->sole();
    $qaRun = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($qaAgent, $task), 'qa', [
        'mode' => 'initial', 'input' => 'Review.', 'sources' => [], 'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'qa',
    ]);
    app(CandidateAcceptanceGate::class)->recordReview($task, $candidateRun, $qaRun, $candidateSha, 'approved', 'Looks good.', []);
    $handoff = app(TaskWorkflowService::class)->handoff(
        $qaRun,
        $task,
        'coder',
        'approved',
        'qa-approved-'.$qaRun->id,
        [],
        $qaRun->execution_token,
    );
    $candidateRun->update(['status' => 'succeeded']);
    $qaRun->update(['status' => 'succeeded']);
    $coderAgent = $project->agents()->where('role', 'coder')->sole();
    $coderRun = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($coderAgent, $task), 'coder', [
        'mode' => 'initial', 'input' => 'Finalize.', 'sources' => [], 'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'coder',
    ]);
    $coderRun->update(['execution_metadata' => ['accepted_handoff_id' => $handoff->id, 'execution_mode' => 'approved']]);
    $task->refresh()->update(['status' => 'running']);

    return [$project, $task->refresh(), $coderRun, $qaRun];
}
