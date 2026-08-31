<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\AgentRun;
use App\Models\AgentSession;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkRequest;
use App\Services\ProjectAgentProvisioner;
use App\Services\RepositoryInspector;
use App\Services\TaskWorktreeManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * Display a listing of Projects.
     */
    public function index(): Response
    {
        return Inertia::render('projects/index', [
            'projects' => Project::query()
                ->orderBy('title')
                ->get(['id', 'title', 'description', 'path', 'enabled']),
        ]);
    }

    /**
     * Show the form for creating a Project.
     */
    public function create(): Response
    {
        return Inertia::render('projects/create');
    }

    /**
     * Store a newly created Project and provision its default Agents.
     */
    public function store(
        StoreProjectRequest $request,
        ProjectAgentProvisioner $provisioner,
    ): RedirectResponse {
        $project = Project::query()->create($request->validated());
        $provisioner->ensureFor($project);

        return to_route('projects.show', $project);
    }

    /**
     * Display the Project workspace with WorkRequests, Tasks, and concise Agent activity.
     */
    public function show(
        Project $project,
        RepositoryInspector $repositoryInspector,
        ProjectAgentProvisioner $provisioner,
        TaskWorktreeManager $worktreeManager,
    ): Response {
        $provisioner->ensureFor($project);

        $workRequests = $project->workRequests()
            ->with([
                'agentSessions.projectAgent',
                'agentSessions.runs',
                'tasks.agentSessions.projectAgent',
                'tasks.agentSessions.runs',
            ])
            ->orderBy('id')
            ->get()
            ->map(
                fn (WorkRequest $workRequest): array => $this->workRequestPayload(
                    $workRequest,
                    $worktreeManager,
                ),
            )
            ->values();

        return Inertia::render('projects/show', [
            'project' => $project->only([
                'id',
                'title',
                'description',
                'path',
                'enabled',
            ]),
            'repositoryStatus' => $project->enabled
                ? $repositoryInspector->status($project->path)
                : null,
            'workRequests' => $workRequests,
        ]);
    }

    /**
     * Show the form for editing the specified Project.
     */
    public function edit(Project $project): Response
    {
        return Inertia::render('projects/edit', [
            'project' => $project->only([
                'id',
                'title',
                'description',
                'path',
                'enabled',
            ]),
        ]);
    }

    /**
     * Update the specified Project.
     */
    public function update(
        UpdateProjectRequest $request,
        Project $project,
    ): RedirectResponse {
        $project->update($request->validated());

        return to_route('projects.show', $project);
    }

    /**
     * Serialize one WorkRequest without exposing hidden provider configuration.
     *
     * @return array<string, mixed>
     */
    private function workRequestPayload(
        WorkRequest $workRequest,
        TaskWorktreeManager $worktreeManager,
    ): array {
        return [
            ...$workRequest->only([
                'id',
                'prompt',
                'status',
                'summary',
                'evidence',
                'failure_reason',
            ]),
            'agent_sessions' => $workRequest->agentSessions
                ->sortByDesc('updated_at')
                ->map(
                    fn (AgentSession $session): array => $this->sessionPayload(
                        $session,
                    ),
                )
                ->values()
                ->all(),
            'tasks' => $workRequest->tasks
                ->map(
                    fn (Task $task): array => $this->taskPayload($task, $worktreeManager),
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * Serialize one Task together with worktree lifecycle metadata and recent Agent session activity.
     *
     * @return array<string, mixed>
     */
    private function taskPayload(Task $task, TaskWorktreeManager $worktreeManager): array
    {
        return [
            ...$task->only([
                'id',
                'depends_on_task_id',
                'position',
                'title',
                'objective',
                'implementation_spec',
                'acceptance_criteria',
                'verification_commands',
                'browser_steps',
                'status',
                'base_branch',
                'base_sha',
                'branch_name',
                'worktree_path',
                'blocked_reason',
            ]),
            'changed_files' => filled($task->worktree_path)
                ? $worktreeManager->changedFiles($task)
                : [],
            'agent_sessions' => $task->agentSessions
                ->sortByDesc('updated_at')
                ->map(
                    fn (AgentSession $session): array => $this->sessionPayload(
                        $session,
                    ),
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * Serialize one logical Agent session with at most ten recent invocation records.
     *
     * @return array<string, mixed>
     */
    private function sessionPayload(AgentSession $session): array
    {
        return [
            'id' => $session->id,
            'has_provider_continuity' => filled(
                $session->provider_session_id,
            ),
            'agent' => [
                'id' => $session->projectAgent->id,
                'name' => $session->projectAgent->name,
                'role' => $session->projectAgent->role,
            ],
            'runs' => $session->runs
                ->sortByDesc('attempt')
                ->take(10)
                ->map(
                    fn (AgentRun $run): array => $this->runPayload($run),
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * Serialize safe durable Agent invocation metadata and exact submitted context only.
     *
     * @return array<string, mixed>
     */
    private function runPayload(AgentRun $run): array
    {
        return [
            'id' => $run->id,
            'attempt' => $run->attempt,
            'purpose' => $run->purpose,
            'status' => $run->status,
            'context_mode' => $run->context_mode,
            'submitted_input' => $run->submitted_input,
            'context_sources' => $run->context_sources ?? [],
            'output_summary' => $run->output_summary,
            'exit_code' => $run->exit_code,
            'started_at' => $run->started_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
