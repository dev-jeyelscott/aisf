<?php

use App\Models\AgentRun;
use App\Models\AgentRunAction;
use App\Models\Project;
use App\Models\Task;
use App\Services\AgentRunActionRecorder;
use App\Services\AgentSessionManager;
use App\Services\ProjectAgentProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| Pest framework gives you access to a set of "expectations" methods that you can use to assert
| different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Build one active role-specific Task AgentRun for workflow feature tests.
 *
 * @return array{0: Project, 1: Task, 2: AgentRun}
 */
function taskRoleHandoffFixture(string $role): array
{
    $project = Project::factory()->create();
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $workRequest = $project->workRequests()->create(['prompt' => 'Implement the requested change.']);
    $task = $workRequest->tasks()->create([
        'position' => 1,
        'title' => 'Implement the requested change',
        'objective' => 'Deliver the behavior.',
        'implementation_spec' => 'Use existing conventions.',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
    ]);
    $agent = $project->agents()->where('role', $role)->sole();
    $session = app(AgentSessionManager::class)->forSubject($agent, $task);
    $run = app(AgentSessionManager::class)->startRun($session, $role, [
        'mode' => 'initial',
        'input' => 'Execute the Task.',
        'sources' => [],
        'agent_snapshot' => [],
        'prompt_snapshot' => [],
        'role' => $role,
    ]);

    return [$project, $task, $run];
}

/**
 * Record exact vault-note action evidence for tests that do not exercise filesystem writing itself.
 */
function markAgentRunDocumented(AgentRun $run): void
{
    if (
        $run->actions()
            ->where('action', AgentRunAction::ACTION_VAULT_NOTE_WRITTEN)
            ->exists()
    ) {
        return;
    }

    app(AgentRunActionRecorder::class)->record(
        $run,
        AgentRunAction::ACTION_VAULT_NOTE_WRITTEN,
        $run,
    );
}
