<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\Task;
use Illuminate\Support\Arr;
use UnexpectedValueException;

class TaskContextBuilder
{
    /** @return array<string, mixed> */
    public function forTask(Task $task, ?AgentRun $viewer = null, ?string $executionToken = null): array
    {
        $task->loadMissing(['workRequest.project', 'handoffs.fromProjectAgent', 'candidateReviews', 'agentSessions.runs']);

        if ($viewer !== null) {
            $viewer->loadMissing('agentSession.projectAgent');
            if ($viewer->status !== 'running' || ! is_string($executionToken) || ! hash_equals((string) $viewer->execution_token, $executionToken) || (int) $viewer->agentSession->projectAgent->project_id !== (int) $task->workRequest->project_id) {
                throw new UnexpectedValueException('The Agent run cannot access a Task from another Project.');
            }
        }

        $runs = $task->agentSessions->flatMap(fn ($session) => $session->runs)->sortByDesc('id');
        $latestCoderRun = $runs->first(fn (AgentRun $run) => $run->role === 'coder' && $run->status === 'succeeded');
        $latestHandoff = $task->handoffs->sortByDesc('id')->first();
        $latestReview = $task->candidateReviews->sortByDesc('id')->first();

        return [
            'work_request' => ['id' => $task->workRequest->id, 'prompt' => $task->workRequest->prompt],
            'execution_mode' => (string) ($task->last_handoff['reason'] ?? 'implementation_ready'),
            'task' => Arr::only($task->toArray(), ['id', 'title', 'objective', 'implementation_spec', 'acceptance_criteria', 'verification_commands', 'browser_steps', 'status', 'outcome', 'base_branch', 'base_sha', 'branch_name', 'worktree_path', 'candidate_tree_sha', 'candidate_created_by_run_id', 'candidate_kind', 'commit_sha', 'pull_request_url']),
            'latest_coder_result' => $latestCoderRun === null ? null : ['agent_run_id' => $latestCoderRun->id, 'summary' => $latestCoderRun->output_summary, 'execution_metadata' => $latestCoderRun->execution_metadata, 'artifacts' => $latestCoderRun->artifacts],
            'latest_review' => $latestReview === null ? null : Arr::only($latestReview->toArray(), ['id', 'candidate_agent_run_id', 'reviewer_agent_run_id', 'candidate_tree_sha', 'status', 'summary', 'findings']),
            'latest_handoff' => $latestHandoff === null ? null : ['id' => $latestHandoff->id, 'from_role' => $latestHandoff->fromProjectAgent->role, 'to_role' => $latestHandoff->toProjectAgent->role, 'reason' => $latestHandoff->reason, 'payload' => $latestHandoff->payload],
            'agent_runs' => $runs->take(10)->map(fn (AgentRun $run) => ['id' => $run->id, 'role' => $run->role, 'status' => $run->status, 'summary' => $run->output_summary, 'artifacts' => $run->artifacts])->values()->all(),
        ];
    }
}
