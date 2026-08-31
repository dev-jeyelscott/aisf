<?php

namespace App\Models;

use Database\Factories\WorkRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['prompt', 'status', 'summary', 'evidence', 'failure_reason'])]
class WorkRequest extends Model
{
    /** @use HasFactory<WorkRequestFactory> */
    use HasFactory;

    /**
     * Cast persisted planning evidence.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }

    /**
     * Return the Project that owns this WorkRequest.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return Tasks in authoritative planning order.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position');
    }

    /**
     * Return the Project Manager logical session associated with this WorkRequest.
     */
    public function agentSessions(): HasMany
    {
        return $this->hasMany(AgentSession::class);
    }
}
