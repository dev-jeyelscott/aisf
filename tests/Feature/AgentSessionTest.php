<?php

use App\Jobs\ProcessWorkRequest;
use App\Models\AgentSession;
use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\Task;
use App\Models\WorkRequest;
use App\Services\AgentContextAssembler;
use App\Services\AgentSessionManager;
use App\Services\ProjectAgentProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('one Agent and subject reuse one logical session and run attempts increase monotonically', function () {
    [$project, $workRequest, $task, $coder] = feature04TaskFixture();
    $manager = app(AgentSessionManager::class);

    $firstSession = $manager->forSubject($coder, $task);
    $secondSession = $manager->forSubject($coder, $task);

    $firstRun = $manager->startRun(
        $firstSession,
        'coder_initial',
        [
            'mode' => 'initial',
            'input' => 'Initial context.',
            'sources' => [
                ['type' => 'task', 'label' => 'Task specification'],
            ],
        ],
    );
    $manager->completeRun($firstRun, 'Initial implementation completed.', 0);

    $secondRun = $manager->startRun(
        $secondSession,
        'coder_fix',
        [
            'mode' => 'delta',
            'input' => 'Latest QA finding only.',
            'sources' => [
                ['type' => 'qa_findings', 'label' => 'Latest QA findings'],
            ],
        ],
    );

    expect($firstSession->id)->toBe($secondSession->id)
        ->and($firstRun->attempt)->toBe(1)
        ->and($secondRun->attempt)->toBe(2)
        ->and($task->agentSessions()->count())->toBe(1)
        ->and($coder->sessions()->count())->toBe(1)
        ->and($firstSession->runs()->count())->toBe(2)
        ->and($firstSession->task->is($task))->toBeTrue()
        ->and($firstSession->workRequest)->toBeNull();

    expect(fn () => AgentSession::query()->create([
        'project_agent_id' => $coder->id,
        'task_id' => $task->id,
        'work_request_id' => null,
    ]))->toThrow(QueryException::class);

    expect($project->id)->toBe($workRequest->project_id);
});

test('Project Manager sessions belong to WorkRequests while PM Task sessions are rejected', function () {
    [, $workRequest, $task] = feature04TaskFixture();
    $pm = $workRequest->project->agents()
        ->where('role', 'project_manager')
        ->sole();
    $manager = app(AgentSessionManager::class);

    $session = $manager->forSubject($pm, $workRequest);

    expect($session->workRequest->is($workRequest))->toBeTrue()
        ->and($session->task)->toBeNull();

    expect(fn () => $manager->forSubject($pm, $task))
        ->toThrow(UnexpectedValueException::class);
});

