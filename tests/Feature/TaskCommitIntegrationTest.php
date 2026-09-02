<?php

use App\Jobs\DispatchWorkflowForProject;
use App\Jobs\ProcessAgentExecution;
use App\Models\AgentRun;
use App\Models\AgentRunAction;
use App\Models\Project;
use App\Models\ProjectVerificationRun;
use App\Models\Task;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResult;
use App\Services\AgentSessionManager;
use App\Services\AgentTurnExecution;
use App\Services\AgentTurnReconciler;
use App\Services\CandidateAcceptanceGate;
use App\Services\ProjectAgentProvisioner;
use App\Services\ProjectVerificationService;
use App\Services\RepairCycleGuard;
use App\Services\TaskCandidateFingerprint;
use App\Services\TaskCommitIntegrator;
use App\Services\TaskWorkflowService;
use App\Services\TaskWorktreeManager;
use App\Services\WorkflowDispatcher;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use UnexpectedValueException;

use function Pest\Laravel\mock;

test('QA cannot approve a candidate it produced itself', function () {
    [, $task, $run] = taskRoleHandoffFixture('coder');

    $task->update([
        'candidate_tree_sha' => 'candidate-1',
        'candidate_created_by_run_id' => $run->id,
    ]);

    expect(fn () => app(CandidateAcceptanceGate::class)->recordReview(
        $task,
        $run,
        $run,
        'candidate-1',
        'approved',
        'Looks fine.',
        [],
    ))->toThrow(UnexpectedValueException::class);
});

test('a review may only evaluate the Task current candidate tree SHA', function () {
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

    expect(fn () => app(CandidateAcceptanceGate::class)->recordReview(
        $task,
        $coderRun,
        $qaRun,
        'stale-sha',
        'approved',
        'Looks fine.',
        [],
    ))->toThrow(UnexpectedValueException::class);
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

    expect($review->candidate_tree_sha)->toBe('candidate-1');
    expect($review->candidate_sha)->toBeNull();
    expect($review->status)->toBe('approved');
    expect($review->findings)->toBe([]);
});

