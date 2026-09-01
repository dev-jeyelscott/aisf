<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\WorkRequest;
use App\Services\AgentSessionManager;
use App\Services\ProjectAgentProvisioner;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Verify a valid nested Project Task route renders the dedicated inspection page
 * and the canonical persisted Task data needed by the interface.
 */
test('task view renders the dedicated task inspection payload', function () {
    [$project, $workRequest, $task, $dependency] = taskViewFixture();

    $this->get(route('projects.tasks.show', [$project, $task]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tasks/show')
            ->where('project.id', $project->id)
            ->where('project.title', $project->title)
            ->where('workRequest.id', $workRequest->id)
            ->where('workRequest.prompt', $workRequest->prompt)
            ->where('workRequest.source_type', 'manual')
            ->where('task.id', $task->id)
            ->where('task.title', $task->title)
            ->where('task.objective', $task->objective)
            ->where('task.implementation_spec', $task->implementation_spec)
            ->where('task.status', 'completed')
            ->where('task.outcome', 'implemented')
            ->where('task.candidate_tree_sha', 'candidate-tree-sha')
            ->where('task.candidate_kind', 'changes')
            ->where('task.commit_sha', 'commit-sha')
            ->where('task.changed_files', [])
            ->where('dependency.id', $dependency->id)
            ->where('dependency.title', $dependency->title)
            ->has('task.agent_sessions', 0)
            ->has('task.candidate_reviews', 0)
            ->has('task.handoffs', 0)
            ->where('task.repair_cycle_count', 0)
            ->where(
                'task.repair_cycle_limit',
                (int) config('aisf.max_repair_cycles'),
            ));
});

/**
 * Verify the nested Task page rejects Tasks whose WorkRequest belongs to a
 * different Project using the same ownership boundary as run and retry.
 */
test('task view rejects a task owned by another project', function () {
    $requestedProject = Project::factory()->create([
        'enabled' => false,
    ]);

    [$owningProject, , $task] = taskViewFixture();

    expect($owningProject->isNot($requestedProject))->toBeTrue();

    $this->get(
        route('projects.tasks.show', [$requestedProject, $task]),
    )->assertNotFound();
});

/**
 * Verify the dedicated Task view receives complete durable Agent run history
 * while the Project workspace retains its existing ten-run presentation cap.
 */
test('task view exposes complete run history without expanding project workspace payloads', function () {
    [$project, , $task] = taskViewFixture();

    app(ProjectAgentProvisioner::class)->ensureFor($project);

    $coder = $project->agents()
        ->where('role', 'coder')
        ->sole();

    $sessionManager = app(AgentSessionManager::class);
    $session = $sessionManager->forSubject($coder, $task);

    foreach (range(1, 11) as $attempt) {
        $run = $sessionManager->startRun(
            $session,
            'coder',
            [
                'mode' => 'initial',
                'input' => "Task view test run {$attempt}.",
                'sources' => [],
                'agent_snapshot' => [],
                'prompt_snapshot' => [],
                'role' => 'coder',
            ],
        );

        $sessionManager->completeRun(
            $run,
            "Completed Task view test run {$attempt}.",
            0,
            null,
            [
                'harness' => 'test-harness',
                'model' => 'test-model',
            ],
        );
    }

    $this->get(route('projects.tasks.show', [$project, $task]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('task.agent_sessions', 1)
            ->has('task.agent_sessions.0.runs', 11));

    $this->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('workRequests', 1)
            ->has('workRequests.0.tasks', 2)
            ->has('workRequests.0.tasks.1.agent_sessions', 1)
            ->has('workRequests.0.tasks.1.agent_sessions.0.runs', 10));
});

/**
 * Create a deterministic disabled Project, WorkRequest, dependency, and Task
 * without requiring a live Git repository during Task View feature tests.
 *
 * @return array{Project, WorkRequest, Task, Task}
 */
function taskViewFixture(): array
{
    $project = Project::factory()->create([
        'title' => 'AISF',
        'enabled' => false,
    ]);

    $workRequest = $project->workRequests()->create([
        'prompt' => 'Implement the dedicated Task inspection page.',
        'status' => 'completed',
        'outcome' => 'implemented',
        'summary' => 'Task plan created.',
        'source_type' => 'manual',
    ]);

    $dependency = $workRequest->tasks()->create([
        'position' => 1,
        'title' => 'Prepare supporting workflow data',
        'objective' => 'Persist workflow evidence.',
        'implementation_spec' => 'Use the existing workflow services.',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
        'status' => 'completed',
        'outcome' => 'implemented',
    ]);

    $task = $workRequest->tasks()->create([
        'depends_on_task_id' => $dependency->id,
        'position' => 2,
        'title' => 'Implement Task View',
        'objective' => 'Provide a dedicated Task inspection workspace.',
        'implementation_spec' => 'Render durable workflow evidence and Task metadata.',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
        'status' => 'completed',
        'outcome' => 'implemented',
        'protocol_recovery_count' => 1,
        'branch_name' => 'feature/task-view',
        'candidate_tree_sha' => 'candidate-tree-sha',
        'candidate_kind' => 'changes',
        'commit_sha' => 'commit-sha',
    ]);

    return [$project, $workRequest, $task, $dependency];
}
