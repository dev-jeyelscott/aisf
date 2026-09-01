<?php

use App\Models\Project;
use App\Models\Task;
use App\Services\AgentSessionManager;
use App\Services\ProjectAgentProvisioner;

test('attributable nonterminal legacy candidates return to a durable Coder recovery handoff', function () {
    [$project, $task] = legacyCandidateFixture(['status' => 'running', 'candidate_sha' => 'legacy-base']);
    $coder = $project->agents()->where('role', 'coder')->sole();
    $run = app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($coder, $task), 'coder', legacyCandidateRunContext());
    $run->update(['status' => 'succeeded']);

    $this->artisan('workflow:invalidate-legacy-candidates')->assertSuccessful();

    expect($task->refresh()->status)->toBe('waiting')
        ->and($task->candidate_sha)->toBeNull()
        ->and($task->last_handoff['reason'])->toBe('candidate_fingerprint_migration')
        ->and($task->handoffs()->count())->toBe(1)
        ->and($run->actions()->where('action', 'handoff_created')->count())->toBe(1);
});

test('completed legacy candidates remain unchanged', function () {
    [, $task] = legacyCandidateFixture(['status' => 'completed', 'candidate_sha' => 'legacy-base']);

    $this->artisan('workflow:invalidate-legacy-candidates')->assertSuccessful();

    expect($task->refresh()->status)->toBe('completed')
        ->and($task->candidate_sha)->toBe('legacy-base')
        ->and($task->handoffs()->count())->toBe(0);
});

test('unattributed or published legacy candidates are blocked for operator review', function () {
    [$project, $unattributed] = legacyCandidateFixture(['status' => 'waiting', 'candidate_sha' => 'legacy-1']);
    $published = $unattributed->workRequest->tasks()->create([
        'position' => 2,
        'title' => 'Published legacy candidate',
        'objective' => 'Published legacy candidate',
        'implementation_spec' => '',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
        'status' => 'waiting',
        'candidate_sha' => 'legacy-2',
        'pull_request_url' => 'https://github.com/example/repo/pull/1',
    ]);
    $coder = $project->agents()->where('role', 'coder')->sole();
    app(AgentSessionManager::class)->startRun(app(AgentSessionManager::class)->forSubject($coder, $published), 'coder', legacyCandidateRunContext());

    $this->artisan('workflow:invalidate-legacy-candidates')->assertSuccessful();

    expect($unattributed->refresh()->outcome)->toBe('blocked')
        ->and($published->refresh()->outcome)->toBe('blocked')
        ->and($published->pull_request_url)->not->toBeNull();
});

/** @param array<string, mixed> $overrides
 * @return array{0: Project, 1: Task}
 */
function legacyCandidateFixture(array $overrides): array
{
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $workRequest = $project->workRequests()->create(['prompt' => 'Migrate the legacy candidate.']);
    $task = $workRequest->tasks()->create(array_merge([
        'position' => 1,
        'title' => 'Legacy candidate',
        'objective' => 'Legacy candidate',
        'implementation_spec' => '',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
    ], $overrides));

    return [$project, $task];
}

/** @return array<string, mixed> */
function legacyCandidateRunContext(): array
{
    return [
        'mode' => 'initial', 'input' => 'Implement.', 'sources' => [],
        'agent_snapshot' => [], 'prompt_snapshot' => [], 'role' => 'coder',
    ];
}