test('Coder initial context contains only approved initial sources in ordered Skill order and never prior run output', function () {
    [$project, , $task, $coder] = feature04TaskFixture();

    $firstSkill = $project->skills()->create([
        'name' => 'Repository Safety',
        'description' => 'Inspect safely.',
        'instructions' => 'Keep repository changes bounded.',
        'enabled' => true,
    ]);
    $secondSkill = $project->skills()->create([
        'name' => 'Browser Verification',
        'description' => 'Verify visible behavior.',
        'instructions' => 'Confirm the acceptance result in the browser.',
        'enabled' => true,
    ]);
    $disabledSkill = $project->skills()->create([
        'name' => 'Disabled Skill',
        'instructions' => 'NEVER_INCLUDE_THIS',
        'enabled' => false,
    ]);

    $coder->skills()->sync([
        $secondSkill->id => ['position' => 1],
        $firstSkill->id => ['position' => 2],
        $disabledSkill->id => ['position' => 3],
    ]);

    $session = app(AgentSessionManager::class)->forSubject($coder, $task);
    $priorRun = app(AgentSessionManager::class)->startRun(
        $session,
        'coder_previous',
        [
            'mode' => 'initial',
            'input' => 'PRIOR_TRANSCRIPT_MARKER',
            'sources' => [],
        ],
    );
    app(AgentSessionManager::class)->completeRun(
        $priorRun,
        'PRIOR_OUTPUT_MARKER',
    );

    $context = app(AgentContextAssembler::class)->coderInitial(
        $task,
        $coder,
    );

    $sourceLabels = collect($context['sources'])
        ->pluck('label')
        ->all();

    expect($context['mode'])->toBe('initial')
        ->and($context['input'])->toContain('Coder identity')
        ->toContain('Coder default context')
        ->toContain('Coder workflow')
        ->toContain($project->description)
        ->toContain($project->path)
        ->toContain($task->implementation_spec)
        ->toContain($task->acceptance_criteria[0])
        ->toContain($task->verification_commands[0])
        ->toContain($task->browser_steps[0])
        ->toContain('Skill 1: Browser Verification')
        ->toContain('Skill 2: Repository Safety')
        ->not->toContain('Disabled Skill')
        ->not->toContain('NEVER_INCLUDE_THIS')
        ->not->toContain('PRIOR_TRANSCRIPT_MARKER')
        ->not->toContain('PRIOR_OUTPUT_MARKER')
        ->not->toContain($task->title)
        ->not->toContain($task->objective);

    expect(array_search('Skill 1: Browser Verification', $sourceLabels, true))
        ->toBeLessThan(
            array_search('Skill 2: Repository Safety', $sourceLabels, true),
        );
});

test('Coder fix delta contains only latest QA findings unresolved acceptance criteria and new operator instruction', function () {
    $context = app(AgentContextAssembler::class)->coderFixDelta(
        ['QA_FINDING_ONLY'],
        ['UNRESOLVED_CRITERION_ONLY'],
        'NEW_OPERATOR_INSTRUCTION_ONLY',
    );

    expect($context['mode'])->toBe('delta')
        ->and($context['input'])->toContain('QA_FINDING_ONLY')
        ->toContain('UNRESOLVED_CRITERION_ONLY')
        ->toContain('NEW_OPERATOR_INSTRUCTION_ONLY')
        ->not->toContain('Project path')
        ->not->toContain('Implementation specification')
        ->not->toContain('Agent identity')
        ->not->toContain('Skill')
        ->not->toContain('previous AgentRun')
        ->and(collect($context['sources'])->pluck('type')->all())->toBe([
            'qa_findings',
            'acceptance_criteria',
            'operator_instruction',
        ]);
});

test('QA initial and re-review contexts preserve the exact initial and delta boundaries', function () {
    [$project, , $task] = feature04TaskFixture();
    $qa = $project->agents()
        ->where('role', 'quality_assurance_specialist')
        ->sole();

    $skill = $project->skills()->create([
        'name' => 'QA Verification',
        'instructions' => 'Verify observable behavior.',
        'enabled' => true,
    ]);
    $qa->skills()->sync([
        $skill->id => ['position' => 1],
    ]);

    $assembler = app(AgentContextAssembler::class);
    $initial = $assembler->qaInitial(
        $task,
        $qa,
        '/tmp/aisf-task-worktree',
        ['app/Services/Example.php', 'tests/Feature/ExampleTest.php'],
    );
    $delta = $assembler->qaRereviewDelta(
        'CODER_FIX_SUMMARY_ONLY',
        ['UNRESOLVED_FINDING_ONLY'],
        ['app/Services/Example.php'],
    );

    expect($initial['mode'])->toBe('initial')
        ->and($initial['input'])->toContain($task->implementation_spec)
        ->toContain($task->acceptance_criteria[0])
        ->toContain('/tmp/aisf-task-worktree')
        ->toContain('app/Services/Example.php')
        ->toContain($task->verification_commands[0])
        ->toContain($task->browser_steps[0])
        ->toContain('QA Verification')
        ->and($delta['mode'])->toBe('delta')
        ->and($delta['input'])->toContain('CODER_FIX_SUMMARY_ONLY')
        ->toContain('UNRESOLVED_FINDING_ONLY')
        ->toContain('app/Services/Example.php')
        ->not->toContain($task->implementation_spec)
        ->not->toContain('QA Verification')
        ->not->toContain('/tmp/aisf-task-worktree');
});

