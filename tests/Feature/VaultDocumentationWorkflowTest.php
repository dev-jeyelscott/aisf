<?php

use App\Models\AgentRun;
use App\Models\AgentRunAction;
use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\Task;
use App\Models\WorkRequest;
use App\Services\AgentExecutionRunner;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResult;
use App\Services\AgentRunActionRecorder;
use App\Services\AgentSessionManager;
use App\Services\ProjectAgentProvisioner;
use App\Services\RepairCycleGuard;
use App\Services\TaskCandidateFingerprint;
use App\Services\TaskCommitIntegrator;
use App\Services\TaskWorkflowService;
use App\Services\TaskWorktreeManager;
use App\Services\VaultDocumentationService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use UnexpectedValueException;

use function Pest\Laravel\mock;

/**
 * Capture the repository-write capability passed to the provider harness for each role.
 */
class VaultDocumentationWorkflowHarness extends AgentHarness
{
    /** @var list<array{role: string, writable: bool}> */
    public array $calls = [];

    public function __construct() {}

    /**
     * Force each capability check through a fresh provider turn.
     */
    public function canResume(ProjectAgent $agent): bool
    {
        return false;
    }

    /**
     * Record the role and repository-write capability without invoking an external provider.
     */
    public function start(
        ProjectAgent $agent,
        string $repositoryPath,
        string $prompt,
        ?array $schema = null,
        bool $writable = false,
    ): AgentHarnessResult {
        $this->calls[] = [
            'role' => (string) $agent->role,
            'writable' => $writable,
        ];

        return new AgentHarnessResult(
            successful: true,
            output: 'Capability check completed.',
            providerSessionId: null,
            exitCode: 0,
        );
    }
}

beforeEach(function (): void {
    $this->vaultDocumentationWorkflowPath = storage_path(
        'framework/testing/vault-workflow/'.Str::uuid(),
    );

    File::ensureDirectoryExists(
        $this->vaultDocumentationWorkflowPath.'/Work Logs',
    );

    File::put(
        $this->vaultDocumentationWorkflowPath.'/AGENTS.md',
        "Root AISF regression-test governance.\n",
    );

    config()->set(
        'aisf.obsidian_vault_path',
        $this->vaultDocumentationWorkflowPath,
    );
});

afterEach(function (): void {
    if (
        isset($this->vaultDocumentationWorkflowPath)
        && is_string($this->vaultDocumentationWorkflowPath)
    ) {
        $workLogs = $this->vaultDocumentationWorkflowPath.'/Work Logs';

        if (is_dir($workLogs)) {
            @chmod($workLogs, 0700);
        }

        if (is_dir($this->vaultDocumentationWorkflowPath)) {
            File::deleteDirectory(
                $this->vaultDocumentationWorkflowPath,
            );
        }
    }
});

