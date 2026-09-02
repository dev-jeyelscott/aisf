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
    /**
     * Inject provider execution, session, prompt, Task context, and vault documentation collaborators.
     */
    public function __construct(
        private readonly AgentHarness $harness,
        private readonly AgentSessionManager $sessionManager,
        private readonly AgentPromptComposer $promptComposer,
        private readonly TaskContextBuilder $taskContextBuilder,
        private readonly VaultDocumentationService $vaultDocumentationService,
    ) {}

    /**
     * Execute one durable Agent run, resuming the provider conversation when the logical session supports continuity.
     */
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
        $providerSessionId = filled($session->provider_session_id)
            ? (string) $session->provider_session_id
            : null;
        $resumingProviderSession = $providerSessionId !== null
            && $role !== 'qa'
            && $this->harness->canResume($agent);
        [$repositoryPath, $writable] = $this->executionTarget($subject);
        $promptContext = $this->promptComposer->compose($agent, $subject, $repositoryPath, $operatorInstruction);
        $executionToken = Str::random(64);
        $prompt = $promptContext['prompt']
            ."\n\n".$this->contractSection($subject, $role, $mode)
            ."\n\n".$this->projectVerificationContractSection()
            ."\n\n".$this->vaultDocumentationContractSection()
            ."\n\nACTIVE RUN AUTHORIZATION\nAgent run token: {$executionToken}";

        if ($subject instanceof Task) {
            $prompt .= "\n\nDURABLE TASK CONTEXT\n".json_encode($this->taskContextBuilder->forTask($subject), JSON_THROW_ON_ERROR);
        }

        $run = $this->sessionManager->startRun($session, $role, [
            'mode' => $resumingProviderSession ? 'delta' : 'initial',
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
            $this->vaultDocumentationService->preflight(
                $run,
                $executionToken,
            );

            $result = $resumingProviderSession
                ? $this->harness->resume(
                    $agent,
                    $repositoryPath,
                    $providerSessionId,
                    $prompt,
                    writable: $writable,
                )
                : $this->harness->start($agent, $repositoryPath, $prompt, writable: $writable);

            if ($role !== 'qa') {
                $this->sessionManager->captureProviderSessionId($session, $result->providerSessionId);
            }
        } catch (Throwable $exception) {
            $result = new AgentHarnessResult(false, null, null, null, $exception->getMessage());
        }

        return new AgentTurnExecution($run, $result, $this->informationalSummary($result));
    }

    /**
     * Reduce provider terminal output to an informational summary without treating it as workflow truth.
     */
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

    /**
     * Resolve the Agent role authorized by the durable WorkRequest or accepted Task handoff.
     */
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

    /**
     * Resolve the workflow execution mode from the durable subject state and latest accepted handoff.
     */
    private function modeFor(Task|WorkRequest $subject): string
    {
        if ($subject instanceof WorkRequest) {
            return $subject->tasks()->exists() ? 'dependency_handoff' : 'initial_planning';
        }

        return (string) ($subject->last_handoff['reason'] ?? 'implementation_ready');
    }

    /**
     * Resolve the Project that owns the Task or WorkRequest execution subject.
     */
    private function projectFor(Task|WorkRequest $subject): Project
    {
        if ($subject instanceof WorkRequest) {
            $subject->loadMissing('project');

            return $subject->project;
        }

        $subject->loadMissing('workRequest.project');

        return $subject->workRequest->project;
    }

    /**
     * Resolve the repository path and whether this role is authorized to write implementation changes.
     *
     * @return array{0: string, 1: bool}
     */
    private function executionTarget(Task|WorkRequest $subject): array
    {
        $writable = $subject instanceof Task
            && ($subject->last_handoff['to_role'] ?? null) === 'coder';

        if (
            $subject instanceof Task
            && filled($subject->worktree_path)
            && is_dir((string) $subject->worktree_path)
        ) {
            return [(string) $subject->worktree_path, $writable];
        }

        return [(string) $this->projectFor($subject)->path, $writable];
    }

    /**
     * Build the role-specific durable workflow contract without duplicating generic documentation policy.
     */
    private function contractSection(Task|WorkRequest $subject, string $role, string $mode): string
    {
        if ($subject instanceof WorkRequest) {
            return <<<'PROMPT'
DURABLE WORKFLOW CONTRACT
Inspect the repository read-only. Your final message is informational and never controls workflow state.
For a new request, call save_task_plan. Every dependency-ready Task must be handed off to Coder using handoff_task with reason "implementation_ready".
For an existing plan, hand off each newly dependency-ready Task that has not already been handed off.
If the entire request already exists or is deterministically blocked, use record_workflow_outcome instead of inventing Tasks.
PROMPT;
        }

        if ($role === 'qa') {
            return <<<'PROMPT'
DURABLE WORKFLOW CONTRACT
You have read-only access. Use get_task_context and review the exact candidate_tree_sha.
Before recording a QA decision, if the Task has verification_commands, you MUST call run_project_verification with profile "ci" and a fresh idempotency key for this verification attempt. Do not classify required PHP, type, or build checks as provider-environment blockers until the host-controlled verification result is available.
If you find a code-level defect, call save_qa_review with "changes_requested". The resulting handoff_task must return the Task to Coder with reason "changes_requested".
If the candidate has no code-level defects and verification is blocked only by an unavailable or misconfigured external environment, use record_workflow_outcome for this Task with outcome "blocked" and include the environment evidence. Do not create a Coder repair handoff.
Otherwise, call save_qa_review with "approved". The resulting handoff_task must return the approved candidate to Coder with reason "approved".
Your final message is informational only. Do not edit or commit.
PROMPT;
        }

        if ($mode === 'approved') {
            return <<<'PROMPT'
DURABLE WORKFLOW CONTRACT
This is approved finalization mode. Do not modify the implementation.
When candidate_kind is "changes", commit the approved candidate before finalization. For "no_change", do not create a commit.
Use finalize_task as the workflow-ending action after completing the required finalization work.
Your final message is informational only.
PROMPT;
        }

        return <<<PROMPT
DURABLE WORKFLOW CONTRACT
Coder mode: {$mode}. Use get_task_context, perform the required implementation or repair, test it, and call save_task_result.
The successful workflow-ending action is handoff_task to QA for the new candidate. Do not commit before QA approval.
Use record_workflow_outcome only for a deterministic blocker that prevents normal completion.
Your final message is informational only.
PROMPT;
    }

    /**
     * Build one role-agnostic host verification contract inherited by every Agent turn.
     */
    private function projectVerificationContractSection(): string
    {
        return <<<'PROMPT'
HOST-CONTROLLED PROJECT VERIFICATION
When required verification depends on Docker, databases, Redis, browsers, or host infrastructure that your provider sandbox cannot access, use run_project_verification with an operator-approved profile and a unique idempotency key for that logical attempt.
Never invoke Docker directly as a workaround. Never invent a host command, service name, container name, Docker option, environment variable, mount, or shell command.
A repeated call for the same logical attempt must reuse its idempotency key. A genuinely new verification attempt after relevant work changes must use a new idempotency key.
Treat "passed" as successful verification and "failed" as an executed verification whose checks failed.
Treat "environment_unavailable" as external verification infrastructure evidence, not automatically as a code defect.
Treat "timed_out" separately and determine from the evidence whether the timeout indicates code behavior or external infrastructure.
Treat "stale_candidate" as unusable evidence because the durable candidate identity changed.
QA verification of a Task must apply to the exact candidate_tree_sha being reviewed. Do not approve a different mutable checkout.
PROMPT;
    }

    /**
     * Build one role-agnostic vault documentation invariant inherited by every provider turn.
     */
    private function vaultDocumentationContractSection(): string
    {
        return <<<'PROMPT'
VAULT DOCUMENTATION INVARIANT
Perform your normal role-specific work first, including applicable intermediate durable actions such as save_task_plan, save_task_result, or save_qa_review.
Then use get_vault_rules, starting at "." and checking the intended destination directory when needed, to follow the applicable vault governance and choose a valid vault-relative Markdown destination.
Call write_vault_work_log exactly once with one concise Agent-authored Markdown work note summarizing the completed work, evidence, decisions, and blockers. The work note is a summary, not a transcript of the conversation or provider output.
Only after write_vault_work_log succeeds may you call a workflow-ending tool such as handoff_task, finalize_task, or record_workflow_outcome, as required by your role-specific workflow.
Do not finish a satisfied, handed-off, finalized, or terminal/blocked turn unless the vault work note succeeds.
PROMPT;
    }
}
