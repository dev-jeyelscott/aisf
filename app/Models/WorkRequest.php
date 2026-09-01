<?php

namespace App\Models;

use Database\Factories\WorkRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property array<string, mixed>|null $last_handoff */
#[Fillable(['prompt', 'status', 'outcome', 'protocol_recovery_count', 'summary', 'evidence', 'failure_reason', 'last_handoff', 'source_type', 'source_external_id', 'source_url', 'source_metadata'])]
class WorkRequest extends Model
{
    /** @use HasFactory<WorkRequestFactory> */
    use HasFactory;

    /**
     * Default to pending (eligible for the dispatcher, no execution yet) and manually submitted.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'source_type' => 'manual',
    ];

    /**
     * Cast persisted planning evidence and the most recent Agent handoff.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'last_handoff' => 'array',
            'source_metadata' => 'array',
            'protocol_recovery_count' => 'integer',
        ];
    }

    /**
     * Return the Project that owns this WorkRequest.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return Tasks in authoritative planning order.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position');
    }

    /**
     * Return logical Agent sessions associated with this WorkRequest.
     *
     * @return HasMany<AgentSession, $this>
     */
    public function agentSessions(): HasMany
    {
        return $this->hasMany(AgentSession::class);
    }
}