test('PM to Coder to QA to approved finalization requires one ordered vault note per AgentRun', function (): void {
    [$project, $workRequest, $pmRun] = vaultWorkflowProjectManagerRun();

    $sentinel = 'AISF-VAULT-NOTE-BODY-FILESYSTEM-ONLY';

    $worktreeManager = mock(TaskWorktreeManager::class);
    $worktreeManager
        ->shouldReceive('ensureWorktree')
        ->once();
    $worktreeManager
        ->shouldReceive('assertNoCommitBeforeQa')
        ->twice();
    $worktreeManager
        ->shouldReceive('changedFiles')
        ->once()
        ->andReturn(['app/Services/Example.php']);
    $worktreeManager
        ->shouldReceive('verifyCommitExists')
        ->once()
        ->andReturn('commit-sha-1');
    $worktreeManager
        ->shouldReceive('verifyHeadMatches')
        ->once();
    $worktreeManager
        ->shouldReceive('runCiCheck')
        ->once()
        ->andReturn([
            'passed' => true,
            'output' => '',
        ]);
    $worktreeManager
        ->shouldReceive('pushAndOpenPullRequest')
        ->once()
        ->andReturn([
            'commit_sha' => 'commit-sha-1',
            'pull_request_url' => 'https://github.com/example/project/pull/1',
        ]);

    $candidateFingerprint = mock(TaskCandidateFingerprint::class);
    $candidateFingerprint
        ->shouldReceive('forTask')
        ->once()
        ->andReturn([
            'tree_sha' => 'candidate-tree-1',
            'base_tree_sha' => 'base-tree-1',
            'kind' => 'changes',
        ]);
    $candidateFingerprint
        ->shouldReceive('currentTreeSha')
        ->twice()
        ->andReturn('candidate-tree-1');
    $candidateFingerprint
        ->shouldReceive('commitTreeSha')
        ->once()
        ->andReturn('candidate-tree-1');

    $task = vaultWorkflowSaveTask(
        $pmRun,
        $workRequest,
    );

    $pmMarkdown = "# PM work note\n\n{$sentinel}:PM\n";

    vaultWorkflowWriteNote(
        $pmRun,
        'pm-'.$pmRun->id.'.md',
        $pmMarkdown,
    );

    app(TaskWorkflowService::class)->handoff(
        $pmRun,
        $task,
        'coder',
        'implementation_ready',
        'pm-coder-'.$pmRun->id,
        [],
        (string) $pmRun->execution_token,
    );

    app(AgentSessionManager::class)->completeRun(
        $pmRun,
        'PM durable actions completed.',
        0,
    );

    $workRequest->update([
        'status' => 'waiting',
    ]);

    $task = $task->refresh();
    $coderRun = vaultWorkflowStartTaskRun(
        $task,
        'coder',
    );

    app(TaskWorkflowService::class)->saveResult(
        $coderRun,
        $task,
        [
            'summary' => 'Implemented the requested change.',
            'validation' => [
                [
                    'command' => 'php artisan test --compact',
                    'passed' => true,
                ],
            ],
        ],
        (string) $coderRun->execution_token,
    );

    $task = $task->refresh();
    $coderMarkdown = "# Coder work note\n\n{$sentinel}:CODER\n";

    vaultWorkflowWriteNote(
        $coderRun,
        'coder-'.$coderRun->id.'.md',
        $coderMarkdown,
    );

    app(TaskWorkflowService::class)->handoff(
        $coderRun,
        $task,
        'qa',
        'ready_for_review',
        'coder-qa-'.$coderRun->id,
        [],
        (string) $coderRun->execution_token,
    );

    app(AgentSessionManager::class)->completeRun(
        $coderRun,
        'Coder durable actions completed.',
        0,
    );

    $task = $task->refresh();
    $qaRun = vaultWorkflowStartTaskRun(
        $task,
        'qa',
    );

    app(TaskWorkflowService::class)->saveReview(
        $qaRun,
        $task,
        'candidate-tree-1',
        'approved',
        'The immutable candidate is approved.',
        [],
        (string) $qaRun->execution_token,
    );

    $qaMarkdown = "# QA work note\n\n{$sentinel}:QA\n";

    vaultWorkflowWriteNote(
        $qaRun,
        'qa-'.$qaRun->id.'.md',
        $qaMarkdown,
    );

    app(TaskWorkflowService::class)->handoff(
        $qaRun,
        $task->refresh(),
        'coder',
        'approved',
        'qa-approved-'.$qaRun->id,
        [],
        (string) $qaRun->execution_token,
    );

    app(AgentSessionManager::class)->completeRun(
        $qaRun,
        'QA durable actions completed.',
        0,
    );

    $task = $task->refresh();
    $finalizerRun = vaultWorkflowStartTaskRun(
        $task,
        'coder',
    );

    expect(
        app(AgentRunActionRecorder::class)
            ->hasVaultNoteWritten($coderRun->refresh()),
    )->toBeTrue();

    expect(
        app(AgentRunActionRecorder::class)
            ->hasVaultNoteWritten($qaRun->refresh()),
    )->toBeTrue();

    expect(
        app(AgentRunActionRecorder::class)
            ->hasVaultNoteWritten($finalizerRun),
    )->toBeFalse();

    expect(
        fn () => app(TaskCommitIntegrator::class)->finalize(
            $task,
            $finalizerRun,
            'commit-sha-1',
            'Finalize the approved candidate.',
            (string) $finalizerRun->execution_token,
        ),
    )->toThrow(
        UnexpectedValueException::class,
        'The AgentRun must write its vault work note before completing this workflow transition.',
    );

    expect($task->refresh()->status)
        ->toBe('running');

    expect(
        $finalizerRun->actions()
            ->where(
                'action',
                AgentRunAction::ACTION_CANDIDATE_FINALIZED,
            )
            ->count(),
    )->toBe(0);

    expect(
        $finalizerRun->actions()
            ->where(
                'action',
                AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED,
            )
            ->count(),
    )->toBe(0);

    $finalizerMarkdown = "# Finalizer work note\n\n{$sentinel}:FINALIZER\n";

    vaultWorkflowWriteNote(
        $finalizerRun,
        'finalizer-'.$finalizerRun->id.'.md',
        $finalizerMarkdown,
    );

    app(TaskCommitIntegrator::class)->finalize(
        $task->refresh(),
        $finalizerRun,
        'commit-sha-1',
        'Finalize the approved candidate.',
        (string) $finalizerRun->execution_token,
    );

    app(AgentSessionManager::class)->completeRun(
        $finalizerRun,
        'Approved finalization completed.',
        0,
    );

    $task = $task->refresh();
    $workRequest = $workRequest->refresh();

    expect($task->status)
        ->toBe('completed')
        ->and($task->outcome)->toBe('implemented')
        ->and($task->candidate_tree_sha)->toBe('candidate-tree-1')
        ->and($task->candidate_created_by_run_id)->toBe($coderRun->id)
        ->and($task->commit_sha)->toBe('commit-sha-1')
        ->and($task->pull_request_url)
        ->toBe('https://github.com/example/project/pull/1');

    expect($workRequest->status)
        ->toBe('completed')
        ->and($workRequest->outcome)->toBe('implemented');

    foreach (
        [$pmRun, $coderRun, $qaRun, $finalizerRun] as $successfulRun
    ) {
        $successfulRun->refresh();

        expect($successfulRun->status)
            ->toBe('succeeded');

        expect(
            $successfulRun->actions()
                ->where(
                    'action',
                    AgentRunAction::ACTION_VAULT_NOTE_WRITTEN,
                )
                ->count(),
        )->toBe(1);

        $noteAction = $successfulRun->actions()
            ->where(
                'action',
                AgentRunAction::ACTION_VAULT_NOTE_WRITTEN,
            )
            ->sole();

        expect($noteAction->resource_type)
            ->toBe(AgentRunAction::RESOURCE_AGENT_RUN);

        expect($noteAction->resource_id)
            ->toBe($successfulRun->id);
    }

    vaultWorkflowAssertNoteBefore(
        $pmRun,
        AgentRunAction::ACTION_HANDOFF_CREATED,
    );

    vaultWorkflowAssertNoteBefore(
        $coderRun,
        AgentRunAction::ACTION_HANDOFF_CREATED,
    );

    vaultWorkflowAssertNoteBefore(
        $qaRun,
        AgentRunAction::ACTION_HANDOFF_CREATED,
    );

    vaultWorkflowAssertNoteBefore(
        $finalizerRun,
        AgentRunAction::ACTION_CANDIDATE_FINALIZED,
    );

    vaultWorkflowAssertNoteBefore(
        $finalizerRun,
        AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED,
    );

    expect(
        File::get(
            $this->vaultDocumentationWorkflowPath
                .'/Work Logs/pm-'.$pmRun->id.'.md',
        ),
    )->toBe($pmMarkdown);

    expect(
        File::get(
            $this->vaultDocumentationWorkflowPath
                .'/Work Logs/coder-'.$coderRun->id.'.md',
        ),
    )->toBe($coderMarkdown);

    expect(
        File::get(
            $this->vaultDocumentationWorkflowPath
                .'/Work Logs/qa-'.$qaRun->id.'.md',
        ),
    )->toBe($qaMarkdown);

    expect(
        File::get(
            $this->vaultDocumentationWorkflowPath
                .'/Work Logs/finalizer-'.$finalizerRun->id.'.md',
        ),
    )->toBe($finalizerMarkdown);

    expect(
        File::files(
            $this->vaultDocumentationWorkflowPath.'/Work Logs',
        ),
    )->toHaveCount(4);

    $runIds = collect([
        $pmRun,
        $coderRun,
        $qaRun,
        $finalizerRun,
    ])
        ->pluck('id')
        ->all();

    $databaseSnapshot = [
        'runs' => AgentRun::query()
            ->whereIn('id', $runIds)
            ->get()
            ->toArray(),
        'actions' => AgentRunAction::query()
            ->whereIn('agent_run_id', $runIds)
            ->get()
            ->toArray(),
        'task' => $task->toArray(),
        'work_request' => $workRequest->toArray(),
        'reviews' => $task->candidateReviews()
            ->get()
            ->toArray(),
        'handoffs' => $task->handoffs()
            ->get()
            ->toArray(),
    ];

    expect(
        json_encode(
            $databaseSnapshot,
            JSON_THROW_ON_ERROR,
        ),
    )->not->toContain($sentinel);
});