test('PM queue retry reuses one logical session creates distinct attempts and resumes only with a captured provider session', function () {
    [$project, $workRequest] = feature04PmFixture();
    $codexExecutions = 0;
    $executedCommands = [];
    $plan = feature04ValidPlan();

    Process::fake(function (PendingProcess $process) use (
        &$codexExecutions,
        &$executedCommands,
        $plan,
    ) {
        if (
            is_array($process->command)
            && ($process->command[0] ?? null) === 'git'
        ) {
            return Process::result(output: 'true');
        }

        if ($process->command === ['codex', 'exec', '--help']) {
            return Process::result(
                output: 'Usage: codex exec resume --json --output-schema',
            );
        }

        if (
            is_array($process->command)
            && ($process->command[0] ?? null) === 'codex'
        ) {
            $codexExecutions++;
            $executedCommands[] = $process->command;

            if ($codexExecutions === 1) {
                return Process::result(
                    output: json_encode([
                        'type' => 'thread.started',
                        'thread_id' => 'pm-provider-session',
                    ], JSON_THROW_ON_ERROR)."\n".json_encode([
                        'type' => 'turn.failed',
                    ], JSON_THROW_ON_ERROR),
                    exitCode: 1,
                );
            }

            return Process::result(output: implode("\n", [
                json_encode([
                    'type' => 'thread.started',
                    'thread_id' => 'pm-provider-session',
                ], JSON_THROW_ON_ERROR),
                json_encode([
                    'type' => 'item.completed',
                    'item' => [
                        'type' => 'agent_message',
                        'text' => $plan,
                    ],
                ], JSON_THROW_ON_ERROR),
                json_encode([
                    'type' => 'turn.completed',
                ], JSON_THROW_ON_ERROR),
            ]));
        }

        return Process::result();
    });

    Process::preventStrayProcesses();

    $job = new ProcessWorkRequest($workRequest);

    expect(fn () => app()->call([$job, 'handle']))
        ->toThrow(RuntimeException::class);

    app()->call([$job, 'handle']);

    $session = $workRequest->agentSessions()->sole();
    $runs = $session->runs()->get();

    expect($session->provider_session_id)->toBe('pm-provider-session')
        ->and($runs)->toHaveCount(2)
        ->and($runs[0]->attempt)->toBe(1)
        ->and($runs[0]->status)->toBe('failed')
        ->and($runs[0]->context_mode)->toBe('initial')
        ->and($runs[0]->exit_code)->toBe(1)
        ->and($runs[1]->attempt)->toBe(2)
        ->and($runs[1]->status)->toBe('succeeded')
        ->and($runs[1]->context_mode)->toBe('delta')
        ->and($runs[1]->submitted_input)->toContain(
            'PROJECT MANAGER RETRY DELTA',
        )
        ->and($runs[1]->submitted_input)->not->toContain(
            $workRequest->prompt,
        )
        ->and($workRequest->refresh()->status)->toBe('planned')
        ->and($workRequest->summary)->toBe(
            'Implement one browser-testable session activity increment.',
        )
        ->and($codexExecutions)->toBe(2);

    expect($executedCommands[1])->toContain(
        'resume',
        'pm-provider-session',
    );

    expect($project->id)->toBe($workRequest->project_id);
});

