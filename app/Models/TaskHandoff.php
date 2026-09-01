<?php

namespace App\Models;

use Database\Factories\TaskHandoffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon|null $dispatched_at */
#[Fillable(['from_project_agent_id', 'to_project_agent_id', 'from_agent_run_id', 'reason', 'payload', 'idempotency_key', 'dispatched_at'])]
class TaskHandoff extends Model
{
    /** @use HasFactory<TaskHandoffFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['payload' => 'array', 'dispatched_at' => 'datetime'];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<ProjectAgent, $this> */
    public function fromProjectAgent(): BelongsTo
    {
        return $this->belongsTo(ProjectAgent::class, 'from_project_agent_id');
    }

    /** @return BelongsTo<ProjectAgent, $this> */
    public function toProjectAgent(): BelongsTo
    {
        return $this->belongsTo(ProjectAgent::class, 'to_project_agent_id');
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function fromAgentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'from_agent_run_id');
    }
}