test('QA repair creates a new Coder AgentRun that requires its own vault note', function (): void {
    [, $workRequest, $pmRun] = vaultWorkflowProjectManagerRun();

    $worktreeManager = mock(TaskWorktreeManager::class);
    $worktreeManager
        ->shouldReceive('ensureWorktree')
        ->twice();
    $worktreeManager
        ->shouldReceive('assertNoCommitBeforeQa')
        ->times(5);
    $worktreeManager
        ->shouldReceive('changedFiles')
        ->twice()
        ->andReturn(
            ['app/Services/Initial.php'],
            ['app/Services/Repaired.php'],
        );

    mock(TaskCandidateFingerprint::class)
        ->shouldReceive('forTask')
        ->twice()
        ->andReturn(
            [
                'tree_sha' => 'candidate-tree-1',
                'base_tree_sha' => 'base-tree-1',
                'kind' => 'changes',
            ],
            [
                'tree_sha' => 'candidate-tree-2',
                'base_tree_sha' => 'base-tree-1',
                'kind' => 'changes',
            ],
        );

    $task = vaultWorkflowSaveTask(
        $pmRun,
        $workRequest,
    );

    vaultWorkflowWriteNote(
        $pmRun,
        'repair-pm-'.$pmRun->id.'.md',
        "# PM repair-flow note\n",
    );

    app(TaskWorkflowService::class)->handoff(
        $pmRun,
        $task,
        'coder',
        'implementation_ready',
        'repair-pm-coder-'.$pmRun->id,
        [],
        (string) $pmRun->execution_token,
    );

    app(AgentSessionManager::class)->completeRun(
        $pmRun,
        'PM repair-flow handoff completed.',
        0,
    );

    $workRequest->update([
        'status' => 'waiting',
    ]);

    $task = $task->refresh();
    $firstCoderRun = vaultWorkflowStartTaskRun(
        $task,
        'coder',
    );

    app(TaskWorkflowService::class)->saveResult(
        $firstCoderRun,
        $task,
        [
            'summary' => 'Produced the first candidate.',
            'validation' => [],
        ],
        (string) $firstCoderRun->execution_token,
    );

    vaultWorkflowWriteNote(
        $firstCoderRun,
        'repair-coder-first-'.$firstCoderRun->id.'.md',
        "# First Coder note\n",
    );

    app(TaskWorkflowService::class)->handoff(
        $firstCoderRun,
        $task->refresh(),
        'qa',
        'ready_for_review',
        'repair-first-coder-qa-'.$firstCoderRun->id,
        [],
        (string) $firstCoderRun->execution_token,
    );

    app(AgentSessionManager::class)->completeRun(
        $firstCoderRun,
        'First candidate handed to QA.',
        0,
    );

    $task = $task->refresh();
    $qaRun = vaultWorkflowStartTaskRun(
        $task,
        'qa',
    );

    app(TaskWorkflowService::class)->saveReview(
        $qaRun,
        $task,
        'candidate-tree-1',
        'changes_requested',
        'The candidate requires one repair.',
        ['Handle the regression.'],
        (string) $qaRun->execution_token,
    );

    vaultWorkflowWriteNote(
        $qaRun,
        'repair-qa-'.$qaRun->id.'.md',
        "# QA repair note\n",
    );

    app(TaskWorkflowService::class)->handoff(
        $qaRun,
        $task->refresh(),
        'coder',
        'changes_requested',
        'repair-qa-coder-'.$qaRun->id,
        [
            'findings' => [
                'Handle the regression.',
            ],
        ],
        (string) $qaRun->execution_token,
    );

    app(AgentSessionManager::class)->completeRun(
        $qaRun,
        'QA requested a repair.',
        0,
    );

    $task = $task->refresh();

    expect(
        app(RepairCycleGuard::class)
            ->repairCycleCount($task),
    )->toBe(1);

    $repairRun = vaultWorkflowStartTaskRun(
        $task,
        'coder',
    );

    expect($repairRun->id)
        ->not->toBe($firstCoderRun->id);

    expect($repairRun->agent_session_id)
        ->toBe($firstCoderRun->agent_session_id);

    expect($repairRun->attempt)
        ->toBe($firstCoderRun->attempt + 1);

    expect($repairRun->execution_token)
        ->not->toBe($firstCoderRun->execution_token);

    expect(
        app(AgentRunActionRecorder::class)
            ->hasVaultNoteWritten($firstCoderRun->refresh()),
    )->toBeTrue();

    expect(
        app(AgentRunActionRecorder::class)
            ->hasVaultNoteWritten($repairRun),
    )->toBeFalse();

    app(TaskWorkflowService::class)->saveResult(
        $repairRun,
        $task,
        [
            'summary' => 'Produced the repaired candidate.',
            'validation' => [],
        ],
        (string) $repairRun->execution_token,
    );

    $task = $task->refresh();

    expect($task->candidate_tree_sha)
        ->toBe('candidate-tree-2')
        ->and($task->candidate_created_by_run_id)
        ->toBe($repairRun->id);

    expect(
        fn () => app(TaskWorkflowService::class)->handoff(
            $repairRun,
            $task,
            'qa',
            'ready_for_review',
            'repair-coder-qa-'.$repairRun->id,
            [],
            (string) $repairRun->execution_token,
        ),
    )->toThrow(
        UnexpectedValueException::class,
        'The AgentRun must write its vault work note before completing this workflow transition.',
    );

    expect(
        $task->handoffs()
            ->where(
                'from_agent_run_id',
                $repairRun->id,
            )
            ->count(),
    )->toBe(0);

    vaultWorkflowWriteNote(
        $repairRun,
        'repair-coder-second-'.$repairRun->id.'.md',
        "# Repair Coder note\n",
    );

    app(TaskWorkflowService::class)->handoff(
        $repairRun,
        $task->refresh(),
        'qa',
        'ready_for_review',
        'repair-coder-qa-'.$repairRun->id,
        [],
        (string) $repairRun->execution_token,
    );

    expect(
        $repairRun->actions()
            ->where(
                'action',
                AgentRunAction::ACTION_VAULT_NOTE_WRITTEN,
            )
            ->count(),
    )->toBe(1);

    expect(
        $repairRun->actions()
            ->where(
                'action',
                AgentRunAction::ACTION_HANDOFF_CREATED,
            )
            ->count(),
    )->toBe(1);

    vaultWorkflowAssertNoteBefore(
        $repairRun,
        AgentRunAction::ACTION_HANDOFF_CREATED,
    );

    expect(
        app(RepairCycleGuard::class)
            ->repairCycleCount($task->refresh()),
    )->toBe(1);
});

