<?php

namespace App\Models;

use Database\Factories\CandidateReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['candidate_agent_run_id', 'reviewer_agent_run_id', 'candidate_sha', 'candidate_tree_sha', 'status', 'summary', 'findings'])]
class CandidateReview extends Model
{
    /** @use HasFactory<CandidateReviewFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'findings' => 'array',
        ];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function candidateAgentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'candidate_agent_run_id');
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function reviewerAgentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'reviewer_agent_run_id');
    }
}
