<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'agent_run_id',
    'project_id',
    'task_id',
    'idempotency_key',
    'profile',
    'driver',
    'target_type',
    'command',
    'candidate_tree_sha',
    'status',
    'exit_code',
    'duration_ms',
    'stdout',
    'stderr',
    'diagnostic',
    'started_at',
    'finished_at',
])]
class ProjectVerificationRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ENVIRONMENT_UNAVAILABLE = 'environment_unavailable';

    public const STATUS_TIMED_OUT = 'timed_out';

    public const STATUS_STALE_CANDIDATE = 'stale_candidate';

    /**
     * Default a newly-created attempt to an active verification state.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_RUNNING,
    ];

    /**
     * Cast persisted verification evidence to application values.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'command' => 'array',
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Return the exact AgentRun responsible for requesting this verification.
     *
     * @return BelongsTo<AgentRun, $this>
     */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /**
     * Return the Project whose approved verification profile was used.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the Task candidate verified by this attempt when Task-scoped.
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Return bounded Agent-facing verification evidence without host configuration details.
     *
     * @return array<string, mixed>
     */
    public function toAgentEvidence(): array
    {
        return [
            'verification_run_id' => $this->id,
            'status' => $this->status,
            'profile' => $this->profile,
            'driver' => $this->driver,
            'target_type' => $this->target_type,
            'candidate_tree_sha' => $this->candidate_tree_sha,
            'exit_code' => $this->exit_code,
            'duration_ms' => $this->duration_ms,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'diagnostic' => $this->diagnostic,
        ];
    }
}