test('repository write capability remains read only for PM and QA and writable only for Coder turns', function (): void {
    $project = Project::factory()->create([
        'path' => base_path(),
    ]);

    app(ProjectAgentProvisioner::class)->ensureFor(
        $project,
    );

    $workRequest = $project->workRequests()->create([
        'prompt' => 'Verify Agent repository capabilities.',
        'status' => 'running',
    ]);

    $harness = new VaultDocumentationWorkflowHarness;

    app()->instance(
        AgentHarness::class,
        $harness,
    );

    $runner = app(AgentExecutionRunner::class);

    $runner->run($workRequest);

    $task = $workRequest->tasks()->create([
        'position' => 1,
        'title' => 'Verify Agent repository capabilities',
        'objective' => 'Verify repository access.',
        'implementation_spec' => '',
        'acceptance_criteria' => [],
        'verification_commands' => [],
        'browser_steps' => [],
        'status' => 'running',
        'last_handoff' => [
            'to_role' => 'coder',
            'reason' => 'implementation_ready',
        ],
    ]);

    $runner->run($task);

    $task->update([
        'last_handoff' => [
            'to_role' => 'qa',
            'reason' => 'ready_for_review',
        ],
    ]);

    $runner->run($task->refresh());

    $task->update([
        'last_handoff' => [
            'to_role' => 'coder',
            'reason' => 'approved',
        ],
    ]);

    $runner->run($task->refresh());

    expect($harness->calls)->toBe([
        [
            'role' => 'project_manager',
            'writable' => false,
        ],
        [
            'role' => 'coder',
            'writable' => true,
        ],
        [
            'role' => 'qa',
            'writable' => false,
        ],
        [
            'role' => 'coder',
            'writable' => true,
        ],
    ]);
});

