<?php

namespace App\Models;

use Database\Factories\QaReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<array{criterion: string, met: bool, note: string}> $acceptance_criteria_results
 * @property list<array{command: string, passed: bool, notes: string}> $verification_results
 * @property array{mode: string, passed: bool|null, notes: string} $browser_result
 * @property list<string> $findings
 */
#[Fillable(['agent_session_id', 'status', 'summary', 'acceptance_criteria_results', 'verification_results', 'browser_result', 'findings', 'operator_confirmed_at'])]
class QaReview extends Model
{
    /** @use HasFactory<QaReviewFactory> */
    use HasFactory;

    /**
     * Cast structured QA evidence and confirmation timestamp.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acceptance_criteria_results' => 'array',
            'verification_results' => 'array',
            'browser_result' => 'array',
            'findings' => 'array',
            'operator_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Return the Task this QA review evaluated.
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Return the QA Agent session that produced this review.
     *
     * @return BelongsTo<AgentSession, $this>
     */
    public function agentSession(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class);
    }
}
