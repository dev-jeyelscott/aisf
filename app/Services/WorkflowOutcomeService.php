<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\AgentRunAction;
use App\Models\Task;
use App\Models\WorkRequest;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class WorkflowOutcomeService
{
    public function __construct(
        private readonly AgentRunActionRecorder $actionRecorder,
    ) {}

    /** @param list<string> $evidence */
    public function record(
        AgentRun $run,
        Task|WorkRequest $subject,
        string $outcome,
        string $summary,
        array $evidence,
        string $executionToken,
    ): Task|WorkRequest {
        $run->loadMissing('agentSession.projectAgent');
        $summary = trim($summary);

        if (
            $run->status !== 'running'
            || ! hash_equals((string) $run->execution_token, $executionToken)
            || $summary === ''
        ) {
            throw new UnexpectedValueException('This Agent execution cannot record a workflow outcome.');
        }

        if ($subject instanceof WorkRequest) {
            if (
                $run->agentSession->work_request_id !== $subject->id
                || $run->agentSession->projectAgent->role !== 'project_manager'
                || ! in_array($outcome, ['already_implemented', 'blocked'], true)
                || ($outcome === 'already_implemented' && $subject->tasks()->exists())
            ) {
                throw new UnexpectedValueException('The Project Manager may record only a valid WorkRequest outcome.');
            }
        } elseif (
            $run->agentSession->task_id !== $subject->id
            || $outcome !== 'blocked'
        ) {
            throw new UnexpectedValueException('An active Task Agent may record only a blocked Task outcome.');
        }

        return DB::transaction(function () use ($run, $subject, $outcome, $summary, $evidence): Task|WorkRequest {
            $locked = $subject instanceof WorkRequest
                ? WorkRequest::query()->lockForUpdate()->findOrFail($subject->id)
                : Task::query()->lockForUpdate()->findOrFail($subject->id);

            if ($locked->status !== 'running') {
                throw new UnexpectedValueException('The workflow subject is no longer running.');
            }

            if ($locked instanceof WorkRequest) {
                $locked->update([
                    'status' => $outcome === 'blocked' ? 'failed' : 'completed',
                    'outcome' => $outcome,
                    'summary' => $summary,
                    'evidence' => $evidence,
                    'failure_reason' => $outcome === 'blocked' ? $summary : null,
                    'last_handoff' => null,
                ]);
            } else {
                $locked->update([
                    'status' => 'failed',
                    'outcome' => 'blocked',
                    'blocked_reason' => $summary,
                    'last_handoff' => null,
                ]);
            }

            $this->actionRecorder->record(
                $run,
                AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED,
                $locked,
            );

            return $locked;
        }, attempts: 3);
    }
}