test('an unwritable note destination after preflight prevents the PM handoff until permissions are restored', function (): void {
    [, $workRequest, $pmRun] = vaultWorkflowProjectManagerRun();

    $task = vaultWorkflowSaveTask(
        $pmRun,
        $workRequest,
    );

    app(VaultDocumentationService::class)->preflight(
        $pmRun,
        (string) $pmRun->execution_token,
    );

    $workLogDirectory = $this->vaultDocumentationWorkflowPath
        .'/Work Logs';

    if (! @chmod($workLogDirectory, 0500)) {
        $this->markTestSkipped(
            'The test environment does not permit changing directory permissions.',
        );
    }

    clearstatcache(
        true,
        $workLogDirectory,
    );

    if (is_writable($workLogDirectory)) {
        @chmod($workLogDirectory, 0700);

        $this->markTestSkipped(
            'The test runtime can still write to a mode-0500 directory, commonly because it is running as root.',
        );
    }

    $markdown = "# Permission recovery\n\nThe write must fail first.\n";

    try {
        expect(
            fn () => app(VaultDocumentationService::class)
                ->writeWorkLog(
                    $pmRun,
                    (string) $pmRun->execution_token,
                    'Work Logs/permission-recovery.md',
                    $markdown,
                ),
        )->toThrow(
            UnexpectedValueException::class,
            'The vault work note parent directory must be writable.',
        );

        expect(
            fn () => app(TaskWorkflowService::class)->handoff(
                $pmRun,
                $task,
                'coder',
                'implementation_ready',
                'permission-pm-coder-'.$pmRun->id,
                [],
                (string) $pmRun->execution_token,
            ),
        )->toThrow(
            UnexpectedValueException::class,
            'The AgentRun must write its vault work note before completing this workflow transition.',
        );

        expect(
            File::exists(
                $workLogDirectory
                    .'/permission-recovery.md',
            ),
        )->toBeFalse();

        expect(
            $pmRun->actions()
                ->where(
                    'action',
                    AgentRunAction::ACTION_VAULT_NOTE_WRITTEN,
                )
                ->count(),
        )->toBe(0);

        expect($task->handoffs()->count())
            ->toBe(0);
    } finally {
        @chmod($workLogDirectory, 0700);

        clearstatcache(
            true,
            $workLogDirectory,
        );
    }

    $metadata = app(VaultDocumentationService::class)
        ->writeWorkLog(
            $pmRun,
            (string) $pmRun->execution_token,
            'Work Logs/permission-recovery.md',
            $markdown,
        );

    app(TaskWorkflowService::class)->handoff(
        $pmRun,
        $task,
        'coder',
        'implementation_ready',
        'permission-pm-coder-'.$pmRun->id,
        [],
        (string) $pmRun->execution_token,
    );

    expect(
        File::get(
            $workLogDirectory
                .'/permission-recovery.md',
        ),
    )->toBe($markdown);

    expect(
        $pmRun->refresh()
            ->execution_metadata['vault_work_note'],
    )->toBe($metadata);

    expect(
        $pmRun->actions()
            ->where(
                'action',
                AgentRunAction::ACTION_VAULT_NOTE_WRITTEN,
            )
            ->count(),
    )->toBe(1);

    expect($task->refresh()->handoffs()->count())
        ->toBe(1);

    vaultWorkflowAssertNoteBefore(
        $pmRun,
        AgentRunAction::ACTION_HANDOFF_CREATED,
    );
});

