<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAgentExecution;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('workflow:dispatch')]
#[Description('Claim the next eligible WorkRequest or Task per Project and dispatch its Agent execution.')]
class DispatchWorkflow extends Command
{
    /**
     * Perform only short database eligibility checks and transactional claims, then dispatch queued Jobs.
     * Never invoke an Agent harness directly from this process — that is ProcessAgentExecution's job.
     */
    public function handle(): int
    {
        Project::query()
            ->where('enabled', true)
            ->each(function (Project $project): void {
                $this->dispatchForProject($project);
            });

        return self::SUCCESS;
    }

    /**
     * One active Agent execution per Project. Prefer resuming the WorkRequest's own planning turn,
     * then the lowest-position eligible Task whose dependency is already complete.
     */
    private function dispatchForProject(Project $project): void
    {
        if ($this->hasActiveExecution($project)) {
            return;
        }

        $workRequest = WorkRequest::query()
            ->where('project_id', $project->id)
            ->whereIn('status', ['pending', 'waiting'])
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
        }
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
