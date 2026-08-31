<?php

namespace App\Models;

use Database\Factories\AgentSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_agent_id', 'task_id', 'work_request_id', 'provider_session_id'])]
class AgentSession extends Model
{
    /** @use HasFactory<AgentSessionFactory> */
    use HasFactory;

    /**
     * Prevent provider continuity identifiers from being serialized accidentally.
     *
     * @var list<string>
     */
    protected $hidden = ['provider_session_id'];

    /**
     * Return the configured Project Agent that owns this logical session.
     *
     * @return BelongsTo<ProjectAgent, $this>
     */
    public function projectAgent(): BelongsTo
    {
        return $this->belongsTo(ProjectAgent::class);
    }

    /**
     * Return the Task subject when this Agent session works on a Task.
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Return the WorkRequest subject when this Agent session works on a WorkRequest.
     *
     * @return BelongsTo<WorkRequest, $this>
     */
    public function workRequest(): BelongsTo
    {
        return $this->belongsTo(WorkRequest::class);
    }

    /**
     * Return model invocation attempts in deterministic attempt order.
     *
     * @return HasMany<AgentRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class)->orderBy('attempt');
    }
}
