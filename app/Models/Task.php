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
 * @property array<string, mixed>|null $last_handoff
 */
#[Fillable([
    'assigned_project_agent_id',
    'depends_on_task_id',
    'position',
    'title',
    'objective',
    'implementation_spec',
    'acceptance_criteria',
    'verification_commands',
    'browser_steps',
    'status',
    'outcome',
    'protocol_recovery_count',
    'repair_cycle_review_boundary_id',
    'repair_cycle_handoff_boundary_id',
    'base_branch',
    'base_sha',
    'branch_name',
    'worktree_path',
    'blocked_reason',
    'last_handoff',
    'commit_sha',
    'candidate_sha',
    'candidate_tree_sha',
    'candidate_created_by_run_id',
    'candidate_kind',
    'pull_request_url',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * Default to pending so the dispatcher can identify Tasks with no active execution.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Cast structured planning, workflow, and repair-cycle state to application values.
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
            'protocol_recovery_count' => 'integer',
            'repair_cycle_review_boundary_id' => 'integer',
            'repair_cycle_handoff_boundary_id' => 'integer',
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
     * Return the persistent specialist selected for this Task.
     *
     * @return BelongsTo<ProjectAgent, $this>
     */
    public function assignedProjectAgent(): BelongsTo
    {
        return $this->belongsTo(ProjectAgent::class, 'assigned_project_agent_id');
    }

    /**
     * Return the AgentRun that produced the current immutable candidate.
     *
     * @return BelongsTo<AgentRun, $this>
     */
    public function candidateCreatedByRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'candidate_created_by_run_id');
    }

    /**
     * Return logical Agent sessions associated with this Task.
     *
     * @return HasMany<AgentSession, $this>
     */
    public function agentSessions(): HasMany
    {
        return $this->hasMany(AgentSession::class);
    }

    /**
     * Return all durable candidate reviews, including previous retry episodes.
     *
     * @return HasMany<CandidateReview, $this>
     */
    public function candidateReviews(): HasMany
    {
        return $this->hasMany(CandidateReview::class);
    }

    /**
     * Return all durable role handoffs, including previous retry episodes.
     *
     * @return HasMany<TaskHandoff, $this>
     */
    public function handoffs(): HasMany
    {
        return $this->hasMany(TaskHandoff::class);
    }
}
