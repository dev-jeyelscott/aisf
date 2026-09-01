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
     * Initialize candidate acceptance with durable AgentRun action recording.
     */
    public function __construct(
        private readonly AgentRunActionRecorder $actionRecorder,
    ) {}

    /**
     * Persist a review only when it evaluates the immutable candidate currently recorded for the Task.
     *
     * @param  list<string>  $findings
     */
    public function recordReview(
        Task $task,
        AgentRun $candidateRun,
        AgentRun $reviewerRun,
        string $candidateSha,
        string $status,
        string $summary,
        array $findings,
    ): CandidateReview {
        return DB::transaction(function () use (
            $task,
            $candidateRun,
            $reviewerRun,
            $candidateSha,
            $status,
            $summary,
            $findings,
        ): CandidateReview {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);

            if (! in_array($status, ['approved', 'changes_requested'], true)) {
                throw new UnexpectedValueException(
                    'A candidate review must be approved or changes requested.',
                );
            }

            $findings = array_values(array_filter(
                array_map(fn (string $finding): string => trim($finding), $findings),
                fn (string $finding): bool => $finding !== '',
            ));

            if ($status === 'changes_requested' && $findings === []) {
                throw new UnexpectedValueException(
                    'A changes-requested review requires at least one finding.',
                );
            }

            if ($task->candidate_tree_sha !== $candidateSha) {
                throw new UnexpectedValueException(
                    'A review may only evaluate the Task’s current immutable candidate tree SHA.',
                );
            }

            if ((int) $task->candidate_created_by_run_id !== (int) $candidateRun->id) {
                throw new UnexpectedValueException(
                    'The candidate-producing run does not match the Task’s current candidate.',
                );
            }

            $candidateRun->loadMissing('agentSession');
            $reviewerRun->loadMissing('agentSession');

            if (
                $candidateRun->agentSession->project_agent_id
                === $reviewerRun->agentSession->project_agent_id
            ) {
                throw new UnexpectedValueException(
                    'A code-producing Agent cannot approve its own candidate.',
                );
            }

            $review = $task->candidateReviews()->create([
                'candidate_agent_run_id' => $candidateRun->id,
                'reviewer_agent_run_id' => $reviewerRun->id,
                'candidate_sha' => $candidateSha,
                'candidate_tree_sha' => $candidateSha,
                'status' => $status,
                'summary' => $summary,
                'findings' => $findings,
            ]);

            $this->actionRecorder->record(
                $reviewerRun,
                AgentRunAction::ACTION_QA_REVIEW_SAVED,
                $review,
            );

            return $review;
        }, attempts: 3);
    }

    /**
     * Determine whether the latest review of the Task current candidate is an approval.
     */
    public function hasCurrentApproval(Task $task): bool
    {
        if (! filled($task->candidate_tree_sha)) {
            return false;
        }

        $latestReview = $task->candidateReviews()
            ->where('candidate_tree_sha', $task->candidate_tree_sha)
            ->latest('id')
            ->first();

        return $latestReview?->status === 'approved';
    }
}
