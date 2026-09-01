<?php

use App\Mcp\Tools\HandoffTask;
use App\Mcp\Tools\SaveQaReview;
use App\Mcp\Tools\SaveTaskPlan;
use App\Mcp\Tools\SaveTaskResult;
use App\Models\AgentRun;
use App\Models\AgentRunAction;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkRequest;
use App\Services\AgentSessionManager;
use App\Services\CandidateAcceptanceGate;
use App\Services\ProjectAgentProvisioner;
use App\Services\TaskCommitIntegrator;
use App\Services\TaskWorkflowService;
use App\Services\TaskWorktreeManager;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;
use RuntimeException;
use UnexpectedValueException;

use function Pest\Laravel\mock;

/**
 * Expose only the mutation tools required for focused MCP action-evidence tests.
 */
class AgentRunActionMcpServer extends Server
{
    /**
     * The mutation tools exercised by this feature.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        SaveTaskPlan::class,
        SaveTaskResult::class,
        SaveQaReview::class,
        HandoffTask::class,
    ];
}

test('a successful MCP plan save records every created Task against the exact Project Manager run', function () {
    [$project, $workRequest, $run] = agentRunActionProjectManagerFixture();

    $response = AgentRunActionMcpServer::tool(SaveTaskPlan::class, [
        'work_request_id' => $workRequest->id,
        'agent_run_id' => $run->id,
        'execution_token' => $run->execution_token,
        'tasks' => [
            [
                'title' => 'Implement the backend',
                'objective' => 'Implement the requested backend behavior.',
                'implementation_spec' => 'Follow existing service conventions.',
                'acceptance_criteria' => ['Backend behavior is implemented.'],
                'verification_commands' => ['php artisan test --compact'],
                'browser_steps' => [],
                'depends_on_position' => null,
            ],
            [
                'title' => 'Verify the integration',
                'objective' => 'Verify the completed behavior.',
                'implementation_spec' => 'Run the focused regression checks.',
                'acceptance_criteria' => ['Regression coverage passes.'],
                'verification_commands' => ['composer ci:check'],
                'browser_steps' => [],
                'depends_on_position' => 1,
            ],
        ],
    ]);

    $response->assertOk();

    $tasks = $workRequest->tasks()->orderBy('id')->get();
    $actions = $run->actions()
        ->where('action', AgentRunAction::ACTION_PLAN_SAVED)
        ->orderBy('resource_id')
        ->get();

    expect($tasks)->toHaveCount(2);
    expect($actions)->toHaveCount(2);
    expect($actions->pluck('resource_type')->unique()->values()->all())
        ->toBe([AgentRunAction::RESOURCE_TASK]);
    expect($actions->pluck('resource_id')->sort()->values()->all())
        ->toBe($tasks->pluck('id')->sort()->values()->all());
});

test('a successful MCP Coder result save records the Task against the exact Coder run', function () {
    [, $task, $run] = taskRoleHandoffFixture('coder');

    $task->update([
        'base_sha' => 'base-sha',
        'worktree_path' => '/tmp/aisf-task-worktree',
    ]);

    mock(TaskWorktreeManager::class)
        ->shouldReceive('ensureWorktree')
        ->once()
        ->shouldReceive('assertNoCommitBeforeQa')
        ->once()
        ->shouldReceive('changedFiles')
        ->once()
        ->andReturn(['app/Services/Example.php']);

    $response = AgentRunActionMcpServer::tool(SaveTaskResult::class, [
        'task_id' => $task->id,
        'agent_run_id' => $run->id,
        'execution_token' => $run->execution_token,
        'result' => [
            'summary' => 'Implemented the requested behavior.',
            'validation' => [
                [
                    'command' => 'php artisan test --compact',
                    'passed' => true,
                ],
            ],
        ],
    ]);

    $response->assertOk();

    $action = $run->actions()
        ->where('action', AgentRunAction::ACTION_TASK_RESULT_SAVED)
        ->sole();

    expect($task->refresh()->candidate_sha)->toBe('base-sha');
    expect($run->refresh()->artifacts['summary'])->toBe('Implemented the requested behavior.');
    expect($action->resource_type)->toBe(AgentRunAction::RESOURCE_TASK);
    expect($action->resource_id)->toBe($task->id);
});

test('a successful MCP QA review records the CandidateReview against the exact QA run', function () {
    [$project, $task, $coderRun] = taskRoleHandoffFixture('coder');

    $task->update([
        'candidate_sha' => 'candidate-1',
    ]);

    $coderRun->update([
        'status' => 'succeeded',
        'finished_at' => now(),
    ]);

    $qaAgent = $project->agents()->where('role', 'qa')->sole();
    $qaRun = app(AgentSessionManager::class)->startRun(
        app(AgentSessionManager::class)->forSubject($qaAgent, $task),
        'qa',
        [
            'mode' => 'initial',
            'input' => 'Review the candidate.',
            'sources' => [],
            'agent_snapshot' => [],
            'prompt_snapshot' => [],
            'role' => 'qa',
        ],
    );

    $response = AgentRunActionMcpServer::tool(SaveQaReview::class, [
        'task_id' => $task->id,
        'agent_run_id' => $qaRun->id,
        'execution_token' => $qaRun->execution_token,
        'candidate_sha' => 'candidate-1',
        'status' => 'approved',
        'summary' => 'The candidate satisfies the Task.',
        'findings' => [],
    ]);

    $response->assertOk();

    $review = $task->candidateReviews()->sole();
    $action = $qaRun->actions()
        ->where('action', AgentRunAction::ACTION_QA_REVIEW_SAVED)
        ->sole();

    expect($action->resource_type)->toBe(AgentRunAction::RESOURCE_CANDIDATE_REVIEW);
    expect($action->resource_id)->toBe($review->id);
    expect(
        $coderRun->actions()
            ->where('action', AgentRunAction::ACTION_QA_REVIEW_SAVED)
            ->count(),
    )->toBe(0);
});

test('an idempotent MCP handoff retry does not duplicate durable action evidence', function () {
    [, $task, $run] = taskRoleHandoffFixture('coder');

    $task->update([
        'base_sha' => 'base-sha',
        'worktree_path' => '/tmp/aisf-task-worktree',
    ]);

    $run->update([
        'artifacts' => [
            'validation' => [],
        ],
    ]);

    mock(TaskWorktreeManager::class)
        ->shouldReceive('assertNoCommitBeforeQa')
        ->once();

    $payload = [
        'task_id' => $task->id,
        'agent_run_id' => $run->id,
        'execution_token' => $run->execution_token,
        'to_role' => 'qa',
        'reason' => 'ready_for_review',
        'idempotency_key' => 'handoff-1',
        'payload' => [],
    ];

    AgentRunActionMcpServer::tool(HandoffTask::class, $payload)->assertOk();
    AgentRunActionMcpServer::tool(HandoffTask::class, $payload)->assertOk();

    $handoff = $task->handoffs()->sole();
    $actions = $run->actions()
        ->where('action', AgentRunAction::ACTION_HANDOFF_CREATED)
        ->get();

    expect($actions)->toHaveCount(1);
    expect($actions->sole()->resource_type)->toBe(AgentRunAction::RESOURCE_TASK_HANDOFF);
    expect($actions->sole()->resource_id)->toBe($handoff->id);
});

test('invalid MCP mutation input creates no action evidence', function () {
    $response = AgentRunActionMcpServer::tool(SaveTaskResult::class, [
        'task_id' => 1,
    ]);

    $response->assertHasErrors();

    expect(AgentRunAction::query()->count())->toBe(0);
});

test('a stale Agent run mutation creates no action evidence', function () {
    [, $task, $run] = taskRoleHandoffFixture('coder');

    $run->update([
        'status' => 'succeeded',
        'finished_at' => now(),
    ]);

    expect(fn () => app(TaskWorkflowService::class)->handoff(
        $run,
        $task,
        'qa',
        'ready_for_review',
        'handoff-1',
        [],
        $run->execution_token,
    ))->toThrow(UnexpectedValueException::class);

    expect($task->handoffs()->count())->toBe(0);
    expect($run->actions()->count())->toBe(0);
});

test('a rejected QA review creates neither a review nor action evidence', function () {
    [$project, $task, $coderRun] = taskRoleHandoffFixture('coder');

    $task->update([
        'candidate_sha' => 'candidate-1',
    ]);

    $qaAgent = $project->agents()->where('role', 'qa')->sole();
    $qaRun = app(AgentSessionManager::class)->startRun(
        app(AgentSessionManager::class)->forSubject($qaAgent, $task),
        'qa',
        [
            'mode' => 'initial',
            'input' => 'Review the candidate.',
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
        'stale-candidate',
        'approved',
        'Looks good.',
        [],
    ))->toThrow(UnexpectedValueException::class);

    expect($task->candidateReviews()->count())->toBe(0);
    expect(
        $qaRun->actions()
            ->where('action', AgentRunAction::ACTION_QA_REVIEW_SAVED)
            ->count(),
    )->toBe(0);
});

test('a failed action insert rolls back the entire Project Manager plan transaction', function () {
    [, $workRequest, $run] = agentRunActionProjectManagerFixture();

    AgentRunAction::creating(function (): void {
        throw new RuntimeException('Action persistence failed.');
    });

    try {
        expect(fn () => app(TaskWorkflowService::class)->savePlan(
            $run,
            $workRequest,
            [
                [
                    'title' => 'Implement the change',
                    'objective' => 'Deliver the behavior.',
                    'implementation_spec' => 'Follow existing conventions.',
                    'acceptance_criteria' => [],
                    'verification_commands' => [],
                    'browser_steps' => [],
                    'depends_on_position' => null,
                ],
            ],
            $run->execution_token,
        ))->toThrow(RuntimeException::class);
    } finally {
        AgentRunAction::flushEventListeners();
    }

    expect($workRequest->tasks()->count())->toBe(0);
    expect($run->actions()->count())->toBe(0);
});

test('a failed action insert rolls back both Coder result writes', function () {
    [, $task, $run] = taskRoleHandoffFixture('coder');

    $task->update([
        'base_sha' => 'base-sha',
        'worktree_path' => '/tmp/aisf-task-worktree',
    ]);

    mock(TaskWorktreeManager::class)
        ->shouldReceive('ensureWorktree')
        ->once()
        ->shouldReceive('assertNoCommitBeforeQa')
        ->once()
        ->shouldReceive('changedFiles')
        ->once()
        ->andReturn([]);

    AgentRunAction::creating(function (): void {
        throw new RuntimeException('Action persistence failed.');
    });

    try {
        expect(fn () => app(TaskWorkflowService::class)->saveResult(
            $run,
            $task,
            [
                'summary' => 'Implemented the change.',
                'validation' => [],
            ],
            $run->execution_token,
        ))->toThrow(RuntimeException::class);
    } finally {
        AgentRunAction::flushEventListeners();
    }

    expect($run->refresh()->artifacts)->toBeNull();
    expect($task->refresh()->candidate_sha)->toBeNull();
    expect($run->actions()->count())->toBe(0);
});

test('a successfully persisted Agent completion records its workflow outcome against the same run', function () {
    [, , $run] = taskRoleHandoffFixture('coder');

    app(AgentSessionManager::class)->completeRun(
        $run,
        'Coder execution completed.',
        0,
        executionMetadata: [
            'completion' => [
                'status' => 'waiting',
                'summary' => 'Coder execution completed.',
            ],
        ],
    );

    $action = $run->actions()
        ->where('action', AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED)
        ->sole();

    expect($run->refresh()->status)->toBe('succeeded');
    expect($action->resource_type)->toBe(AgentRunAction::RESOURCE_AGENT_RUN);
    expect($action->resource_id)->toBe($run->id);
});

test('a failed Agent execution does not record a successful workflow outcome', function () {
    [, , $run] = taskRoleHandoffFixture('coder');

    app(AgentSessionManager::class)->failRun(
        $run,
        new RuntimeException('Agent execution failed.'),
        1,
    );

    expect($run->refresh()->status)->toBe('failed');
    expect(
        $run->actions()
            ->where('action', AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED)
            ->count(),
    )->toBe(0);
});

test('finalizing an approved candidate records the completed Task against the responsible Coder run', function () {
    [, $task, $coderRun] = agentRunActionApprovedCandidateFixture();

    mock(TaskWorktreeManager::class)
        ->shouldReceive('verifyCommitExists')
        ->once()
        ->andReturn('commit-sha-1')
        ->shouldReceive('verifyHeadMatches')
        ->once()
        ->shouldReceive('runCiCheck')
        ->once()
        ->andReturn([
            'passed' => true,
            'output' => '',
        ])
        ->shouldReceive('pushAndOpenPullRequest')
        ->once()
        ->andReturn([
            'commit_sha' => 'commit-sha-1',
            'pull_request_url' => 'https://github.com/example/project/pull/1',
        ]);

    app(TaskCommitIntegrator::class)->integrate(
        $task,
        $coderRun,
        'commit-sha-1',
        'Implemented the change.',
    );

    $action = $coderRun->actions()
        ->where('action', AgentRunAction::ACTION_CANDIDATE_FINALIZED)
        ->sole();

    expect($task->refresh()->status)->toBe('completed');
    expect($action->resource_type)->toBe(AgentRunAction::RESOURCE_TASK);
    expect($action->resource_id)->toBe($task->id);
});

test('a CI repair handoff is attributed to the Coder run that caused it', function () {
    [, $task, $coderRun] = agentRunActionApprovedCandidateFixture();

    mock(TaskWorktreeManager::class)
        ->shouldReceive('verifyCommitExists')
        ->once()
        ->andReturn('commit-sha-1')
        ->shouldReceive('verifyHeadMatches')
        ->once()
        ->shouldReceive('runCiCheck')
        ->once()
        ->andReturn([
            'passed' => false,
            'output' => 'FAILED: focused regression test',
        ]);

    app(TaskCommitIntegrator::class)->integrate(
        $task,
        $coderRun,
        'commit-sha-1',
        'Implemented the change.',
    );

    $handoff = $task->handoffs()->where('reason', 'ci_failed')->sole();
    $action = $coderRun->actions()
        ->where('action', AgentRunAction::ACTION_HANDOFF_CREATED)
        ->sole();

    expect($task->refresh()->status)->toBe('waiting');
    expect($action->resource_type)->toBe(AgentRunAction::RESOURCE_TASK_HANDOFF);
    expect($action->resource_id)->toBe($handoff->id);
});

/**
 * Create an active Project Manager AgentRun attached to a pending WorkRequest.
 *
 * @return array{0: Project, 1: WorkRequest, 2: AgentRun}
 */
function agentRunActionProjectManagerFixture(): array
{
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);

