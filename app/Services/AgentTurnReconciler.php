<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\AgentRunAction;
use App\Models\Task;
use App\Models\WorkRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AgentTurnReconciler
{
    /**
     * Initialize durable turn reconciliation with workflow and documentation collaborators.
     */
    public function __construct(
        private readonly AgentSessionManager $sessionManager,
        private readonly WorkflowOutcomeService $workflowOutcomeService,
        private readonly AgentRunActionRecorder $actionRecorder,
    ) {}

    /**
     * Reconcile one provider turn from authoritative durable workflow state.
     */
    public function reconcile(Task|WorkRequest $subject, AgentTurnExecution $execution): AgentTurnReconciliation
    {
        $subject->refresh();
        $run = $execution->run->fresh();
        $hasDocumentation = $this->actionRecorder->hasVaultNoteWritten($run);

        if ($this->hasTerminalOutcome($subject, $run)) {
            if (! $hasDocumentation) {
                return $this->recoverProtocol($subject, $run, $execution);
            }

            $this->completeRun($run, $execution, 'terminal', 'terminal_blocked');

            return new AgentTurnReconciliation('terminal', 'terminal_blocked');
        }

        if ($this->postconditionsSatisfied($subject, $run)) {
            if (! $hasDocumentation) {
                return $this->recoverProtocol($subject, $run, $execution);
            }

            $this->applySatisfiedSubjectState($subject);
            $this->completeRun($run, $execution, 'satisfied');

            return new AgentTurnReconciliation('satisfied');
        }

        if (! $execution->harnessResult->successful) {
            $exception = new RuntimeException(
                $execution->harnessResult->failureMessage ?? 'Agent infrastructure execution failed.',
            );
            $this->sessionManager->failRun($run, $exception, $execution->harnessResult->exitCode);
            $run->update([
                'reconciliation_status' => 'recoverable',
                'failure_class' => 'infrastructure_recoverable',
            ]);

            return new AgentTurnReconciliation('recoverable', 'infrastructure_recoverable', true);
        }

        return $this->recoverProtocol($subject, $run, $execution);
    }

    /**
     * Determine whether the run persisted every non-documentation postcondition for its role and mode.
     */
    private function postconditionsSatisfied(Task|WorkRequest $subject, AgentRun $run): bool
    {
        if ($subject instanceof WorkRequest) {
            return $this->projectManagerPostconditions($subject, $run);
        }

        $mode = (string) ($run->execution_metadata['execution_mode'] ?? '');

        if ($run->role === 'qa') {
            return $run->actions()->where('action', AgentRunAction::ACTION_QA_REVIEW_SAVED)->exists()
                && $run->actions()->where('action', AgentRunAction::ACTION_HANDOFF_CREATED)->exists();
        }

        if ($mode === 'approved') {
            return $run->actions()->where('action', AgentRunAction::ACTION_CANDIDATE_FINALIZED)->exists()
                || $run->handoffs()->where('reason', 'ci_failed')->exists();
        }

        return $run->actions()->where('action', AgentRunAction::ACTION_TASK_RESULT_SAVED)->exists()
            && $run->actions()->where('action', AgentRunAction::ACTION_HANDOFF_CREATED)->exists()
            && (int) $subject->candidate_created_by_run_id === (int) $run->id;
    }

    /**
     * Determine whether a Project Manager run persisted its terminal outcome or every required ready-task handoff.
     */
    private function projectManagerPostconditions(WorkRequest $workRequest, AgentRun $run): bool
    {
        if ($run->actions()->where('action', AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED)->exists()) {
            return true;
        }

        $tasks = $workRequest->tasks()->with('dependsOn')->get();

        if ($tasks->isEmpty()) {
            return false;
        }

        if (
            ($run->execution_metadata['execution_mode'] ?? null) === 'initial_planning'
            && ! $run->actions()->where('action', AgentRunAction::ACTION_PLAN_SAVED)->exists()
        ) {
            return false;
        }

        return $tasks
            ->filter(fn (Task $task): bool => $task->status !== 'completed'
                && ($task->depends_on_task_id === null || $task->dependsOn?->status === 'completed'))
            ->every(fn (Task $task): bool => ($task->last_handoff['to_role'] ?? null) === 'coder'
                && ($task->last_handoff['reason'] ?? null) === 'implementation_ready');
    }

    /**
     * Determine whether the subject and current run already own a durable blocked workflow outcome.
     */
    private function hasTerminalOutcome(Task|WorkRequest $subject, AgentRun $run): bool
    {
        return $subject->outcome === 'blocked'
            && $run->actions()->where('action', AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED)->exists();
    }

    /**
     * Apply the existing WorkRequest satisfied-state projection after durable postconditions are proven.
     */
    private function applySatisfiedSubjectState(Task|WorkRequest $subject): void
    {
        if (! $subject instanceof WorkRequest || $subject->status !== 'running') {
            return;
        }

        $tasks = $subject->tasks()->get();

        if ($tasks->isNotEmpty() && $tasks->every(fn (Task $task): bool => $task->status === 'completed')) {
            $subject->update([
                'status' => 'completed',
                'outcome' => 'implemented',
                'failure_reason' => null,
            ]);

            return;
        }

        $subject->update(['status' => 'waiting', 'failure_reason' => null]);
    }

    /**
     * Consume one bounded protocol recovery and require documentation before any recovery-limit terminal outcome.
     */
    private function recoverProtocol(
        Task|WorkRequest $subject,
        AgentRun $run,
        AgentTurnExecution $execution,
    ): AgentTurnReconciliation {
        $limit = (int) config('aisf.max_protocol_recoveries');

        $recoveryCount = DB::transaction(function () use ($subject): int {
            $locked = $subject instanceof WorkRequest
                ? WorkRequest::query()->lockForUpdate()->findOrFail($subject->id)
                : Task::query()->lockForUpdate()->findOrFail($subject->id);
            $count = (int) $locked->protocol_recovery_count + 1;
            $locked->update(['protocol_recovery_count' => $count]);

            return $count;
        }, attempts: 3);

        if ($recoveryCount > $limit) {
            if ($this->actionRecorder->hasVaultNoteWritten($run)) {
                $message = "The workflow exceeded its protocol recovery limit ({$limit}) and requires operator review.";
                $this->workflowOutcomeService->record(
                    $run,
                    $subject->fresh(),
                    'blocked',
                    $message,
                    [$message],
                    (string) $run->execution_token,
                );
                $this->completeRun($run, $execution, 'terminal', 'terminal_blocked');

                return new AgentTurnReconciliation('terminal', 'terminal_blocked');
            }

            return $this->failProtocolRecovery(
                $subject,
                $run,
                $execution,
                "The workflow exceeded its protocol recovery limit ({$limit}) before the AgentRun wrote its required vault work note. Operator retry is required.",
                true,
            );
        }

        return $this->failProtocolRecovery(
            $subject,
            $run,
            $execution,
            'The Agent turn did not persist all required durable workflow actions.',
            false,
        );
    }

    /**
     * Persist one recoverable protocol failure, optionally parking the subject for explicit operator retry.
     */
    private function failProtocolRecovery(
        Task|WorkRequest $subject,
        AgentRun $run,
        AgentTurnExecution $execution,
        string $message,
        bool $operatorRetryRequired,
    ): AgentTurnReconciliation {
        $freshSubject = $subject->fresh();

        if ($operatorRetryRequired) {
            $failureReasonColumn = $freshSubject instanceof WorkRequest
                ? 'failure_reason'
                : 'blocked_reason';

            $freshSubject->update([
                'status' => 'failed',
                $failureReasonColumn => $message,
            ]);
        } else {
            $freshSubject->update(['status' => 'waiting']);
        }

        $this->sessionManager->failRun(
            $run,
            new RuntimeException($message),
            $execution->harnessResult->exitCode,
        );
        $run->update([
            'reconciliation_status' => 'recoverable',
            'failure_class' => 'protocol_recoverable',
        ]);

        return new AgentTurnReconciliation('recoverable', 'protocol_recoverable');
    }

    /**
     * Complete the AgentRun while preserving provider execution diagnostics in metadata.
     */
    private function completeRun(
        AgentRun $run,
        AgentTurnExecution $execution,
        string $classification,
        ?string $failureClass = null,
    ): void {
        $this->sessionManager->completeRun(
            $run,
            $execution->summary,
            $execution->harnessResult->exitCode,
            executionMetadata: [
                'provider_successful' => $execution->harnessResult->successful,
                'provider_failure' => $execution->harnessResult->failureMessage,
            ],
        );
        $run->update([
            'reconciliation_status' => $classification,
            'failure_class' => $failureClass,
        ]);
    }
}
