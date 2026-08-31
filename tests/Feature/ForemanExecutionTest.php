<?php

use App\Models\AgentInstructionDefault;
use App\Models\Project;
use App\Services\AgentPromptComposer;
use App\Services\AgentSessionManager;
use App\Services\CandidateAcceptanceGate;
use App\Services\ProjectAgentProvisioner;
use UnexpectedValueException;

test('a Foreman prompt snapshots global defaults, Agent configuration, Skills, and Task context', function () {
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $foreman = $project->agents()->where('role', 'foreman')->sole();
    $foreman->update(['workflow_instructions' => 'Plan durable Tasks.', 'model' => 'gpt-5.6']);
    $skill = $project->skills()->create(['name' => 'Repository inspection', 'instructions' => 'Inspect before planning.', 'enabled' => true]);
    $foreman->skills()->attach($skill, ['position' => 1]);
    AgentInstructionDefault::query()->create(['role' => 'foreman', 'instructions' => 'Return durable engineering evidence.']);
    $workRequest = $project->workRequests()->create(['prompt' => 'Improve the factory.']);
    $task = $workRequest->tasks()->create([
        'position' => 1,
        'title' => 'Inspect the factory',
        'objective' => 'Find the smallest safe migration.',
        'implementation_spec' => 'Use the existing execution ledger.',
        'acceptance_criteria' => ['Evidence is durable.'],
        'verification_commands' => ['composer ci:check'],
        'browser_steps' => [],
    ]);

    $composed = app(AgentPromptComposer::class)->compose($foreman, $task, '/tmp/aisf-repository');

    expect($composed['prompt'])
        ->toContain('Return durable engineering evidence.')
        ->toContain('Repository inspection')
        ->toContain('Inspect the factory')
        ->toContain('/tmp/aisf-repository')
        ->and($composed['snapshot']['agent']['model'])->toBe('gpt-5.6')
        ->and($composed['snapshot']['skills'][0]['name'])->toBe('Repository inspection')
        ->and($composed['snapshot']['subject']['id'])->toBe($task->id);
});

test('reported ephemeral delegations are persisted as child Agent runs', function () {
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $foreman = $project->agents()->where('role', 'foreman')->sole();
    $workRequest = $project->workRequests()->create(['prompt' => 'Investigate an issue.']);
    $session = app(AgentSessionManager::class)->forSubject($foreman, $workRequest);
    $parent = app(AgentSessionManager::class)->startRun($session, 'foreman', [
        'mode' => 'initial',
        'input' => 'Investigate the request.',
        'sources' => [],
        'agent_snapshot' => ['role' => 'foreman'],
        'prompt_snapshot' => ['work_request_id' => $workRequest->id],
        'role' => 'foreman',
    ]);

    $child = app(AgentSessionManager::class)->recordDelegation($parent, [
        'purpose' => 'Repository research',
        'role' => 'researcher',
        'status' => 'succeeded',
        'evidence' => 'Located the relevant service.',
        'harness' => 'codex',
        'model' => 'gpt-5.6',
    ]);

    expect($child->parent_agent_run_id)->toBe($parent->id)
        ->and($child->agent_snapshot)->toMatchArray(['kind' => 'ephemeral', 'harness' => 'codex'])
        ->and($child->output_summary)->toBe('Located the relevant service.')
        ->and($parent->refresh()->children()->sole()->id)->toBe($child->id);
});

test('candidate approval requires a different Agent and the current candidate SHA', function () {
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $workRequest = $project->workRequests()->create(['prompt' => 'Implement a change.']);
    $task = $workRequest->tasks()->create([
        'position' => 1,
        'title' => 'Implement the change',
        'objective' => 'Deliver the requested behavior.',
        'implementation_spec' => 'Use the existing conventions.',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
        'candidate_sha' => 'candidate-sha',
    ]);
    $sessions = app(AgentSessionManager::class);
    $coder = $project->agents()->where('role', 'implementation_specialist')->sole();
    $reviewer = $project->agents()->where('role', 'independent_reviewer')->sole();
    $candidateRun = $sessions->startRun($sessions->forSubject($coder, $task), 'implementation', foremanRunContext());
    $reviewerRun = $sessions->startRun($sessions->forSubject($reviewer, $task), 'review', foremanRunContext());

    $review = app(CandidateAcceptanceGate::class)->recordReview($task, $candidateRun, $reviewerRun, 'candidate-sha', 'approved', 'Approved.', []);

    expect($review->candidate_sha)->toBe('candidate-sha')
        ->and(app(CandidateAcceptanceGate::class)->hasCurrentApproval($task))->toBeTrue();

    expect(fn () => app(CandidateAcceptanceGate::class)->recordReview($task, $candidateRun, $candidateRun, 'candidate-sha', 'approved', 'Self approved.', []))->toThrow(UnexpectedValueException::class)
        ->and(fn () => app(CandidateAcceptanceGate::class)->recordReview($task, $candidateRun, $reviewerRun, 'stale-sha', 'approved', 'Stale.', []))->toThrow(UnexpectedValueException::class);
});

/** @return array<string, mixed> */
function foremanRunContext(): array
{
    return [
        'mode' => 'initial',
        'input' => 'Run the assigned work.',
        'sources' => [],
        'agent_snapshot' => [],
        'prompt_snapshot' => [],
        'role' => 'specialist',
    ];
}
