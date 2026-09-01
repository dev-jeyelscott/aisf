<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\AgentRunAction;
use App\Models\CandidateReview;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
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
        return DB::transaction(function () use ($task, $candidateRun, $reviewerRun, $candidateSha, $status, $summary, $findings): CandidateReview {
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

            $review = $task->candidateReviews()->create([
                'candidate_agent_run_id' => $candidateRun->id,
                'reviewer_agent_run_id' => $reviewerRun->id,
                'candidate_sha' => $candidateSha,
                'status' => $status,
                'summary' => $summary,
                'findings' => $findings,
            ]);

            $reviewerRun->actions()->create([
                'action' => AgentRunAction::ACTION_QA_REVIEW_SAVED,
                'resource_type' => AgentRunAction::RESOURCE_CANDIDATE_REVIEW,
                'resource_id' => $review->id,
            ]);

            return $review;
        }, attempts: 3);
    }

    /**
     * Determine whether the latest review of the Task current candidate is an approval.
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