/**
 * Create one Project, running WorkRequest, and active Project Manager AgentRun.
 *
 * @return array{0: Project, 1: WorkRequest, 2: AgentRun}
 */
function vaultWorkflowProjectManagerRun(): array
{
    $project = Project::factory()->create([
        'path' => base_path(),
    ]);

    app(ProjectAgentProvisioner::class)->ensureFor(
        $project,
    );

    $workRequest = $project->workRequests()->create([
        'prompt' => 'Exercise the complete documented workflow.',
        'status' => 'running',
    ]);

    $projectManager = $project->agents()
        ->where('role', 'project_manager')
        ->sole();

    $sessions = app(AgentSessionManager::class);

    $run = $sessions->startRun(
        $sessions->forSubject(
            $projectManager,
            $workRequest,
        ),
        'project_manager',
        [
            'mode' => 'initial',
            'input' => 'Plan the WorkRequest.',
            'sources' => [],
            'agent_snapshot' => [],
            'prompt_snapshot' => [],
            'role' => 'project_manager',
        ],
    );

    return [
        $project,
        $workRequest,
        $run,
    ];
}

/**
 * Persist the single Task used by the focused workflow regression.
 */
function vaultWorkflowSaveTask(
    AgentRun $pmRun,
    WorkRequest $workRequest,
): Task {
    return app(TaskWorkflowService::class)
        ->savePlan(
            $pmRun,
            $workRequest,
            [
                [
                    'title' => 'Implement the documented workflow regression',
                    'objective' => 'Exercise PM, Coder, QA, and finalization.',
                    'implementation_spec' => 'Preserve the current durable workflow.',
                    'acceptance_criteria' => [
                        'The documented workflow completes.',
                    ],
                    'verification_commands' => [
                        'php artisan test --compact',
                    ],
                    'browser_steps' => [],
                    'depends_on_position' => null,
                ],
            ],
            (string) $pmRun->execution_token,
        )[0];
}

