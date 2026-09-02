<?php

namespace App\Models;

use Database\Factories\AgentRunActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'agent_run_id',
    'action',
    'resource_type',
    'resource_id',
])]
class AgentRunAction extends Model
{
    /** @use HasFactory<AgentRunActionFactory> */
    use HasFactory;

    public const ACTION_PLAN_SAVED = 'plan_saved';

    public const ACTION_TASK_RESULT_SAVED = 'task_result_saved';

    public const ACTION_QA_REVIEW_SAVED = 'qa_review_saved';

    public const ACTION_HANDOFF_CREATED = 'handoff_created';

    public const ACTION_WORKFLOW_OUTCOME_RECORDED = 'workflow_outcome_recorded';

    public const ACTION_CANDIDATE_FINALIZED = 'candidate_finalized';

    public const ACTION_VAULT_NOTE_WRITTEN = 'vault_note_written';

    public const RESOURCE_AGENT_RUN = 'agent_run';

    public const RESOURCE_TASK = 'task';

    public const RESOURCE_WORK_REQUEST = 'work_request';

    public const RESOURCE_CANDIDATE_REVIEW = 'candidate_review';

    public const RESOURCE_TASK_HANDOFF = 'task_handoff';

    /**
     * Cast persisted resource identifiers to their application type.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resource_id' => 'integer',
        ];
    }

    /**
     * Return the exact AgentRun responsible for this durable action.
     *
     * @return BelongsTo<AgentRun, $this>
     */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