test('invalid PM structured output persists the actual model invocation as failed', function () {
    [, $workRequest] = feature04PmFixture();

    Process::fake(function (PendingProcess $process) {
        if (
            is_array($process->command)
            && ($process->command[0] ?? null) === 'git'
        ) {
            return Process::result(output: 'true');
        }

        if ($process->command === ['codex', 'exec', '--help']) {
            return Process::result(
                output: 'Usage: codex exec resume --json --output-schema',
            );
        }

        return Process::result(output: implode("\n", [
            json_encode([
                'type' => 'thread.started',
                'thread_id' => 'invalid-pm-session',
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'type' => 'item.completed',
                'item' => [
                    'type' => 'agent_message',
                    'text' => '{not-json',
                ],
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'type' => 'turn.completed',
            ], JSON_THROW_ON_ERROR),
        ]));
    });

    Process::preventStrayProcesses();

    app()->call([new ProcessWorkRequest($workRequest), 'handle']);

    $run = $workRequest->agentSessions()
        ->sole()
        ->runs()
        ->sole();

    expect($workRequest->refresh()->status)->toBe('failed')
        ->and($run->status)->toBe('failed')
        ->and($run->attempt)->toBe(1)
        ->and($run->output_summary)->toContain('malformed JSON')
        ->and($run->finished_at)->not->toBeNull();
});

test('Project workspace exposes safe PM and Task session run activity including exact submitted deltas', function () {
    [$project, $workRequest, $task, $coder] = feature04TaskFixture();
    $manager = app(AgentSessionManager::class);
    $pm = $project->agents()
        ->where('role', 'project_manager')
        ->sole();

    $pmSession = $manager->forSubject($pm, $workRequest);
    $pmRun = $manager->startRun(
        $pmSession,
        'project_manager_planning',
        [
            'mode' => 'initial',
            'input' => 'PM_EXACT_INPUT',
            'sources' => [
                ['type' => 'work_request', 'label' => 'Original WorkRequest'],
            ],
        ],
    );
    $manager->completeRun($pmRun, 'PM planning complete.', 0);
    $manager->captureProviderSessionId(
        $pmSession,
        'provider-session-hidden-from-ui',
    );

    $coderSession = $manager->forSubject($coder, $task);
    $initialRun = $manager->startRun(
        $coderSession,
        'coder_initial',
        [
            'mode' => 'initial',
            'input' => 'CODER_INITIAL_INPUT',
            'sources' => [
                ['type' => 'skill', 'label' => 'Skill 1: Browser Verification'],
            ],
        ],
    );
    $manager->completeRun($initialRun, 'Initial Coder run complete.', 0);

    $deltaRun = $manager->startRun(
        $coderSession,
        'coder_fix',
        [
            'mode' => 'delta',
            'input' => 'LATEST_QA_DELTA_ONLY',
            'sources' => [
                ['type' => 'qa_findings', 'label' => 'Latest QA findings'],
            ],
        ],
    );
    $manager->completeRun($deltaRun, 'Coder fix complete.', 0);

    $project->update(['enabled' => false]);

    $response = $this->get(route('projects.show', $project));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('projects/show')
        ->where('workRequests.0.agent_sessions.0.agent.role', 'project_manager')
        ->where('workRequests.0.agent_sessions.0.has_provider_continuity', true)
        ->where('workRequests.0.agent_sessions.0.runs.0.output_summary', 'PM planning complete.')
        ->where('workRequests.0.tasks.0.agent_sessions.0.agent.role', 'coder')
        ->where('workRequests.0.tasks.0.agent_sessions.0.runs.0.attempt', 2)
        ->where('workRequests.0.tasks.0.agent_sessions.0.runs.0.context_mode', 'delta')
        ->where('workRequests.0.tasks.0.agent_sessions.0.runs.0.submitted_input', 'LATEST_QA_DELTA_ONLY')
        ->where('workRequests.0.tasks.0.agent_sessions.0.runs.0.context_sources.0.label', 'Latest QA findings')
        ->where('workRequests.0.tasks.0.agent_sessions.0.runs.1.attempt', 1)
        ->where('workRequests.0.tasks.0.agent_sessions.0.runs.1.context_sources.0.label', 'Skill 1: Browser Verification')
        ->missing('workRequests.0.agent_sessions.0.provider_session_id')
        ->missing('workRequests.0.agent_sessions.0.runs.0.raw_output_reference'));
});

