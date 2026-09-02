<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\AgentRunAction;
use App\Models\CandidateReview;
use App\Models\Task;
use App\Models\TaskHandoff;
use App\Models\WorkRequest;
use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

/**
 * Persist exact durable mutation evidence for an already-authorized AgentRun.
 *
 * Transaction ownership stays with the domain service performing the mutation so
 * the authoritative mutation and its audit evidence always commit or roll back together.
 */
class AgentRunActionRecorder
{
    /**
     * Map each supported action to the exact durable resource type it may reference.
     *
     * @var array<string, array{0: class-string<Model>, 1: string}>
     */
    private const ACTION_RESOURCES = [
        AgentRunAction::ACTION_PLAN_SAVED => [
            Task::class,
            AgentRunAction::RESOURCE_TASK,
        ],
        AgentRunAction::ACTION_TASK_RESULT_SAVED => [
            Task::class,
            AgentRunAction::RESOURCE_TASK,
        ],
        AgentRunAction::ACTION_QA_REVIEW_SAVED => [
            CandidateReview::class,
            AgentRunAction::RESOURCE_CANDIDATE_REVIEW,
        ],
        AgentRunAction::ACTION_HANDOFF_CREATED => [
            TaskHandoff::class,
            AgentRunAction::RESOURCE_TASK_HANDOFF,
        ],
        AgentRunAction::ACTION_CANDIDATE_FINALIZED => [
            Task::class,
            AgentRunAction::RESOURCE_TASK,
        ],
        AgentRunAction::ACTION_VAULT_NOTE_WRITTEN => [
            AgentRun::class,
            AgentRunAction::RESOURCE_AGENT_RUN,
        ],
    ];

    /**
     * Determine whether the exact AgentRun owns one valid successful vault-note action.
     */
    public function hasVaultNoteWritten(AgentRun $run): bool
    {
        if (! $run->exists || $run->getKey() === null) {
            return false;
        }

        $actions = $run->actions()
            ->where('action', AgentRunAction::ACTION_VAULT_NOTE_WRITTEN)
            ->get();

        if ($actions->count() !== 1) {
            return false;
        }

        $action = $actions->sole();

        return $action->resource_type === AgentRunAction::RESOURCE_AGENT_RUN
            && $action->resource_id === (int) $run->getKey();
    }

    /**
     * Require exact durable vault-note evidence before a workflow-ending transition.
     */
    public function assertVaultNoteWritten(AgentRun $run): void
    {
        if (! $this->hasVaultNoteWritten($run)) {
            throw new UnexpectedValueException(
                'The AgentRun must write its vault work note before completing this workflow transition.',
            );
        }
    }

    /**
     * Record one successfully persisted durable resource against the exact responsible AgentRun.
     */
    public function record(
        AgentRun $run,
        string $action,
        Model $resource,
    ): AgentRunAction {
        if (! $run->exists || $run->getKey() === null) {
            throw new UnexpectedValueException(
                'AgentRun action evidence requires a persisted AgentRun.',
            );
        }

        if (! $resource->exists || $resource->getKey() === null) {
            throw new UnexpectedValueException(
                'AgentRun action evidence requires a persisted durable resource.',
            );
        }

        if ($action === AgentRunAction::ACTION_WORKFLOW_OUTCOME_RECORDED) {
            if (! $resource instanceof Task && ! $resource instanceof WorkRequest) {
                throw new UnexpectedValueException(
                    'Workflow outcome evidence must reference a Task or WorkRequest.',
                );
            }

            return $run->actions()->create([
                'action' => $action,
                'resource_type' => $resource instanceof Task
                    ? AgentRunAction::RESOURCE_TASK
                    : AgentRunAction::RESOURCE_WORK_REQUEST,
                'resource_id' => (int) $resource->getKey(),
            ]);
        }

        $definition = self::ACTION_RESOURCES[$action] ?? null;

        if ($definition === null) {
            throw new UnexpectedValueException(
                "Unsupported AgentRun action: {$action}.",
            );
        }

        [$expectedResourceClass, $resourceType] = $definition;

        if (! $resource instanceof $expectedResourceClass) {
            throw new UnexpectedValueException(
                sprintf(
                    'AgentRun action %s does not support resource %s.',
                    $action,
                    $resource::class,
                ),
            );
        }

        return $run->actions()->create([
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => (int) $resource->getKey(),
        ]);
    }
}