/**
 * Start a fresh role-specific Task AgentRun accepting the Task's current durable handoff.
 */
function vaultWorkflowStartTaskRun(
    Task $task,
    string $role,
): AgentRun {
    $task = $task->fresh();

    $task->loadMissing(
        'workRequest.project',
    );

    $agent = $task->workRequest
        ->project
        ->agents()
        ->where('role', $role)
        ->sole();

    $task->update([
        'status' => 'running',
    ]);

    $sessions = app(AgentSessionManager::class);

    $run = $sessions->startRun(
        $sessions->forSubject(
            $agent,
            $task,
        ),
        $role,
        [
            'mode' => 'initial',
            'input' => 'Execute the accepted durable handoff.',
            'sources' => [],
            'agent_snapshot' => [],
            'prompt_snapshot' => [],
            'role' => $role,
        ],
    );

    $lastHandoff = $task->fresh()->last_handoff;

    $run->update([
        'execution_metadata' => [
            'accepted_handoff_id' => $lastHandoff['id']
                ?? null,
            'execution_mode' => $lastHandoff['reason']
                ?? 'implementation_ready',
        ],
    ]);

    return $run->fresh();
}

/**
 * Persist one exact Agent-authored vault note for the supplied active AgentRun.
 *
 * @return array{relative_path: string, sha256: string, timestamp: string}
 */
function vaultWorkflowWriteNote(
    AgentRun $run,
    string $filename,
    string $markdown,
): array {
    return app(VaultDocumentationService::class)
        ->writeWorkLog(
            $run,
            (string) $run->execution_token,
            'Work Logs/'.$filename,
            $markdown,
        );
}

/**
 * Assert that one run's successful vault-note evidence predates its workflow-ending action.
 */
function vaultWorkflowAssertNoteBefore(
    AgentRun $run,
    string $transitionAction,
): void {
    $note = $run->actions()
        ->where(
            'action',
            AgentRunAction::ACTION_VAULT_NOTE_WRITTEN,
        )
        ->sole();

    $transition = $run->actions()
        ->where(
            'action',
            $transitionAction,
        )
        ->oldest('id')
        ->firstOrFail();

    expect($note->id)
        ->toBeLessThan($transition->id);
}
