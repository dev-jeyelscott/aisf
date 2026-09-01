<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\AgentSession;
use App\Models\Project;
use App\Models\WorkRequest;
use App\Services\ProjectAgentProvisioner;
use App\Services\RepositoryInspector;
use App\Services\TaskPayloadBuilder;
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
        TaskPayloadBuilder $taskPayloadBuilder,
    ): Response {
        $provisioner->ensureFor($project);

        $workRequests = $project->workRequests()
            ->with([
                'agentSessions.projectAgent',
                'agentSessions.runs',
                'tasks.agentSessions.projectAgent',
                'tasks.agentSessions.runs',
                'tasks.candidateReviews',
                'tasks.handoffs.fromProjectAgent',
                'tasks.handoffs.toProjectAgent',
            ])
            ->orderBy('id')
            ->get()
            ->map(
                fn (WorkRequest $workRequest): array => $this->workRequestPayload(
                    $workRequest,
                    $taskPayloadBuilder,
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
                'merge_policy',
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
                'merge_policy',
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
        TaskPayloadBuilder $taskPayloadBuilder,
    ): array {
        return [
            ...$workRequest->only([
                'id',
                'prompt',
                'status',
                'outcome',
                'protocol_recovery_count',
                'summary',
                'evidence',
                'failure_reason',
                'last_handoff',
                'source_type',
                'source_url',
            ]),
            'agent_sessions' => $workRequest->agentSessions
                ->sortByDesc('updated_at')
                ->map(
                    fn (AgentSession $session): array => $taskPayloadBuilder->session(
                        $session,
                    ),
                )
                ->values()
                ->all(),
            'tasks' => $workRequest->tasks
                ->map(
                    fn ($task): array => $taskPayloadBuilder->task($task),
                )
                ->values()
                ->all(),
        ];
    }
}
