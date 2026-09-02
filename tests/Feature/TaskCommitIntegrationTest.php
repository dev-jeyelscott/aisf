<?php

use App\Jobs\DispatchWorkflowForProject;
use App\Jobs\ProcessAgentExecution;
use App\Models\AgentRun;
use App\Models\AgentRunAction;
use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\Task;
use App\Services\AgentCapabilityPreflight;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResult;
use App\Services\AgentSessionManager;
use App\Services\AgentTurnExecution;
use App\Services\AgentTurnReconciler;
use App\Services\CandidateAcceptanceGate;
use App\Services\ProjectAgentProvisioner;
use App\Services\TaskCandidateFingerprint;
use App\Services\TaskCommitIntegrator;
use App\Services\TaskWorkflowService;
use App\Services\TaskWorktreeManager;
use App\Services\WorkflowDispatcher;
use Illuminate\Support\Facades\Queue;
use UnexpectedValueException;

use function Pest\Laravel\mock;

test('QA cannot approve a candidate it produced itself', function () {
    [, $task, $run] = taskRoleHandoffFixture('coder');
    $task->update(['candidate_tree_sha' => 'candidate-1', 'candidate_created_by_run_id' => $run->id]);

    expect(fn () => app(CandidateAcceptanceGate::class)->recordReview($task, $run, $run, 'candidate-1', 'approved', 'Looks fine.', []))
        ->toThrow(UnexpectedValueException::class);
});

test('a review may only evaluate the Task current candidate tree SHA', function () {
    [$project, $task, $coderRun] = taskRoleHandoffFixture('coder');
    $task->update(['candidate_tree_sha' => 'candidate-1', 'candidate_created_by_run_id' => $coderRun->id]);
    $qaAgent = $project->agents()->where('role', 'qa')->sole();
    $qaRun = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($qaAgent, $task), 'qa', [
        'mode' => 'initial', 'input' => 'Review.', 'sources' => [], 'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'qa',
    ]);

    expect(fn () => app(CandidateAcceptanceGate::class)->recordReview($task, $coderRun, $qaRun, 'stale-sha', 'approved', 'Looks fine.', []))
        ->toThrow(UnexpectedValueException::class);
});