/**
 * Create one Project with default Agents, one WorkRequest, and one browser-testable Task.
 *
 * @return array{0: Project, 1: WorkRequest, 2: Task, 3: ProjectAgent}
 */
function feature04TaskFixture(): array
{
    $project = Project::factory()->create([
        'title' => 'AISF Feature 04 Project',
        'description' => 'Feature 04 persistent session project.',
        'path' => '/tmp/aisf-feature04-project',
    ]);

    app(ProjectAgentProvisioner::class)->ensureFor($project);

    $coder = $project->agents()
        ->where('role', 'coder')
        ->sole();

    $coder->update([
        'identity' => 'Coder identity',
        'default_context' => 'Coder default context',
        'workflow_instructions' => 'Coder workflow',
        'enabled' => true,
    ]);

    $qa = $project->agents()
        ->where('role', 'quality_assurance_specialist')
        ->sole();

    $qa->update([
        'identity' => 'QA identity',
        'default_context' => 'QA default context',
        'workflow_instructions' => 'QA workflow',
        'enabled' => true,
    ]);

    $workRequest = $project->workRequests()->create([
        'prompt' => 'Implement persistent Agent sessions.',
        'status' => 'planned',
        'summary' => 'Feature 04 plan.',
    ]);

    $task = $workRequest->tasks()->create([
        'position' => 1,
        'title' => 'Persist Agent sessions',
        'objective' => 'Expose durable Agent continuity.',
        'implementation_spec' => 'Implement minimal AgentSession and AgentRun persistence.',
        'acceptance_criteria' => [
            'One logical session is reused for the same Agent and Task.',
        ],
        'verification_commands' => [
            'php artisan test tests/Feature/AgentSessionTest.php',
        ],
        'browser_steps' => [
            'Open the Project workspace in the browser.',
            'Confirm Agent session and run activity is visible on the Task.',
        ],
    ]);

    return [$project, $workRequest, $task, $coder];
}

/**
 * Create one inspectable Project and submitted WorkRequest for PM execution tests.
 *
 * @return array{0: Project, 1: WorkRequest}
 */
function feature04PmFixture(): array
{
    $path = sys_get_temp_dir().'/aisf-feature04-'.Str::uuid();
    File::makeDirectory($path);

    $project = Project::factory()->create([
        'title' => 'AISF Feature 04 PM Project',
        'description' => 'Feature 04 PM persistence fixture.',
        'path' => $path,
    ]);

    app(ProjectAgentProvisioner::class)->ensureFor($project);

    $pm = $project->agents()
        ->where('role', 'project_manager')
        ->sole();

    $pm->update([
        'identity' => 'Feature 04 PM identity',
        'default_context' => 'Feature 04 PM context',
        'workflow_instructions' => 'Feature 04 PM workflow',
        'harness' => 'codex',
        'enabled' => true,
    ]);

    $workRequest = $project->workRequests()->create([
        'prompt' => 'Show persistent Agent activity.',
        'status' => 'submitted',
    ]);

    return [$project, $workRequest];
}

/**
 * Encode one valid browser-testable PM plan.
 */
function feature04ValidPlan(): string
{
    return json_encode([
        'summary' => 'Implement one browser-testable session activity increment.',
        'already_implemented' => false,
        'already_implemented_reason' => null,
        'tasks' => [
            [
                'title' => 'Show Agent activity',
                'objective' => 'Expose persisted Agent sessions in the Project workspace.',
                'implementation_spec' => 'Render Agent sessions and recent runs on existing Task cards.',
                'acceptance_criteria' => [
                    'Agent sessions and runs are visible.',
                ],
                'verification_commands' => [
                    'php artisan test tests/Feature/AgentSessionTest.php',
                ],
                'browser_test_steps' => [
                    'Open the Project workspace in the browser.',
                    'Confirm the Task shows its Agent session and recent run.',
                ],
                'depends_on_position' => null,
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}