test('the current approval gate uses the latest review of the candidate', function () {
    [$project, $task, $coderRun] = taskRoleHandoffFixture('coder');

    $task->update([
        'candidate_tree_sha' => 'candidate-1',
        'candidate_created_by_run_id' => $coderRun->id,
    ]);

    $qaAgent = $project->agents()->where('role', 'qa')->sole();
    $qaSession = app(AgentSessionManager::class)->forSubject($qaAgent, $task);
    $gate = app(CandidateAcceptanceGate::class);

    $firstReview = app(AgentSessionManager::class)->startRun(
        $qaSession,
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

    $gate->recordReview(
        $task,
        $coderRun,
        $firstReview,
        'candidate-1',
        'changes_requested',
        'Needs fixes.',
        ['Handle nulls.'],
    );

    expect($gate->hasCurrentApproval($task->refresh()))->toBeFalse();

    $secondReview = app(AgentSessionManager::class)->startRun(
        $qaSession,
        'qa',
        [
            'mode' => 'initial',
            'input' => 'Review again.',
            'sources' => [],
            'agent_snapshot' => [],
            'prompt_snapshot' => [],
            'role' => 'qa',
        ],
    );

    $gate->recordReview(
        $task,
        $coderRun,
        $secondReview,
        'candidate-1',
        'approved',
        'Looks good now.',
        [],
    );

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

    expect(fn () => app(CandidateAcceptanceGate::class)->recordReview(
        $task,
        $coderRun,
        $qaRun,
        'candidate-1',
        'changes_requested',
        'Needs fixes.',
        ['  '],
    ))->toThrow(UnexpectedValueException::class);
});

test('a Coder may not finalize a commit without a current QA approval', function () {
    [, $task, $coderRun] = taskRoleHandoffFixture('coder');

    $task->update([
        'status' => 'running',
        'candidate_tree_sha' => 'candidate-1',
        'candidate_created_by_run_id' => $coderRun->id,
        'candidate_kind' => 'changes',
        'last_handoff' => [
            'id' => 99,
            'to_role' => 'coder',
            'reason' => 'approved',
        ],
    ]);

    $coderRun->update([
        'execution_metadata' => [
            'accepted_handoff_id' => 99,
            'execution_mode' => 'approved',
        ],
    ]);

    expect(fn () => app(TaskCommitIntegrator::class)->finalize(
        $task,
        $coderRun,
        'deadbeef',
        'Implemented the change.',
        $coderRun->execution_token,
    ))->toThrow(UnexpectedValueException::class);
});

test('an approved candidate cannot finalize before the finalizer writes its vault note', function () {
    [, $task, $coderRun] = taskWithApprovedCandidate('candidate-1', false);

    mock(TaskCandidateFingerprint::class)
        ->shouldReceive('currentTreeSha')
        ->once()
        ->andReturn('candidate-1');

    mock(ProjectVerificationService::class)
        ->shouldNotReceive('run');

    $worktreeManager = mock(TaskWorktreeManager::class);

    $worktreeManager->shouldNotReceive('verifyCommitExists');
    $worktreeManager->shouldNotReceive('verifyHeadMatches');
    $worktreeManager->shouldNotReceive('pushAndOpenPullRequest');

    expect(fn () => app(TaskCommitIntegrator::class)->finalize(
        $task,
        $coderRun,
        'commit-sha-1',
        'Implemented the change.',
        $coderRun->execution_token,
    ))->toThrow(UnexpectedValueException::class);

    expect($task->refresh()->status)->toBe('running');
    expect($task->outcome)->toBeNull();
    expect($task->handoffs()->where('reason', 'ci_failed')->count())->toBe(0);
    expect(
        $coderRun->actions()
            ->where('action', AgentRunAction::ACTION_CANDIDATE_FINALIZED)
            ->count(),
    )->toBe(0);
});

test('an exact-tree successful host verification finalizes without executing a second CI authority', function () {
    [, $task, $coderRun, $qaRun] = taskWithApprovedCandidate();

    recordTaskVerification(
        $task,
        $qaRun,
        ProjectVerificationRun::STATUS_PASSED,
    );

    mock(ProjectVerificationService::class)
        ->shouldNotReceive('run');

    $worktreeManager = mock(TaskWorktreeManager::class);

    $worktreeManager
        ->shouldReceive('verifyCommitExists')
        ->once()
        ->andReturn('commit-sha-1');

    $worktreeManager
        ->shouldReceive('verifyHeadMatches')
        ->once();

    $worktreeManager
        ->shouldReceive('pushAndOpenPullRequest')
        ->once()
        ->andReturn([
            'commit_sha' => 'commit-sha-1',
            'pull_request_url' => 'https://github.com/org/repo/pull/1',
        ]);

    $fingerprint = mock(TaskCandidateFingerprint::class);

    $fingerprint
        ->shouldReceive('currentTreeSha')
        ->twice()
        ->andReturn('candidate-1');

    $fingerprint
        ->shouldReceive('commitTreeSha')
        ->once()
        ->andReturn('candidate-1');

    app(TaskCommitIntegrator::class)->finalize(
        $task,
        $coderRun,
        'commit-sha-1',
        'Implemented the change.',
        $coderRun->execution_token,
    );

    $fresh = $task->refresh();

    expect($fresh->status)->toBe('completed');
    expect($fresh->commit_sha)->toBe('commit-sha-1');
    expect($fresh->outcome)->toBe('implemented');
    expect($fresh->pull_request_url)
        ->toBe('https://github.com/org/repo/pull/1');
    expect($fresh->last_handoff)->toBeNull();
});

test('code changes after QA approval are rejected before finalization', function () {
    [, $task, $coderRun] = taskWithApprovedCandidate();

    mock(TaskCandidateFingerprint::class)
        ->shouldReceive('currentTreeSha')
        ->once()
        ->andReturn('different-tree');

    mock(ProjectVerificationService::class)
        ->shouldNotReceive('run');

    expect(fn () => app(TaskCommitIntegrator::class)->finalize(
        $task,
        $coderRun,
        'commit-sha-1',
        'Implemented the change.',
        $coderRun->execution_token,
    ))->toThrow(UnexpectedValueException::class);

    expect($task->refresh()->status)->toBe('running');
});

test('finalization cannot reuse verification for another Task profile or candidate tree', function () {
    [, $task, $coderRun, $qaRun] = taskWithApprovedCandidate();

    recordTaskVerification(
        $task,
        $qaRun,
        ProjectVerificationRun::STATUS_PASSED,
        'candidate-old',
    );

    recordTaskVerification(
        $task,
        $qaRun,
        ProjectVerificationRun::STATUS_PASSED,
        'candidate-1',
        'smoke',
    );

    $otherTask = $task->workRequest->tasks()->create([
        'position' => 2,
        'title' => 'Other Task',
        'objective' => 'Provide mismatched verification evidence.',
        'implementation_spec' => 'No implementation.',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
        'status' => 'running',
        'candidate_tree_sha' => 'candidate-1',
        'candidate_kind' => 'changes',
    ]);

    recordTaskVerification(
        $otherTask,
        $qaRun,
        ProjectVerificationRun::STATUS_PASSED,
        'candidate-1',
    );

    $staleResult = transientTaskVerification(
        $task,
        ProjectVerificationRun::STATUS_STALE_CANDIDATE,
    );

    mock(ProjectVerificationService::class)
        ->shouldReceive('run')
        ->once()
        ->andReturn($staleResult);

    $worktreeManager = mock(TaskWorktreeManager::class);

    $worktreeManager
        ->shouldReceive('verifyCommitExists')
        ->once()
        ->andReturn('commit-sha-1');

    $worktreeManager
        ->shouldReceive('verifyHeadMatches')
        ->once();

    $worktreeManager->shouldNotReceive('pushAndOpenPullRequest');
    $worktreeManager->shouldNotReceive('resetToBasePreservingChanges');

    $fingerprint = mock(TaskCandidateFingerprint::class);

    $fingerprint
        ->shouldReceive('currentTreeSha')
        ->once()
        ->andReturn('candidate-1');

    $fingerprint
        ->shouldReceive('commitTreeSha')
        ->once()
        ->andReturn('candidate-1');

    expect(fn () => app(TaskCommitIntegrator::class)->finalize(
        $task,
        $coderRun,
        'commit-sha-1',
        'Implemented the change.',
        $coderRun->execution_token,
    ))->toThrow(
        UnexpectedValueException::class,
        'Authoritative CI verification became stale',
    );

    expect($task->refresh()->status)->toBe('running');
    expect($task->pull_request_url)->toBeNull();
    expect($task->handoffs()->where('reason', 'ci_failed')->count())->toBe(0);
});

test('an approved no-change candidate uses the same verification authority and completes without a commit', function () {
    [, $task, $coderRun] = taskWithApprovedCandidate();

    $task->update([
        'candidate_kind' => 'no_change',
    ]);

    $verification = transientTaskVerification(
        $task,
        ProjectVerificationRun::STATUS_PASSED,
    );

    mock(ProjectVerificationService::class)
        ->shouldReceive('run')
        ->once()
        ->andReturn($verification);

    $fingerprint = mock(TaskCandidateFingerprint::class);

    $fingerprint
        ->shouldReceive('currentTreeSha')
        ->twice()
        ->andReturn('candidate-1');

    $fingerprint
        ->shouldReceive('forTask')
        ->once()
        ->andReturn([
            'tree_sha' => 'candidate-1',
            'base_tree_sha' => 'candidate-1',
            'kind' => 'no_change',
        ]);

    $worktreeManager = mock(TaskWorktreeManager::class);

    $worktreeManager->shouldNotReceive('verifyCommitExists');
    $worktreeManager->shouldNotReceive('pushAndOpenPullRequest');

    app(TaskCommitIntegrator::class)->finalize(
        $task,
        $coderRun,
        null,
        'No repository change is required.',
        $coderRun->execution_token,
    );

    $fresh = $task->refresh();

    expect($fresh->status)->toBe('completed');
    expect($fresh->outcome)->toBe('no_change');
    expect($fresh->commit_sha)->toBeNull();
    expect($fresh->pull_request_url)->toBeNull();
});

test('a genuine authoritative CI failure creates the bounded Coder repair path', function () {
    [, $task, $coderRun, $qaRun] = taskWithApprovedCandidate();

    recordTaskVerification(
        $task,
        $qaRun,
        ProjectVerificationRun::STATUS_FAILED,
    );

    mock(ProjectVerificationService::class)
        ->shouldNotReceive('run');

    $worktreeManager = mock(TaskWorktreeManager::class);

    $worktreeManager
        ->shouldReceive('verifyCommitExists')
        ->once()
        ->andReturn('commit-sha-1');

    $worktreeManager
        ->shouldReceive('verifyHeadMatches')
        ->once();

    $worktreeManager
        ->shouldReceive('resetToBasePreservingChanges')
        ->once();

    $worktreeManager->shouldNotReceive('pushAndOpenPullRequest');

    $fingerprint = mock(TaskCandidateFingerprint::class);

    $fingerprint
        ->shouldReceive('currentTreeSha')
        ->once()
        ->andReturn('candidate-1');

    $fingerprint
        ->shouldReceive('commitTreeSha')
        ->once()
        ->andReturn('candidate-1');

    app(TaskCommitIntegrator::class)->finalize(
        $task,
        $coderRun,
        'commit-sha-1',
        'Implemented the change.',
        $coderRun->execution_token,
    );

    $fresh = $task->refresh();

    expect($fresh->status)->toBe('waiting');
    expect($fresh->last_handoff['to_role'])->toBe('coder');
    expect($fresh->last_handoff['reason'])->toBe('ci_failed');
    expect($fresh->handoffs()->where('reason', 'ci_failed')->count())->toBe(1);
    expect($fresh->pull_request_url)->toBeNull();
});

test('environment-only verification failure does not create a false Coder repair cycle', function () {
    [, $task, $coderRun] = taskWithApprovedCandidate();

    $verification = transientTaskVerification(
        $task,
        ProjectVerificationRun::STATUS_ENVIRONMENT_UNAVAILABLE,
    );

    mock(ProjectVerificationService::class)
        ->shouldReceive('run')
        ->once()
        ->andReturn($verification);

    $worktreeManager = mock(TaskWorktreeManager::class);

    $worktreeManager
        ->shouldReceive('verifyCommitExists')
        ->once()
        ->andReturn('commit-sha-1');

    $worktreeManager
        ->shouldReceive('verifyHeadMatches')
        ->once();

    $worktreeManager->shouldNotReceive('resetToBasePreservingChanges');
    $worktreeManager->shouldNotReceive('pushAndOpenPullRequest');

    $fingerprint = mock(TaskCandidateFingerprint::class);

    $fingerprint
        ->shouldReceive('currentTreeSha')
        ->once()
        ->andReturn('candidate-1');

    $fingerprint
        ->shouldReceive('commitTreeSha')
        ->once()
        ->andReturn('candidate-1');

    expect(fn () => app(TaskCommitIntegrator::class)->finalize(
        $task,
        $coderRun,
        'commit-sha-1',
        'Implemented the change.',
        $coderRun->execution_token,
    ))->toThrow(
        UnexpectedValueException::class,
        'verification environment is unavailable',
    );

    expect($task->refresh()->status)->toBe('running');
    expect($task->handoffs()->where('reason', 'ci_failed')->count())->toBe(0);
    expect(app(RepairCycleGuard::class)->repairCycleCount($task->refresh()))
        ->toBe(0);
});

test('CI repair-limit failure persists its final handoff and remains terminal through reconciliation', function () {
    config([
        'aisf.max_repair_cycles' => 1,
    ]);

    Queue::fake([
        ProcessAgentExecution::class,
    ]);

    [$project, $task, $coderRun, $qaRun] = taskWithApprovedCandidate();

    recordTaskVerification(
        $task,
        $qaRun,
        ProjectVerificationRun::STATUS_FAILED,
    );

    mock(ProjectVerificationService::class)
        ->shouldNotReceive('run');

    $worktreeManager = mock(TaskWorktreeManager::class);

    $worktreeManager
        ->shouldReceive('verifyCommitExists')
        ->once()
        ->andReturn('commit-sha-1');

    $worktreeManager
        ->shouldReceive('verifyHeadMatches')
        ->once();

    $worktreeManager
        ->shouldReceive('resetToBasePreservingChanges')
        ->once();

    $fingerprint = mock(TaskCandidateFingerprint::class);

    $fingerprint
        ->shouldReceive('currentTreeSha')
        ->once()
        ->andReturn('candidate-1');

    $fingerprint
        ->shouldReceive('commitTreeSha')
        ->once()
        ->andReturn('candidate-1');

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
            'CI repair limit reached.',
        ),
    );

    app(WorkflowDispatcher::class)->dispatchForProject($project);

    $freshTask = $task->refresh();
    $freshRun = $coderRun->refresh();

    expect($freshTask->status)->toBe('failed');
    expect($freshTask->outcome)->toBe('blocked');
    expect($freshTask->blocked_reason)->toContain('repair cycle limit');
    expect($freshTask->last_handoff['reason'])->toBe('ci_failed');
    expect($freshTask->handoffs()->where('reason', 'ci_failed')->count())
        ->toBe(1);
    expect(app(RepairCycleGuard::class)->repairCycleCount($freshTask))
        ->toBe(1);
    expect($freshTask->protocol_recovery_count)->toBe(0);
    expect($reconciliation->classification)->toBe('terminal');
    expect($reconciliation->failureClass)->toBe('terminal_blocked');
    expect($freshRun->reconciliation_status)->toBe('terminal');
    expect($freshRun->failure_class)->toBe('terminal_blocked');

    Queue::assertNotPushed(ProcessAgentExecution::class);
});

