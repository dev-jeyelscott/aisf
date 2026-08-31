<?php

namespace App\Models;

use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable([
    'purpose',
    'status',
    'attempt',
    'context_mode',
    'submitted_input',
    'context_sources',
    'output_summary',
    'raw_output_reference',
    'exit_code',
    'started_at',
    'finished_at',
])]
class AgentRun extends Model
{
    /** @use HasFactory<AgentRunFactory> */
    use HasFactory;

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
}
