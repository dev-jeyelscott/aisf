<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAgentExecution;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskPayloadBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    /**
     * Display the dedicated Task inspection workspace and its durable workflow evidence.
     */
    public function show(
        Project $project,
        Task $task,
        TaskPayloadBuilder $taskPayloadBuilder,
    ): Response {
        $this->assertTaskBelongsToProject($project, $task);

        $task->load([
            'dependsOn',
            'agentSessions.projectAgent',
            'agentSessions.runs',
            'candidateReviews',
            'handoffs.fromProjectAgent',
            'handoffs.toProjectAgent',
        ]);

        return Inertia::render('projects/tasks/show', [
            'project' => $project->only([
                'id',
                'title',
            ]),
            'workRequest' => $task->workRequest->only([
                'id',
                'prompt',
                'status',
                'outcome',
                'summary',
                'failure_reason',
                'source_type',
                'source_url',
            ]),
            'dependency' => $task->dependsOn?->only([
                'id',
                'title',
                'status',
            ]),
            'task' => $taskPayloadBuilder->task($task, null),
        ]);
    }

    /**
     * Queue the next Agent execution for a pending or waiting Task, optionally carrying an operator instruction.
     */
    public function run(
        Request $request,
        Project $project,
        Task $task,
    ): RedirectResponse {
        $this->assertTaskBelongsToProject($project, $task);

        $validated = $request->validate([
            'operator_instruction' => ['nullable', 'string', 'max:5000'],
        ]);

        if (
            in_array($task->status, ['pending', 'waiting'], true)
            && isset($task->last_handoff['id'])
        ) {
            ProcessAgentExecution::dispatch(
                $task,
                $validated['operator_instruction'] ?? null,
            );
        }

        return to_route('projects.tasks.show', [$project, $task]);
    }

    /**
     * Re-enter a failed Task as pending so the dispatcher gives it a fresh Coder turn.
     */
    public function retry(
        Project $project,
        Task $task,
    ): RedirectResponse {
        $this->assertTaskBelongsToProject($project, $task);

        if ($task->status === 'failed') {
            $lastHandoff = $task->last_handoff;

            if (! isset($lastHandoff['id'])) {
                $handoff = $task->handoffs()->latest('id')->first();

                if ($handoff !== null) {
                    $lastHandoff = [
                        'id' => $handoff->id,
                        'to_role' => $handoff->toProjectAgent->role,
                        'reason' => $handoff->reason,
                        'payload' => $handoff->payload,
                    ];
                }
            }

            $task->update([
                'status' => 'pending',
                'outcome' => null,
                'protocol_recovery_count' => 0,
                'blocked_reason' => null,
                'last_handoff' => $lastHandoff,
            ]);

            if (isset($lastHandoff['id'])) {
                ProcessAgentExecution::dispatch($task->fresh());
            }
        }

        return to_route('projects.tasks.show', [$project, $task]);
    }

    /**
     * Reject a Task route when its parent WorkRequest belongs to another Project.
     */
    private function assertTaskBelongsToProject(
        Project $project,
        Task $task,
    ): void {
        $task->loadMissing('workRequest');

        abort_unless(
            (int) $task->workRequest->project_id === (int) $project->id,
            404,
        );
    }
}