test('new QA reviews bind only to the candidate tree and allow clean approvals', function () {
    [$project, $task, $coderRun] = taskRoleHandoffFixture('coder');
    $task->update([
        'candidate_tree_sha' => 'candidate-1',
        'candidate_created_by_run_id' => $coderRun->id,
    ]);
    $qaAgent = $project->agents()->where('role', 'qa')->sole();
    $qaRun = app(AgentSessionManager::class)->startRun(
        app(AgentSessionManager::class)->forSubject($qaAgent, $task),
        'qa',
        [
            'mode' => 'initial',
            'input' => 'Review.',
            'sources' => [],
            'agent_snapshot' => [],
            'prompt_snapshot' => [],
            'role' => 'qa',
        ],
    );

    $review = app(CandidateAcceptanceGate::class)->recordReview(
        $task,
        $coderRun,
        $qaRun,
        'candidate-1',
        'approved',
        'No blocking issues.',
        [],
    );

    expect($review->candidate_tree_sha)->toBe('candidate-1')
        ->and($review->candidate_sha)->toBeNull()
        ->and($review->status)->toBe('approved')
        ->and($review->findings)->toBe([]);
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

test('the current approval gate ignores legacy candidate SHA values', function () {
    [$project, $task, $coderRun] = taskRoleHandoffFixture('coder');
    $task->update([
        'candidate_tree_sha' => 'candidate-1',
        'candidate_created_by_run_id' => $coderRun->id,
    ]);
    $qaAgent = $project->agents()->where('role', 'qa')->sole();
    $qaRun = app(AgentSessionManager::class)->startRun(
        app(AgentSessionManager::class)->forSubject($qaAgent, $task),
        'qa',
        [
            'mode' => 'initial',
            'input' => 'Review legacy evidence.',
            'sources' => [],
            'agent_snapshot' => [],
            'prompt_snapshot' => [],
            'role' => 'qa',
        ],
    );
    $gate = app(CandidateAcceptanceGate::class);

    $task->candidateReviews()->create([
        'candidate_agent_run_id' => $coderRun->id,
        'reviewer_agent_run_id' => $qaRun->id,
        'candidate_sha' => 'candidate-1',
        'candidate_tree_sha' => 'stale-tree',
        'status' => 'approved',
        'summary' => 'Legacy SHA happens to match the current tree text.',
        'findings' => [],
    ]);

    expect($gate->hasCurrentApproval($task->refresh()))->toBeFalse();

    $gate->recordReview(
        $task,
        $coderRun,
        $qaRun,
        'candidate-1',
        'approved',
        'The current tree is approved.',
        [],
    );

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

test('an approved candidate cannot finalize before the finalizer writes its vault note', function () {
    [, $task, $coderRun] = taskWithApprovedCandidate('candidate-1', false);

    mock(TaskCandidateFingerprint::class)
        ->shouldReceive('currentTreeSha')->once()->andReturn('candidate-1');
    $worktreeManager = mock(TaskWorktreeManager::class);
    $worktreeManager->shouldNotReceive('verifyCommitExists');
    $worktreeManager->shouldNotReceive('verifyHeadMatches');
    $worktreeManager->shouldNotReceive('runCiCheck');
    $worktreeManager->shouldNotReceive('pushAndOpenPullRequest');

    expect(fn () => app(TaskCommitIntegrator::class)->finalize(
        $task,
        $coderRun,
        'commit-sha-1',
        'Implemented the change.',
        $coderRun->execution_token,
    ))->toThrow(UnexpectedValueException::class);

    expect($task->refresh()->status)->toBe('running')
        ->and($task->outcome)->toBeNull()
        ->and($task->handoffs()->where('reason', 'ci_failed')->count())->toBe(0)
        ->and($coderRun->actions()->where('action', AgentRunAction::ACTION_CANDIDATE_FINALIZED)->count())->toBe(0)
        ->and($coderRun->actions()->where('action', AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED)->count())->toBe(0);
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

test('CI repair-limit failure remains terminal through reconciliation and cannot requeue the Coder', function () {
    config(['aisf.max_repair_cycles' => 0]);
    Queue::fake([ProcessAgentExecution::class]);
    [$project, $task, $coderRun] = taskWithApprovedCandidate();

    mock(TaskWorktreeManager::class)
        ->shouldReceive('verifyCommitExists')->once()->andReturn('commit-sha-1')
        ->shouldReceive('verifyHeadMatches')->once()
        ->shouldReceive('runCiCheck')->once()->andReturn(['passed' => false, 'output' => 'FAILED']);
    mock(TaskCandidateFingerprint::class)
        ->shouldReceive('currentTreeSha')->once()->andReturn('candidate-1')
        ->shouldReceive('commitTreeSha')->once()->andReturn('candidate-1');

    app(TaskCommitIntegrator::class)->finalize(
        $task,
        $coderRun,
        'commit-sha-1',
        'Implemented the change.',
        $coderRun->execution_token,
    );

    $reconciliation = app(AgentTurnReconciler::class)->reconcile(
        $task->refresh(),
        new AgentTurnExecution(
            $coderRun,
            new AgentHarnessResult(true, '', null, 0),
            'CI repair limit exceeded.',
        ),
    );

    app(WorkflowDispatcher::class)->dispatchForProject($project);

    $freshTask = $task->refresh();
    $freshRun = $coderRun->refresh();
    $terminalActions = $freshRun->actions()
        ->where('action', AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED)
        ->get();

    expect($freshTask->status)->toBe('failed')
        ->and($freshTask->outcome)->toBe('blocked')
        ->and($freshTask->blocked_reason)->toContain('repair cycle limit')
        ->and($freshTask->protocol_recovery_count)->toBe(0)
        ->and($reconciliation->classification)->toBe('terminal')
        ->and($reconciliation->failureClass)->toBe('terminal_blocked')
        ->and($freshRun->reconciliation_status)->toBe('terminal')
        ->and($freshRun->failure_class)->toBe('terminal_blocked')
        ->and($terminalActions)->toHaveCount(1)
        ->and($terminalActions->sole()->resource_type)->toBe(AgentRunAction::RESOURCE_TASK)
        ->and($terminalActions->sole()->resource_id)->toBe($freshTask->id);

    Queue::assertNotPushed(ProcessAgentExecution::class);
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
    markAgentRunDocumented($qaRun);

    app(TaskWorkflowService::class)->handoff($qaRun, $task, 'coder', 'changes_requested', 'qa-repair-1', [], $qaRun->execution_token);

    $reconciliation = app(AgentTurnReconciler::class)->reconcile(
        $task->refresh(),
        new AgentTurnExecution(
            $qaRun,
            new AgentHarnessResult(true, '', null, 0),
            'QA repair limit exceeded.',
        ),
    );

    $fresh = $task->refresh();
    expect($fresh->status)->toBe('failed')
        ->and($fresh->outcome)->toBe('blocked')
        ->and($fresh->blocked_reason)->toContain('repair cycle limit')
        ->and($fresh->protocol_recovery_count)->toBe(0)
        ->and($reconciliation->classification)->toBe('terminal')
        ->and($reconciliation->failureClass)->toBe('terminal_blocked')
        ->and($qaRun->refresh()->actions()
            ->where('action', AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED)
            ->count())->toBe(1);
});

test('completing an Agent execution immediately triggers the next dispatch attempt', function () {
    Queue::fake([DispatchWorkflowForProject::class]);
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $workRequest = $project->workRequests()->create(['prompt' => 'Plan this change.', 'status' => 'pending']);
    $output = json_encode(['status' => 'completed', 'summary' => 'Nothing to do.', 'tasks' => []], JSON_THROW_ON_ERROR);
    mock(AgentHarness::class)->shouldReceive('start')->once()->andReturn(new AgentHarnessResult(true, $output, null, 0));
    app()->instance(AgentCapabilityPreflight::class, new class extends AgentCapabilityPreflight
    {
        public function __construct() {}

        public function verify(ProjectAgent $agent, string $repositoryPath): void {}
    });

    app()->call([new ProcessAgentExecution($workRequest), 'handle']);

    Queue::assertPushed(DispatchWorkflowForProject::class, fn (DispatchWorkflowForProject $job) => $job->projectId === $project->id);
});

/**
 * Build a Task with a current tree-bound QA approval and an active approved Coder finalization run.
 *
 * @return array{0: Project, 1: Task, 2: AgentRun, 3: AgentRun}
 */
function taskWithApprovedCandidate(
    string $candidateTreeSha = 'candidate-1',
    bool $documentFinalizer = true,
): array {
    [$project, $task, $candidateRun] = taskRoleHandoffFixture('coder');
    $task->workRequest()->update(['status' => 'waiting']);
    $task->update([
        'status' => 'running',
        'candidate_tree_sha' => $candidateTreeSha,
        'candidate_created_by_run_id' => $candidateRun->id,
        'candidate_kind' => 'changes',
    ]);
    $qaAgent = $project->agents()->where('role', 'qa')->sole();
    $qaRun = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($qaAgent, $task), 'qa', [
        'mode' => 'initial', 'input' => 'Review.', 'sources' => [], 'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'qa',
    ]);
    app(CandidateAcceptanceGate::class)->recordReview($task, $candidateRun, $qaRun, $candidateTreeSha, 'approved', 'Looks good.', []);
    markAgentRunDocumented($qaRun);
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

    if ($documentFinalizer) {
        markAgentRunDocumented($coderRun);
    }

    $task->refresh()->update(['status' => 'running']);

    return [$project, $task->refresh(), $coderRun, $qaRun];
}
