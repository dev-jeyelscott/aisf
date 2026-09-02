<?php

use App\Jobs\ProcessAgentExecution;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Task;
use App\Services\AgentSessionManager;
use App\Services\CandidateAcceptanceGate;
use App\Services\RepairCycleGuard;
use App\Services\TaskWorkflowService;
use Illuminate\Support\Facades\Queue;

test('operator retry starts a fresh repair budget while preserving historical reviews and handoffs', function () {
    config([
        'aisf.max_repair_cycles' => 5,
    ]);

    Queue::fake([
        ProcessAgentExecution::class,
    ]);

    [$project, $task] = historicalRepairLimitFixture(3, 2);

    $guard = app(RepairCycleGuard::class);

    $historicalReviewIds = $task->candidateReviews()
        ->orderBy('id')
        ->pluck('id')
        ->all();

    $historicalHandoffIds = $task->handoffs()
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($guard->repairCycleCount($task))->toBe(5);
    expect($guard->limitExceeded($task))->toBeTrue();

    $this->post(
        route('projects.tasks.retry', [$project, $task]),
    )->assertRedirect(
        route('projects.tasks.show', [$project, $task]),
    );

    $fresh = $task->refresh();

    expect($fresh->status)->toBe('pending');
    expect($fresh->outcome)->toBeNull();
    expect($fresh->protocol_recovery_count)->toBe(0);
    expect($fresh->blocked_reason)->toBeNull();

    expect($fresh->repair_cycle_review_boundary_id)
        ->toBe(max($historicalReviewIds));

    expect($fresh->repair_cycle_handoff_boundary_id)
        ->toBe(max($historicalHandoffIds));

    expect($guard->repairCycleCount($fresh))->toBe(0);
    expect($guard->limitExceeded($fresh))->toBeFalse();

    expect(
        $fresh->candidateReviews()
            ->orderBy('id')
            ->pluck('id')
            ->all(),
    )->toBe($historicalReviewIds);

    expect(
        $fresh->handoffs()
            ->orderBy('id')
            ->pluck('id')
            ->all(),
    )->toBe($historicalHandoffIds);

    expect($fresh->last_handoff['id'])
        ->toBe(max($historicalHandoffIds));

    Queue::assertPushed(
        ProcessAgentExecution::class,
        fn (ProcessAgentExecution $job): bool => $job->subject instanceof Task
            && $job->subject->id === $task->id,
    );
});

test('a fresh operator retry cycle independently reaches its configured repair limit', function () {
    config([
        'aisf.max_repair_cycles' => 1,
    ]);

    Queue::fake([
        ProcessAgentExecution::class,
    ]);

    [$project, $task, $coderRun, $qaRun] =
        historicalRepairLimitFixture(1, 1);

    $guard = app(RepairCycleGuard::class);

    expect($guard->repairCycleCount($task))->toBe(2);

    $this->post(
        route('projects.tasks.retry', [$project, $task]),
    )->assertRedirect(
        route('projects.tasks.show', [$project, $task]),
    );

    $fresh = $task->refresh();

    expect($guard->repairCycleCount($fresh))->toBe(0);

    $fresh->update([
        'status' => 'running',
        'candidate_tree_sha' => 'retry-cycle-candidate',
        'candidate_created_by_run_id' => $coderRun->id,
        'candidate_kind' => 'changes',
    ]);

    app(CandidateAcceptanceGate::class)->recordReview(
        $fresh,
        $coderRun,
        $qaRun,
        'retry-cycle-candidate',
        'changes_requested',
        'The retried candidate still needs repair.',
        ['Fix the retried candidate.'],
    );

    markAgentRunDocumented($qaRun);

    app(TaskWorkflowService::class)->handoff(
        $qaRun,
        $fresh,
        'coder',
        'changes_requested',
        'retry-cycle-limit',
        [],
        $qaRun->execution_token,
    );

    $terminal = $fresh->refresh();

    expect($guard->repairCycleCount($terminal))->toBe(1);
    expect($guard->limitExceeded($terminal))->toBeTrue();
    expect($terminal->status)->toBe('failed');
    expect($terminal->outcome)->toBe('blocked');
    expect($terminal->blocked_reason)->toContain('repair cycle limit');

    expect(
        $terminal->candidateReviews()
            ->where('status', 'changes_requested')
            ->count(),
    )->toBe(2);

    expect($terminal->handoffs()->count())->toBe(2);
});

/**
 * Create a failed Task whose historical QA and CI repair evidence already consumes its old repair budget.
 *
 * @return array{0: Project, 1: Task, 2: AgentRun, 3: AgentRun}
 */
function historicalRepairLimitFixture(
    int $reviewCount,
    int $ciFailureCount,
): array {
    [$project, $task, $coderRun] = taskRoleHandoffFixture('coder');

    $qaAgent = $project->agents()
        ->where('role', 'qa')
        ->sole();

    $qaRun = app(AgentSessionManager::class)->startRun(
        app(AgentSessionManager::class)->forSubject($qaAgent, $task),
        'qa',
        [
            'mode' => 'initial',
            'input' => 'Review historical candidate.',
            'sources' => [],
            'agent_snapshot' => [],
            'prompt_snapshot' => [],
            'role' => 'qa',
        ],
    );

    foreach (range(1, $reviewCount) as $index) {
        $task->candidateReviews()->create([
            'candidate_agent_run_id' => $coderRun->id,
            'reviewer_agent_run_id' => $qaRun->id,
            'candidate_tree_sha' => 'historical-candidate-'.$index,
            'status' => 'changes_requested',
            'summary' => 'Historical QA repair '.$index,
            'findings' => [
                'Historical finding '.$index,
            ],
        ]);
    }

    $coderRun->loadMissing('agentSession');

    $latestHandoff = null;

    foreach (range(1, $ciFailureCount) as $index) {
        $latestHandoff = $task->handoffs()->create([
            'from_project_agent_id' => $coderRun->agentSession->project_agent_id,
            'to_project_agent_id' => $coderRun->agentSession->project_agent_id,
            'from_agent_run_id' => $coderRun->id,
            'reason' => 'ci_failed',
            'payload' => [
                'ci_output' => 'Historical CI failure '.$index,
            ],
            'idempotency_key' => 'historical-ci-'.$index,
            'dispatched_at' => now(),
        ]);
    }

    if ($latestHandoff === null) {
        throw new RuntimeException(
            'The retry fixture requires at least one durable handoff.',
        );
    }

    $task->update([
        'status' => 'failed',
        'outcome' => 'blocked',
        'protocol_recovery_count' => 2,
        'blocked_reason' => 'Historical repair cycle limit reached.',
        'last_handoff' => [
            'id' => $latestHandoff->id,
            'to_role' => 'coder',
            'reason' => $latestHandoff->reason,
            'payload' => $latestHandoff->payload,
        ],
    ]);

    return [
        $project,
        $task->fresh(),
        $coderRun,
        $qaRun,
    ];
}
