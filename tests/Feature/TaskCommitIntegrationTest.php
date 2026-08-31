<?php

use App\Jobs\ProcessTaskCommit;
use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\Task;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResult;
use App\Services\ProjectAgentProvisioner;
use App\Services\TaskWorktreeManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class Feature07FakeAgentHarness extends AgentHarness
{
    /** @var list<string> */
    public array $modes = [];

    /** @param callable(string): void $commitWorktree */
    public function __construct(private readonly mixed $commitWorktree) {}

    public function start(ProjectAgent $agent, string $repositoryPath, string $prompt, ?array $schema = null, bool $writable = false): AgentHarnessResult
    {
        return $this->complete($repositoryPath, 'start');
    }

    public function resume(ProjectAgent $agent, string $repositoryPath, string $providerSessionId, string $prompt, ?array $schema = null, bool $writable = false): AgentHarnessResult
    {
        return $this->complete($repositoryPath, 'resume');
    }

    private function complete(string $repositoryPath, string $mode): AgentHarnessResult
    {
        $this->modes[] = $mode;
        ($this->commitWorktree)($repositoryPath);

        $sha = trim(Process::path($repositoryPath)->run(['git', 'rev-parse', 'HEAD'])->output());
        $message = trim(Process::path($repositoryPath)->run(['git', 'log', '-1', '--format=%s'])->output());

        return new AgentHarnessResult(true, json_encode(['commit_sha' => $sha, 'commit_message' => $message], JSON_THROW_ON_ERROR), 'feature-07-existing-coder-session', 0);
    }
}

test('an approved Task is committed by the resumed Coder, fast-forwarded, and cleaned up', function () {
    [$project, $task] = feature07ApprovedTask();
    $harness = feature07FakeHarness('feat(tasks): integrate approved work');

    app()->call([new ProcessTaskCommit($task), 'handle']);

    $task->refresh();
    $projectHead = trim(Process::path($project->path)->run(['git', 'rev-parse', 'HEAD'])->output());
    $coderRun = $task->agentSessions()
        ->whereHas('projectAgent', fn ($query) => $query->where('role', 'coder'))
        ->sole()
        ->runs()
        ->where('purpose', 'coder_commit')
        ->sole();

    expect($task->status)->toBe('done')
        ->and($task->commit_sha)->toBe($projectHead)
        ->and($task->integrated_sha)->toBe($projectHead)
        ->and($task->commit_message)->toBe('feat(tasks): integrate approved work')
        ->and($task->worktree_cleaned_at)->not->toBeNull()
        ->and($task->branch_deleted_at)->not->toBeNull()
        ->and(is_dir((string) $task->worktree_path))->toBeFalse()
        ->and($coderRun->purpose)->toBe('coder_commit')
        ->and($coderRun->context_mode)->toBe('delta')
        ->and($coderRun->submitted_input)->toContain('QA APPROVED COMMIT FINALIZATION')
        ->and($harness->modes)->toBe(['resume']);

    $this->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('workRequests.0.tasks.0.status', 'done')
            ->where('workRequests.0.tasks.0.commit_sha', $projectHead)
            ->where('workRequests.0.tasks.0.integrated_sha', $projectHead)
            ->where('workRequests.0.tasks.0.worktree_cleaned_at', fn (?string $value) => $value !== null));
});

test('an unapproved Task cannot enter the commit path', function () {
    [$project, $task] = feature07ApprovedTask();
    $task->update(['status' => 'ready_for_qa', 'approved_at' => null]);
    Queue::fake([ProcessTaskCommit::class]);

    $this->post(route('projects.tasks.commit', [$project, $task]))->assertRedirect(route('projects.show', $project));

    Queue::assertNothingPushed();
    app()->call([new ProcessTaskCommit($task), 'handle']);

    expect($task->refresh()->status)->toBe('ready_for_qa');
});

