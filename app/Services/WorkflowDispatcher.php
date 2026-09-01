<?php

namespace App\Services;

use App\Jobs\ProcessAgentExecution;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * Claim and dispatch the next eligible WorkRequest or Task for a Project, preserving one active
 * Agent execution per Project. Shared by the scheduled `workflow:dispatch` reconciliation sweep and
 * by the immediate happy-path trigger fired right after a handoff or plan makes something eligible.
 */
class WorkflowDispatcher
{
    /**
     * Prefer resuming the WorkRequest's own planning turn, then the lowest-position eligible Task
     * whose dependency is already complete.
     */
    public function dispatchForProject(Project $project): void
    {
        if ($this->hasActiveExecution($project)) {
            return;
        }

        $this->completeFinishedWorkRequests($project);

        $workRequest = WorkRequest::query()
            ->where('project_id', $project->id)
            ->where(function ($query): void {
                $query->where('status', 'pending')
                    ->orWhere(function ($query): void {
                        $query->where('status', 'waiting')->whereDoesntHave('tasks');
                    });
            })
            ->oldest('id')
            ->first();

        if ($workRequest !== null) {
            $this->claimAndDispatch($workRequest);

            return;
        }

        $task = $this->tasksForProject($project)
            ->whereIn('status', ['pending', 'waiting'])
            ->whereNotNull('last_handoff')
            ->where(function ($query): void {
                $query->whereNull('depends_on_task_id')
                    ->orWhereHas('dependsOn', fn ($dependency) => $dependency->where('status', 'completed'));
            })
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        if ($task !== null) {
            $this->claimAndDispatch($task);

            return;
        }

        $dependencyHandoff = WorkRequest::query()
            ->where('project_id', $project->id)
            ->where('status', 'waiting')
            ->with(['tasks.dependsOn'])
            ->oldest('id')
            ->get()
            ->first(fn (WorkRequest $request): bool => $request->tasks->contains(
                fn (Task $plannedTask): bool => $plannedTask->status !== 'completed'
                    && $plannedTask->last_handoff === null
                    && ($plannedTask->depends_on_task_id === null || $plannedTask->dependsOn?->status === 'completed'),
            ));

        if ($dependencyHandoff !== null) {
            $this->claimAndDispatch($dependencyHandoff);
        }
    }

    private function completeFinishedWorkRequests(Project $project): void
    {
        WorkRequest::query()
            ->where('project_id', $project->id)
            ->where('status', 'waiting')
            ->whereHas('tasks')
            ->whereDoesntHave('tasks', fn ($query) => $query->where('status', '!=', 'completed'))
            ->update([
                'status' => 'completed',
                'outcome' => 'implemented',
                'failure_reason' => null,
                'last_handoff' => null,
            ]);
    }

    private function hasActiveExecution(Project $project): bool
    {
        $workRequestRunning = WorkRequest::query()
            ->where('project_id', $project->id)
            ->where('status', 'running')
            ->exists();

        if ($workRequestRunning) {
            return true;
        }

        return $this->tasksForProject($project)
            ->where('status', 'running')
            ->exists();
    }

    private function claimAndDispatch(Task|WorkRequest $subject): void
    {
        $subjectClass = $subject::class;

        $claimed = $subjectClass::query()
            ->whereKey($subject->getKey())
            ->whereIn('status', ['pending', 'waiting'])
            ->update(['status' => 'running']);

        if ($claimed) {
            ProcessAgentExecution::dispatch($subject->refresh());
        }
    }

    /**
     * Scope Tasks to those belonging to WorkRequests owned by the given Project.
     *
     * @return Builder<Task>
     */
    private function tasksForProject(Project $project): Builder
    {
        return Task::query()->whereHas(
            'workRequest',
            fn ($query) => $query->where('project_id', $project->id),
        );
    }
}
