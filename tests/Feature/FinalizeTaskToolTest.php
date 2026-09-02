<?php

use App\Mcp\Tools\FinalizeTask;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Task;
use App\Services\AgentSessionManager;
use App\Services\CandidateAcceptanceGate;
use App\Services\TaskCandidateFingerprint;
use App\Services\TaskWorkflowService;
use App\Services\TaskWorktreeManager;
use Laravel\Mcp\Request;

use function Pest\Laravel\mock;

it('returns the Task blocked_reason so a blocked Agent turn is not left without a diagnostic', function () {
    [, $task, $coderRun] = feature14ApprovedCandidateFixture();

    mock(TaskWorktreeManager::class)
        ->shouldReceive('verifyCommitExists')->once()->andReturn('commit-sha-1')
        ->shouldReceive('verifyHeadMatches')->once()
        ->shouldReceive('runCiCheck')->once()->andReturn([
            'passed' => false,
            'environment' => true,
            'output' => 'InvalidArgumentException: Database connection [default] not configured.',
        ]);
    mock(TaskCandidateFingerprint::class)
        ->shouldReceive('currentTreeSha')->once()->andReturn('candidate-1')
        ->shouldReceive('commitTreeSha')->once()->andReturn('candidate-1');

    $response = app(FinalizeTask::class)->handle(new Request([
        'task_id' => $task->id,
        'agent_run_id' => $coderRun->id,
        'execution_token' => $coderRun->execution_token,
        'commit_sha' => 'commit-sha-1',
        'summary' => 'Implemented the change.',
    ]));

    $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('failed')
        ->and($payload['outcome'])->toBe('blocked')
        ->and($payload['blocked_reason'])->toContain('unavailable or misconfigured')
        ->and($payload['blocked_reason'])->toContain('Database connection [default] not configured');
});

it('returns a null blocked_reason when finalization completes successfully', function () {
    [, $task, $coderRun] = feature14ApprovedCandidateFixture();

    mock(TaskWorktreeManager::class)
        ->shouldReceive('verifyCommitExists')->once()->andReturn('commit-sha-1')
        ->shouldReceive('verifyHeadMatches')->once()
        ->shouldReceive('runCiCheck')->once()->andReturn(['passed' => true, 'environment' => false, 'output' => ''])
        ->shouldReceive('pushAndOpenPullRequest')->once()->andReturn(['commit_sha' => 'commit-sha-1', 'pull_request_url' => 'https://github.com/org/repo/pull/1']);
    mock(TaskCandidateFingerprint::class)
        ->shouldReceive('currentTreeSha')->once()->andReturn('candidate-1')
        ->shouldReceive('commitTreeSha')->once()->andReturn('candidate-1');

    $response = app(FinalizeTask::class)->handle(new Request([
        'task_id' => $task->id,
        'agent_run_id' => $coderRun->id,
        'execution_token' => $coderRun->execution_token,
        'commit_sha' => 'commit-sha-1',
        'summary' => 'Implemented the change.',
    ]));

    $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('completed')
        ->and($payload['blocked_reason'])->toBeNull();
});

/**
 * Build a Task with an approved candidate and an active, documented, finalization-mode Coder run.
 *
 * @return array{0: Project, 1: Task, 2: AgentRun}
 */
function feature14ApprovedCandidateFixture(): array
{
    [$project, $task, $candidateRun] = taskRoleHandoffFixture('coder');
    $task->workRequest()->update(['status' => 'waiting']);
    $task->update([
        'status' => 'running',
        'candidate_tree_sha' => 'candidate-1',
        'candidate_created_by_run_id' => $candidateRun->id,
        'candidate_kind' => 'changes',
    ]);
    $qaAgent = $project->agents()->where('role', 'qa')->sole();
    $qaRun = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($qaAgent, $task), 'qa', [
        'mode' => 'initial', 'input' => 'Review.', 'sources' => [], 'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'qa',
    ]);
    app(CandidateAcceptanceGate::class)->recordReview($task, $candidateRun, $qaRun, 'candidate-1', 'approved', 'Looks good.', []);
    markAgentRunDocumented($qaRun);
    $handoff = app(TaskWorkflowService::class)->handoff(
        $qaRun, $task, 'coder', 'approved', 'qa-approved-'.$qaRun->id, [], $qaRun->execution_token,
    );
    $candidateRun->update(['status' => 'succeeded']);
    $qaRun->update(['status' => 'succeeded']);
    $coderAgent = $project->agents()->where('role', 'coder')->sole();
    $coderRun = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($coderAgent, $task), 'coder', [
        'mode' => 'initial', 'input' => 'Finalize.', 'sources' => [], 'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'coder',
    ]);
    $coderRun->update(['execution_metadata' => ['accepted_handoff_id' => $handoff->id, 'execution_mode' => 'approved']]);
    markAgentRunDocumented($coderRun);
    $task->refresh()->update(['status' => 'running']);

    return [$project, $task->refresh(), $coderRun];
}
