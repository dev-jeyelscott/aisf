<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\WorkflowDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workflow:dispatch')]
#[Description('Claim the next eligible WorkRequest or Task per Project and dispatch its Agent execution.')]
class DispatchWorkflow extends Command
{
    public function __construct(private readonly WorkflowDispatcher $dispatcher)
    {
        parent::__construct();
    }

    /**
     * Reconciliation/recovery sweep: the happy path is dispatched immediately by
     * DispatchWorkflowForProject right after a handoff is accepted, but a lost dispatch, a worker
     * crash, or an application restart is recovered here on the next scheduled tick. Perform only
     * short database eligibility checks and transactional claims, then dispatch queued Jobs. Never
     * invoke an Agent harness directly from this process — that is ProcessAgentExecution's job.
     */
    public function handle(): int
    {
        Project::query()
            ->where('enabled', true)
            ->each(function (Project $project): void {
                $this->dispatcher->dispatchForProject($project);
            });

        return self::SUCCESS;
    }
}
