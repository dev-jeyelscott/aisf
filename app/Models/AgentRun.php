<?php

namespace App\Models;

use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property array<string, mixed>|null $execution_metadata
 * @property array<string, mixed>|null $artifacts
 */
#[Fillable([
    'parent_agent_run_id',
    'purpose',
    'role',
    'execution_token',
    'status',
    'attempt',
    'context_mode',
    'submitted_input',
    'context_sources',
    'agent_snapshot',
    'prompt_snapshot',
    'output_summary',
    'raw_output_reference',
    'execution_metadata',
    'artifacts',
    'exit_code',
    'started_at',
    'finished_at',
])]
class AgentRun extends Model
{
    /** @use HasFactory<AgentRunFactory> */
    use HasFactory;

    protected $hidden = ['execution_token'];

    /**
     * Cast persisted execution metadata to its application types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'context_sources' => 'array',
            'agent_snapshot' => 'array',
            'prompt_snapshot' => 'array',
            'execution_metadata' => 'array',
            'artifacts' => 'array',
            'exit_code' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Return the durable logical Agent session for this invocation.
     *
     * @return BelongsTo<AgentSession, $this>
     */
    public function agentSession(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class);
    }

    /**
     * Return the parent AgentRun when this invocation was delegated.
     *
     * @return BelongsTo<AgentRun, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_agent_run_id');
    }

    /**
     * Return AgentRuns delegated from this invocation.
     *
     * @return HasMany<AgentRun, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_agent_run_id');
    }

    /**
     * Return durable mutations attributed to this exact invocation.
     *
     * @return HasMany<AgentRunAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(AgentRunAction::class);
    }

    /**
     * Return Task handoffs created by this invocation.
     *
     * @return HasMany<TaskHandoff, $this>
     */
    public function handoffs(): HasMany
    {
        return $this->hasMany(TaskHandoff::class, 'from_agent_run_id');
    }
}