test('QA repair handoffs durably fail the Task once the repair cycle limit is reached', function () {
    config([
        'aisf.max_repair_cycles' => 1,
    ]);

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

    app(CandidateAcceptanceGate::class)->recordReview(
        $task,
        $coderRun,
        $qaRun,
        'candidate-1',
        'changes_requested',
        'Needs fixes.',
        ['Handle nulls.'],
    );

    markAgentRunDocumented($qaRun);

    app(TaskWorkflowService::class)->handoff(
        $qaRun,
        $task,
        'coder',
        'changes_requested',
        'qa-repair-1',
        [],
        $qaRun->execution_token,
    );

    $reconciliation = app(AgentTurnReconciler::class)->reconcile(
        $task->refresh(),
        new AgentTurnExecution(
            $qaRun,
            new AgentHarnessResult(true, '', null, 0),
            'QA repair limit reached.',
        ),
    );

    $fresh = $task->refresh();

    expect($fresh->status)->toBe('failed');
    expect($fresh->outcome)->toBe('blocked');
    expect($fresh->blocked_reason)->toContain('repair cycle limit');
    expect(app(RepairCycleGuard::class)->repairCycleCount($fresh))->toBe(1);
    expect($fresh->protocol_recovery_count)->toBe(0);
    expect($reconciliation->classification)->toBe('terminal');
    expect($reconciliation->failureClass)->toBe('terminal_blocked');
});

