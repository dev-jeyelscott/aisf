<?php

namespace App\Jobs;

use App\Models\Task;
use App\Models\WorkRequest;
use App\Services\ProjectManagerPlanner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

class ProcessWorkRequest implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Keep worker execution below the repository's default database queue retry_after value.
     */
    public int $timeout = 80;

    /**
     * Allow one retry for transient harness or persistence failures.
     */
    public int $tries = 2;

    /**
     * Delay a transient retry briefly instead of immediately repeating external execution.
     */
    public int $backoff = 5;

    /**
     * Expire a stale uniqueness lock after the full retry window instead of leaving a crashed job locked indefinitely.
     */
    public int $uniqueFor = 300;

    /**
     * Create a new queued Project Manager planning job.
     */
    public function __construct(public WorkRequest $workRequest) {}

    /**
     * Deduplicate queued planning for the same durable WorkRequest.
     */
    public function uniqueId(): string
    {
        return (string) $this->workRequest->getKey();
    }

    /**
     * Execute PM planning, persist only validated results, and preserve retry-safe state transitions.
     */
    public function handle(ProjectManagerPlanner $planner): void
    {
        $workRequestId = (int) $this->workRequest->getKey();
        $workRequest = WorkRequest::query()->findOrFail($workRequestId);

        if (in_array($workRequest->status, ['planned', 'completed'], true)) {
            return;
        }

        $workRequest->update([
            'status' => 'processing',
            'failure_reason' => null,
        ]);

        try {
            $plan = $planner->plan($workRequest);
        } catch (UnexpectedValueException $exception) {
            $this->markFailed($workRequestId, $exception);

            return;
        }

        $this->persistPlan($workRequestId, $plan);
    }

    /**
     * Mark a terminal queue failure without overwriting a plan that another retry already completed.
     */
    public function failed(?Throwable $exception): void
    {
        $this->markFailed(
            (int) $this->workRequest->getKey(),
            $exception ?? new UnexpectedValueException('Project Manager planning failed.'),
        );
    }

    /**
     * Atomically replace any stale Task rows with exactly one validated ordered plan.
     *
     * @param  array{
     *     summary: string,
     *     already_implemented: bool,
     *     already_implemented_reason: string|null,
     *     tasks: list<array{
     *         title: string,
     *         objective: string,
     *         implementation_spec: string,
     *         acceptance_criteria: list<string>,
     *         verification_commands: list<string>,
     *         browser_test_steps: list<string>,
     *         depends_on_position: int|null
     *     }>
     * }  $plan
     */
    private function persistPlan(int $workRequestId, array $plan): void
    {
        DB::transaction(function () use ($workRequestId, $plan): void {
            $workRequest = WorkRequest::query()->lockForUpdate()->findOrFail($workRequestId);

            if (in_array($workRequest->status, ['planned', 'completed'], true)) {
                return;
            }

            $workRequest->tasks()->delete();

            if ($plan['already_implemented']) {
                $evidence = $plan['already_implemented_reason'];

                if ($evidence === null) {
                    throw new UnexpectedValueException('Validated already-implemented planning evidence is missing.');
                }

                $workRequest->update([
                    'status' => 'completed',
                    'summary' => $plan['summary'],
                    'evidence' => [$evidence],
                    'failure_reason' => null,
                ]);

                return;
            }

            /** @var array<int, Task> $createdTasks */
            $createdTasks = [];

            foreach ($plan['tasks'] as $index => $taskPlan) {
                $position = $index + 1;
                $dependsOnPosition = $taskPlan['depends_on_position'];
                $dependsOnTaskId = null;

                if ($dependsOnPosition !== null) {
                    $dependency = $createdTasks[$dependsOnPosition] ?? null;

                    if ($dependency === null) {
                        throw new UnexpectedValueException(sprintf('Task %d references an unavailable dependency position.', $position));
                    }

                    $dependsOnTaskId = (int) $dependency->getKey();
                }

                $createdTasks[$position] = $workRequest->tasks()->create([
                    'depends_on_task_id' => $dependsOnTaskId,
                    'position' => $position,
                    'title' => $taskPlan['title'],
                    'objective' => $taskPlan['objective'],
                    'implementation_spec' => $taskPlan['implementation_spec'],
                    'acceptance_criteria' => $taskPlan['acceptance_criteria'],
                    'verification_commands' => $taskPlan['verification_commands'],
                    'browser_steps' => $taskPlan['browser_test_steps'],
                ]);
            }

            $workRequest->update([
                'status' => 'planned',
                'summary' => $plan['summary'],
                'evidence' => null,
                'failure_reason' => null,
            ]);
        }, attempts: 3);
    }

    /**
     * Persist a concise terminal failure only while no successful planning result exists.
     */
    private function markFailed(int $workRequestId, Throwable $exception): void
    {
        WorkRequest::query()
            ->whereKey($workRequestId)
            ->whereNotIn('status', ['planned', 'completed'])
            ->update([
                'status' => 'failed',
                'failure_reason' => Str::limit($exception->getMessage(), 10000, ''),
            ]);
    }
}
