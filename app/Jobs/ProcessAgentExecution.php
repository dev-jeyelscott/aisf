<?php

namespace App\Jobs;

use App\Models\Task;
use App\Models\WorkRequest;
use App\Services\AgentExecutionRunner;
use App\Services\AgentTurnReconciler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Run one Agent turn and let durable reconciliation determine the workflow result. */
class ProcessAgentExecution implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 0;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(
        public Task|WorkRequest $subject,
        public ?string $operatorInstruction = null,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15];
    }

    public function uniqueId(): string
    {
        return (string) $this->projectId();
    }

    public function handle(AgentExecutionRunner $runner, AgentTurnReconciler $reconciler): void
    {
        $subject = $this->freshSubject();

        if (! in_array($subject->status, ['pending', 'waiting', 'running'], true)) {
            return;
        }

        $subject->update(['status' => 'running']);
        $execution = $runner->run($subject, $this->operatorInstruction);
        $reconciliation = $reconciler->reconcile($subject, $execution);

        if ($reconciliation->retryInfrastructure) {
            throw new RuntimeException(
                $execution->harnessResult->failureMessage ?? 'Agent infrastructure execution failed.',
            );
        }

        DispatchWorkflowForProject::dispatch($this->projectId())->delay(now()->addSeconds(2));
    }

    public function failed(?Throwable $exception): void
    {
        $subjectClass = $this->subject::class;

        $subjectClass::query()
            ->whereKey($this->subject->getKey())
            ->where('status', 'running')
            ->update([
                'status' => 'failed',
                $this->failureReasonColumn($this->subject) => Str::limit(
                    $exception?->getMessage() ?? 'Agent infrastructure execution failed.',
                    10000,
                    '',
                ),
            ]);
    }

    private function freshSubject(): Task|WorkRequest
    {
        return $this->subject instanceof WorkRequest
            ? WorkRequest::query()->whereKey($this->subject->getKey())->sole()
            : Task::query()->whereKey($this->subject->getKey())->sole();
    }

    private function projectId(): int
    {
        if ($this->subject instanceof WorkRequest) {
            return (int) $this->subject->project_id;
        }

        $this->subject->loadMissing('workRequest');

        return (int) $this->subject->workRequest->project_id;
    }

    private function failureReasonColumn(Task|WorkRequest $subject): string
    {
        return $subject instanceof WorkRequest ? 'failure_reason' : 'blocked_reason';
    }
}