    $workRequest = $project->workRequests()->create([
        'prompt' => 'Plan the requested change.',
        'status' => 'running',
    ]);

    $agent = $project->agents()->where('role', 'project_manager')->sole();

    $run = app(AgentSessionManager::class)->startRun(
        app(AgentSessionManager::class)->forSubject($agent, $workRequest),
        'project_manager',
        [
            'mode' => 'initial',
            'input' => 'Plan the work.',
            'sources' => [],
            'agent_snapshot' => [],
            'prompt_snapshot' => [],
            'role' => 'project_manager',
        ],
    );

    return [$project, $workRequest, $run];
}

/**
 * Create a running Task with a current QA-approved candidate.
 *
 * @return array{0: Project, 1: Task, 2: AgentRun, 3: AgentRun}
 */
function agentRunActionApprovedCandidateFixture(string $candidateSha = 'candidate-1'): array
{
    [$project, $task, $coderRun] = taskRoleHandoffFixture('coder');

    $task->update([
        'status' => 'running',
        'candidate_sha' => $candidateSha,
    ]);

    $qaAgent = $project->agents()->where('role', 'qa')->sole();

    $qaRun = app(AgentSessionManager::class)->startRun(
        app(AgentSessionManager::class)->forSubject($qaAgent, $task),
        'qa',
        [
            'mode' => 'initial',
            'input' => 'Review the candidate.',
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
        $candidateSha,
        'approved',
        'The candidate is approved.',
        [],
    );

    return [$project, $task->refresh(), $coderRun, $qaRun];
}
