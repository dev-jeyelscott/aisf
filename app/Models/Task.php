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
#[Fillable(['depends_on_task_id', 'position', 'title', 'objective', 'implementation_spec', 'acceptance_criteria', 'verification_commands', 'browser_steps', 'status', 'base_branch', 'base_sha', 'branch_name', 'worktree_path', 'blocked_reason', 'last_handoff', 'approved_at', 'commit_sha', 'commit_message', 'integrated_sha', 'integrated_at', 'worktree_cleaned_at', 'branch_deleted_at'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * Default to pending: eligible for the dispatcher, no execution yet.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Cast structured planning collections, the most recent Agent handoff, and execution timestamps.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acceptance_criteria' => 'array',
            'verification_commands' => 'array',
            'browser_steps' => 'array',
            'last_handoff' => 'array',
            'approved_at' => 'datetime',
            'integrated_at' => 'datetime',
            'worktree_cleaned_at' => 'datetime',
            'branch_deleted_at' => 'datetime',
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
     * Return the earlier-position Task this Task depends on, if any.
     *
     * @return BelongsTo<Task, $this>
     */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'depends_on_task_id');
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
