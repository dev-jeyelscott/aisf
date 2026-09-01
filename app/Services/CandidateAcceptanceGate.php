<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\CandidateReview;
use App\Models\Task;
use UnexpectedValueException;

class CandidateAcceptanceGate
{
    /**
     * Persist a review only when it evaluates the immutable candidate currently recorded for the Task.
     *
     * @param  list<string>  $findings
     */
    public function recordReview(Task $task, AgentRun $candidateRun, AgentRun $reviewerRun, string $candidateSha, string $status, string $summary, array $findings): CandidateReview
    {
        if (! in_array($status, ['approved', 'changes_requested'], true)) {
            throw new UnexpectedValueException('A candidate review must be approved or changes requested.');
        }

        if ($task->candidate_sha !== $candidateSha) {
            throw new UnexpectedValueException('A review may only evaluate the Task’s current immutable candidate SHA.');
        }

        $candidateRun->loadMissing('agentSession');
        $reviewerRun->loadMissing('agentSession');

        if ($candidateRun->agentSession->project_agent_id === $reviewerRun->agentSession->project_agent_id) {
            throw new UnexpectedValueException('A code-producing Agent cannot approve its own candidate.');
        }

        return $task->candidateReviews()->create([
            'candidate_agent_run_id' => $candidateRun->id,
            'reviewer_agent_run_id' => $reviewerRun->id,
            'candidate_sha' => $candidateSha,
            'status' => $status,
            'summary' => $summary,
            'findings' => $findings,
        ]);
    }

    /**
     * The most recent review of the Task's current candidate must be an approval. Using the latest
     * review (rather than "no changes_requested ever recorded") lets a Coder repair fix a prior
     * changes_requested finding and still reach approval for the same candidate SHA.
     */
    public function hasCurrentApproval(Task $task): bool
    {
        if (! filled($task->candidate_sha)) {
            return false;
        }

        $latestReview = $task->candidateReviews()
            ->where('candidate_sha', $task->candidate_sha)
            ->latest('id')
            ->first();

        return $latestReview?->status === 'approved';
    }
}
