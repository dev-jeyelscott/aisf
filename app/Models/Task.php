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
#[Fillable(['depends_on_task_id', 'position', 'title', 'objective', 'implementation_spec', 'acceptance_criteria', 'verification_commands', 'browser_steps', 'status', 'base_branch', 'base_sha', 'branch_name', 'worktree_path', 'blocked_reason', 'approved_at'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * Cast structured planning collections and the QA approval timestamp.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acceptance_criteria' => 'array',
            'verification_commands' => 'array',
            'browser_steps' => 'array',
            'approved_at' => 'datetime',
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

    /**
     * Return QA review evidence in the order it was produced.
     *
     * @return HasMany<QaReview, $this>
     */
    public function qaReviews(): HasMany
    {
        return $this->hasMany(QaReview::class)->orderBy('id');
    }
}
