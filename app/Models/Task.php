<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property list<string> $acceptance_criteria
 * @property list<string> $verification_commands
 * @property list<string> $browser_steps
 */
#[Fillable(['depends_on_task_id', 'position', 'title', 'objective', 'implementation_spec', 'acceptance_criteria', 'verification_commands', 'browser_steps'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * Cast structured planning collections.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acceptance_criteria' => 'array',
            'verification_commands' => 'array',
            'browser_steps' => 'array',
        ];
    }

    /**
     * Return the WorkRequest that owns this planned Task.
     *
     * @return BelongsTo<WorkRequest, $this>
     */
    public function workRequest(): BelongsTo
    {
        return $this->belongsTo(WorkRequest::class);
    }

    /**
     * Return Coder and QA logical sessions associated with this Task.
     *
     * @return HasMany<AgentSession, $this>
     */
    public function agentSessions(): HasMany
    {
        return $this->hasMany(AgentSession::class);
    }
}
