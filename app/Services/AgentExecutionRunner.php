<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\Task;
use App\Models\WorkRequest;
use Illuminate\Support\Str;
use JsonException;
use Throwable;
use UnexpectedValueException;

/** Run one provider turn while leaving every workflow decision to durable reconciliation. */
class AgentExecutionRunner
{
    public function __construct(
        private readonly AgentHarness $harness,
        private readonly AgentSessionManager $sessionManager,
        private readonly AgentPromptComposer $promptComposer,
        private readonly TaskContextBuilder $taskContextBuilder,
    ) {}

    public function run(Task|WorkRequest $subject, ?string $operatorInstruction = null): AgentTurnExecution
    {
        $role = $this->roleFor($subject);
        $mode = $this->modeFor($subject);
        $project = $this->projectFor($subject);
        $agent = $project->agents()->where('role', $role)->where('enabled', true)->first();

        if (! $agent instanceof ProjectAgent) {
            throw new UnexpectedValueException("No enabled {$role} Agent is configured for this Project.");
        }

        $session = $this->sessionManager->forSubject($agent, $subject);
        [$repositoryPath, $writable] = $this->executionTarget($subject);
        $promptContext = $this->promptComposer->compose($agent, $subject, $repositoryPath, $operatorInstruction);
        $executionToken = Str::random(64);
        $prompt = $promptContext['prompt']."\n\n".$this->contractSection($subject, $role, $mode)
            ."\n\nACTIVE RUN AUTHORIZATION\nAgent run token: {$executionToken}";

        if ($subject instanceof Task) {
            $prompt .= "\n\nDURABLE TASK CONTEXT\n".json_encode($this->taskContextBuilder->forTask($subject), JSON_THROW_ON_ERROR);
        }

        $run = $this->sessionManager->startRun($session, $role, [
            'mode' => 'initial',
            'input' => $prompt,
            'sources' => $promptContext['sources'],
            'agent_snapshot' => $promptContext['snapshot']['agent'],
            'prompt_snapshot' => [...$promptContext['snapshot'], 'execution_mode' => $mode],
            'role' => $role,
            'execution_token' => $executionToken,
        ]);
        $prompt .= "\nAgent run ID: {$run->id}";
        $run->update([
            'submitted_input' => $prompt,
            'execution_metadata' => [
                'accepted_handoff_id' => $subject instanceof Task ? ($subject->last_handoff['id'] ?? null) : null,
                'execution_mode' => $mode,
                'harness' => $agent->harness,
                'model' => $agent->model,
            ],
        ]);

        try {
            $result = $this->harness->start($agent, $repositoryPath, $prompt, writable: $writable);
        } catch (Throwable $exception) {
            $result = new AgentHarnessResult(false, null, null, null, $exception->getMessage());
        }

        return new AgentTurnExecution($run, $result, $this->informationalSummary($result));
    }

    private function informationalSummary(AgentHarnessResult $result): string
    {
        $output = trim((string) $result->output);

        if ($output !== '') {
            try {
                $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

                if (is_array($decoded) && filled($decoded['summary'] ?? null)) {
                    return Str::limit(trim((string) $decoded['summary']), 2000, '');
                }
            } catch (JsonException) {
                // Ordinary text is a valid informational final response.
            }

            return Str::limit($output, 2000, '');
        }

        return Str::limit(trim((string) $result->failureMessage) ?: 'Agent execution returned no terminal summary.', 2000, '');
    }

    private function roleFor(Task|WorkRequest $subject): string
    {
        if ($subject instanceof WorkRequest) {
            return 'project_manager';
        }

        $toRole = $subject->last_handoff['to_role'] ?? null;

        if (! filled($toRole)) {
            throw new UnexpectedValueException('A Task execution requires an accepted durable handoff.');
        }

        return (string) $toRole;
    }

    private function modeFor(Task|WorkRequest $subject): string
    {
        if ($subject instanceof WorkRequest) {
            return $subject->tasks()->exists() ? 'dependency_handoff' : 'initial_planning';
        }

        return (string) ($subject->last_handoff['reason'] ?? 'implementation_ready');
    }

    private function projectFor(Task|WorkRequest $subject): Project
    {
        if ($subject instanceof WorkRequest) {
            $subject->loadMissing('project');

            return $subject->project;
        }

        $subject->loadMissing('workRequest.project');

        return $subject->workRequest->project;
    }

    /** @return array{0: string, 1: bool} */
    private function executionTarget(Task|WorkRequest $subject): array
    {
        $writable = $subject instanceof Task
            && ($subject->last_handoff['to_role'] ?? null) === 'coder';

        return [(string) $this->projectFor($subject)->path, $writable];
    }

    private function contractSection(Task|WorkRequest $subject, string $role, string $mode): string
    {
        if ($subject instanceof WorkRequest) {
            return <<<'PROMPT'
DURABLE WORKFLOW CONTRACT
Inspect the repository read-only. Your final message is informational and never controls workflow state.
For a new request, call save_task_plan, then call handoff_task for every dependency-ready Task using reason "implementation_ready".
For an existing plan, hand off each newly dependency-ready Task that has not already been handed off.
If the entire request already exists or is deterministically blocked, call record_workflow_outcome instead of inventing Tasks.
PROMPT;
        }

        if ($role === 'qa') {
            return <<<'PROMPT'
DURABLE WORKFLOW CONTRACT
You have read-only access. Use get_task_context, review the exact candidate_tree_sha, call save_qa_review, then call handoff_task to Coder with reason exactly "approved" or "changes_requested". Your final message is informational only. Do not edit or commit.
PROMPT;
        }

        if ($mode === 'approved') {
            return <<<'PROMPT'
DURABLE WORKFLOW CONTRACT
This is approved finalization mode. Do not modify the implementation. Commit the approved candidate when candidate_kind is "changes", then call finalize_task. For "no_change", call finalize_task without a commit. Your final message is informational only.
PROMPT;
        }

        return <<<PROMPT
DURABLE WORKFLOW CONTRACT
Coder mode: {$mode}. Use get_task_context, perform the required implementation or repair, test it, call save_task_result, then hand off the new candidate to QA. Do not commit before QA approval. Your final message is informational only. Use record_workflow_outcome only for a deterministic blocker.
PROMPT;
    }
}
