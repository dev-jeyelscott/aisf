<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\WorkflowDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Attempt to immediately claim and dispatch the next eligible WorkRequest/Task for a Project right
 * after a handoff or WorkRequest plan makes one eligible, instead of waiting for the next
 * `workflow:dispatch` scheduler sweep (up to 60s later). This job does not invoke any Agent
 * harness itself — it only performs the same short, generic claim-and-dispatch check the scheduler
 * performs. A short delay lets the just-finished ProcessAgentExecution's per-project unique queue
 * lock release first; if this attempt finds nothing eligible (already claimed, or the lock has not
 * released yet), the scheduler sweep still reconciles it within a minute.
 */
class DispatchWorkflowForProject implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $projectId) {}

    public function handle(WorkflowDispatcher $dispatcher): void
    {
        $project = Project::query()->find($this->projectId);

        if ($project?->enabled) {
            $dispatcher->dispatchForProject($project);
        }
    }
}