test('completing an Agent execution immediately triggers the next dispatch attempt', function () {
    Queue::fake([
        DispatchWorkflowForProject::class,
    ]);

    $project = Project::factory()->create();

    app(ProjectAgentProvisioner::class)->ensureFor($project);

    $workRequest = $project->workRequests()->create([
        'prompt' => 'Plan this change.',
        'status' => 'pending',
    ]);

    $output = json_encode([
        'status' => 'completed',
        'summary' => 'Nothing to do.',
        'tasks' => [],
    ], JSON_THROW_ON_ERROR);

    mock(AgentHarness::class)
        ->shouldReceive('start')
        ->once()
        ->andReturn(
            new AgentHarnessResult(true, $output, null, 0),
        );

    app()->call([
        new ProcessAgentExecution($workRequest),
        'handle',
    ]);

    Queue::assertPushed(
        DispatchWorkflowForProject::class,
        fn (DispatchWorkflowForProject $job): bool => $job->projectId === $project->id,
    );
});

/**
 * Build a Task with a current tree-bound QA approval and active approved Coder finalization run.
 *
 * @return array{0: Project, 1: Task, 2: AgentRun, 3: AgentRun}
 */
function taskWithApprovedCandidate(
    string $candidateTreeSha = 'candidate-1',
    bool $documentFinalizer = true,
): array {
    [$project, $task, $candidateRun] = taskRoleHandoffFixture('coder');

    $task->workRequest()->update([
        'status' => 'waiting',
    ]);

    $task->update([
        'status' => 'running',
        'candidate_tree_sha' => $candidateTreeSha,
        'candidate_created_by_run_id' => $candidateRun->id,
        'candidate_kind' => 'changes',
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

    app(CandidateAcceptanceGate::class)->recordReview(
        $task,
        $candidateRun,
        $qaRun,
        $candidateTreeSha,
        'approved',
        'Looks good.',
        [],
    );

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

    $candidateRun->update([
        'status' => 'succeeded',
    ]);

    $qaRun->update([
        'status' => 'succeeded',
    ]);

    $coderAgent = $project->agents()->where('role', 'coder')->sole();

    $coderRun = app(AgentSessionManager::class)->startRun(
        app(AgentSessionManager::class)->forSubject($coderAgent, $task),
        'coder',
        [
            'mode' => 'initial',
            'input' => 'Finalize.',
            'sources' => [],
            'agent_snapshot' => [],
            'prompt_snapshot' => [],
            'role' => 'coder',
        ],
    );

    $coderRun->update([
        'execution_metadata' => [
            'accepted_handoff_id' => $handoff->id,
            'execution_mode' => 'approved',
        ],
    ]);

    if ($documentFinalizer) {
        markAgentRunDocumented($coderRun);
    }

    $task->refresh()->update([
        'status' => 'running',
    ]);

    return [$project, $task->refresh(), $coderRun, $qaRun];
}

/**
 * Persist one Task-scoped verification attempt for finalization evidence tests.
 */
function recordTaskVerification(
    Task $task,
    AgentRun $run,
    string $status,
    ?string $candidateTreeSha = null,
    string $profile = 'ci',
): ProjectVerificationRun {
    $task->loadMissing('workRequest');

    return ProjectVerificationRun::query()->create([
        'agent_run_id' => $run->id,
        'project_id' => $task->workRequest->project_id,
        'task_id' => $task->id,
        'idempotency_key' => 'test-verification-'.Str::uuid(),
        'profile' => $profile,
        'driver' => 'native',
        'target_type' => 'task_candidate',
        'command' => ['composer', 'ci:check'],
        'candidate_tree_sha' => $candidateTreeSha ?? $task->candidate_tree_sha,
        'status' => $status,
        'exit_code' => $status === ProjectVerificationRun::STATUS_PASSED
            ? 0
            : 1,
        'duration_ms' => 10,
        'stdout' => $status === ProjectVerificationRun::STATUS_PASSED
            ? 'PASS'
            : '',
        'stderr' => $status === ProjectVerificationRun::STATUS_FAILED
            ? 'FAILED: some test'
            : '',
        'diagnostic' => null,
        'started_at' => now()->subSecond(),
        'finished_at' => now(),
    ]);
}

/**
 * Build non-persisted service-return evidence for status-handling tests.
 */
function transientTaskVerification(
    Task $task,
    string $status,
): ProjectVerificationRun {
    $task->loadMissing('workRequest');

    return new ProjectVerificationRun([
        'project_id' => $task->workRequest->project_id,
        'task_id' => $task->id,
        'idempotency_key' => 'transient-verification',
        'profile' => 'ci',
        'driver' => 'native',
        'target_type' => 'task_candidate',
        'command' => ['composer', 'ci:check'],
        'candidate_tree_sha' => $task->candidate_tree_sha,
        'status' => $status,
        'exit_code' => null,
        'duration_ms' => 10,
        'stdout' => '',
        'stderr' => '',
        'diagnostic' => $status,
        'started_at' => now()->subSecond(),
        'finished_at' => now(),
    ]);
}