test('a non-conventional Coder commit blocks the approved Task and preserves its worktree', function () {
    [, $task] = feature07ApprovedTask();
    feature07FakeHarness('integrate approved work');

    app()->call([new ProcessTaskCommit($task), 'handle']);

    expect($task->refresh()->status)->toBe('blocked')
        ->and($task->blocked_reason)->toContain('conventional commit syntax')
        ->and(is_dir((string) $task->worktree_path))->toBeTrue();
});

test('a moved Project branch blocks integration without deleting the approved Task worktree', function () {
    [$project, $task] = feature07ApprovedTask();
    File::put($project->path.'/PROJECT_CHANGE.md', 'Concurrent Project branch change');
    Process::path($project->path)->run(['git', 'add', 'PROJECT_CHANGE.md'])->throw();
    Process::path($project->path)->run(['git', '-c', 'user.name=AISF Tests', '-c', 'user.email=aisf-tests@example.test', 'commit', '-m', 'chore: move project branch'])->throw();
    feature07FakeHarness('feat(tasks): integrate approved work');

    app()->call([new ProcessTaskCommit($task), 'handle']);

    expect($task->refresh()->status)->toBe('blocked')
        ->and($task->blocked_reason)->toContain('cannot fast-forward')
        ->and($task->commit_sha)->not->toBeNull()
        ->and(is_dir((string) $task->worktree_path))->toBeTrue();
});

/** @return array{0: Project, 1: Task} */
function feature07ApprovedTask(): array
{
    $repositoryPath = sys_get_temp_dir().'/aisf-feature07-'.Str::uuid();
    File::makeDirectory($repositoryPath);
    Process::path($repositoryPath)->run(['git', 'init', '--initial-branch=main'])->throw();
    File::put($repositoryPath.'/README.md', '# Feature 07 test repository');
    Process::path($repositoryPath)->run(['git', 'add', 'README.md'])->throw();
    Process::path($repositoryPath)->run(['git', '-c', 'user.name=AISF Tests', '-c', 'user.email=aisf-tests@example.test', 'commit', '-m', 'chore: initialize fixture'])->throw();

    $project = Project::factory()->create(['path' => $repositoryPath]);
    app(ProjectAgentProvisioner::class)->ensureFor($project);
    $coder = $project->agents()->where('role', 'coder')->sole();
    $workRequest = $project->workRequests()->create(['prompt' => 'Implement approved work.', 'status' => 'planned', 'summary' => 'One approved Task.']);
    $task = $workRequest->tasks()->create([
        'position' => 1,
        'title' => 'Integrate approved work',
        'objective' => 'Commit and integrate the QA-approved work.',
        'implementation_spec' => 'Implement the approved work.',
        'acceptance_criteria' => ['The approved work is integrated.'],
        'verification_commands' => ['php artisan test --filter=Example'],
        'browser_steps' => ['Open the Task and confirm integration evidence.'],
    ]);

    app(TaskWorktreeManager::class)->ensureWorktree($task);
    $task->refresh();
    File::put((string) $task->worktree_path.'/APPROVED_CHANGE.md', 'QA approved work');
    $session = $task->agentSessions()->create(['project_agent_id' => $coder->id, 'provider_session_id' => 'feature-07-existing-coder-session']);
    $session->runs()->create([
        'purpose' => 'coder_implementation',
        'status' => 'succeeded',
        'attempt' => 1,
        'context_mode' => 'initial',
        'submitted_input' => 'Initial Coder implementation context.',
        'context_sources' => [],
        'output_summary' => 'Implemented approved Task work.',
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $task->update(['status' => 'approved', 'approved_at' => now()]);

    return [$project, $task->refresh()];
}

function feature07FakeHarness(string $message): Feature07FakeAgentHarness
{
    $harness = new Feature07FakeAgentHarness(function (string $worktreePath) use ($message): void {
        Process::path($worktreePath)->run(['git', 'add', '-A'])->throw();
        Process::path($worktreePath)->run(['git', '-c', 'user.name=AISF Tests', '-c', 'user.email=aisf-tests@example.test', 'commit', '-m', $message])->throw();
    });
    app()->instance(AgentHarness::class, $harness);

    return $harness;
}
