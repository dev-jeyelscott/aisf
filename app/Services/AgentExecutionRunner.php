<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\Task;
use App\Models\WorkRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Run one Agent execution against a Task or WorkRequest and return its minimal, self-reported completion.
 *
 * Laravel owns only: which Agent role to invoke, repository/worktree isolation, and durable execution
 * auditing (AgentSession/AgentRun). The Agent owns how the work is planned, implemented, tested,
 * reviewed, fixed, and committed — this class enforces no workflow-shape contract beyond
 * {status, summary, handoff?, commit_sha?, tasks?, already_implemented?}.
 */
class AgentExecutionRunner
{
    /** @var array<int, string> */
    private const STATUSES = ['completed', 'waiting', 'failed'];

    public function __construct(
        private readonly AgentHarness $harness,
        private readonly AgentSessionManager $sessionManager,
        private readonly TaskWorktreeManager $worktreeManager,
        private readonly AgentPromptComposer $promptComposer,
        private readonly TaskContextBuilder $taskContextBuilder,
    ) {}

    /**
     * @return array{status: string, summary: string, handoff: array<string, mixed>|null, commit_sha: string|null, tasks: list<array<string, mixed>>|null, already_implemented: bool|null, delegations: list<array<string, mixed>>, review: array<string, mixed>|null, agent_run_id: int}
     */
    public function run(Task|WorkRequest $subject, ?string $operatorInstruction = null): array
    {
        $role = $this->roleFor($subject);
        $project = $this->projectFor($subject);
        $agent = $project->agents()
            ->where('role', $role)
            ->where('enabled', true)
            ->first();

        if (! $agent instanceof ProjectAgent) {
            throw new UnexpectedValueException(sprintf(
                'No enabled %s Agent is configured for this Project.',
                $role,
            ));
        }

        $session = $this->sessionManager->forSubject($agent, $subject);
        [$repositoryPath, $writable] = $this->executionTarget($subject);
        $promptContext = $this->promptComposer->compose($agent, $subject, $repositoryPath, $operatorInstruction);
        $executionToken = Str::random(64);
        $prompt = $promptContext['prompt']."\n\n".$this->contractSection($subject, $role)."\n\nACTIVE RUN AUTHORIZATION\nAgent run token: {$executionToken}";
        if ($subject instanceof Task) {
            $prompt .= "\n\nDURABLE TASK CONTEXT\n".json_encode($this->taskContextBuilder->forTask($subject), JSON_THROW_ON_ERROR);
        }

        $run = $this->sessionManager->startRun($session, $role, [
            'mode' => 'initial',
            'input' => $prompt,
            'sources' => $promptContext['sources'],
            'agent_snapshot' => $promptContext['snapshot']['agent'],
            'prompt_snapshot' => $promptContext['snapshot'],
            'role' => $role,
            'execution_token' => $executionToken,
        ]);
        $prompt .= "\nAgent run ID: {$run->id}";
        $run->update([
            'submitted_input' => $prompt,
            'execution_metadata' => $subject instanceof Task
                ? ['accepted_handoff_id' => $subject->last_handoff['id'] ?? null]
                : [],
        ]);

        try {
            $result = $this->harness->start($agent, $repositoryPath, $prompt, self::schema(), $writable);
        } catch (Throwable $exception) {
            $this->sessionManager->failRun($run, $exception);

            throw $exception;
        }

        if (! $result->successful) {
            $exception = new RuntimeException($result->failureMessage ?? 'Agent execution failed.');
            $this->sessionManager->failRun($run, $exception, $result->exitCode);

            throw $exception;
        }

        // Every execution starts fresh (no resume/delta continuity in this design), so a new
        // provider thread ID is expected each time — do not compare it against a prior one.

        try {
            $completion = $this->parseCompletion((string) $result->output, $subject);
        } catch (UnexpectedValueException $exception) {
            $this->sessionManager->failRun($run, $exception, $result->exitCode);

            throw $exception;
        }

        $this->sessionManager->completeRun($run, $completion['summary'], $result->exitCode, executionMetadata: [
            'completion' => $completion,
            'harness' => $agent->harness,
            'model' => $agent->model,
        ]);

        foreach ($completion['delegations'] as $delegation) {
            $this->sessionManager->recordDelegation($run, $delegation);
        }

        return [...$completion, 'agent_run_id' => $run->id];
    }

    /**
     * Verify an Agent-reported commit, require the Project's CI check to pass, and only then open a pull
     * request. If CI fails, hand the Task back to the Coder with the failure output instead of opening a PR.
     *
     * @return array{integrated: true, commit_sha: string, pull_request_url: string}|array{integrated: false, ci_output: string}|null
     */
    public function integrateReportedCommit(Task $task, ?string $commitSha, string $summary): ?array
    {
        if (! filled($commitSha)) {
            return null;
        }

        $verifiedSha = $this->worktreeManager->verifyCommitExists($task, $commitSha);
        $this->worktreeManager->verifyHeadMatches($task, $verifiedSha);
        $ci = $this->worktreeManager->runCiCheck($task);

        if (! $ci['passed']) {
            return ['integrated' => false, 'ci_output' => $ci['output']];
        }

        $pullRequest = $this->worktreeManager->pushAndOpenPullRequest($task, $verifiedSha, $task->title, $summary);

        return ['integrated' => true, ...$pullRequest];
    }

    public function mergeVerifiedCandidate(Task $task): void
    {
        $this->worktreeManager->mergePullRequest($task, (string) $task->candidate_sha);
    }

    /**
     * The minimal completion contract every role must satisfy. Everything else is the Agent's judgment call.
     *
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['status', 'summary', 'handoff', 'commit_sha', 'tasks', 'already_implemented', 'delegations', 'review'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => self::STATUSES],
                'summary' => ['type' => 'string'],
                'handoff' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => false,
                    'required' => ['to_role', 'note'],
                    'properties' => [
                        'to_role' => ['type' => ['string', 'null']],
                        'note' => ['type' => ['string', 'null']],
                    ],
                ],
                'commit_sha' => ['type' => ['string', 'null']],
                'tasks' => [
                    'type' => ['array', 'null'],
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'objective', 'implementation_spec', 'acceptance_criteria', 'verification_commands', 'browser_steps', 'depends_on_position', 'assigned_agent_role'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'objective' => ['type' => ['string', 'null']],
                            'implementation_spec' => ['type' => ['string', 'null']],
                            'acceptance_criteria' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'verification_commands' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'browser_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'depends_on_position' => ['type' => ['integer', 'null']],
                            'assigned_agent_role' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
                'already_implemented' => ['type' => ['boolean', 'null']],
                'delegations' => [
                    'type' => ['array', 'null'],
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['purpose', 'role', 'status', 'evidence'],
                        'properties' => [
                            'purpose' => ['type' => 'string'],
                            'role' => ['type' => 'string'],
                            'status' => ['type' => 'string'],
                            'evidence' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
                'review' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => false,
                    'required' => ['candidate_sha', 'status', 'summary', 'findings'],
                    'properties' => [
                        'candidate_sha' => ['type' => 'string'],
                        'status' => ['type' => 'string', 'enum' => ['approved', 'changes_requested']],
                        'summary' => ['type' => 'string'],
                        'findings' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{status: string, summary: string, handoff: array<string, mixed>|null, commit_sha: string|null, tasks: list<array<string, mixed>>|null, already_implemented: bool|null, delegations: list<array<string, mixed>>, review: array<string, mixed>|null}
     */
    private function parseCompletion(string $output, Task|WorkRequest $subject): array
    {
        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('The Agent response was not valid JSON.');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new UnexpectedValueException('The Agent response must be one structured JSON object.');
        }

        $validator = Validator::make($decoded, [
            'status' => ['required', 'string', 'in:'.implode(',', self::STATUSES)],
            'summary' => ['required', 'string'],
            'handoff' => ['nullable', 'array'],
            'handoff.to_role' => ['nullable', 'string', 'max:100'],
            'handoff.note' => ['nullable', 'string'],
            'commit_sha' => ['nullable', 'string'],
            'tasks' => ['nullable', 'array'],
            'tasks.*.title' => ['required_with:tasks', 'string'],
            'tasks.*.objective' => ['nullable', 'string'],
            'tasks.*.implementation_spec' => ['nullable', 'string'],
            'tasks.*.acceptance_criteria' => ['nullable', 'array'],
            'tasks.*.acceptance_criteria.*' => ['string'],
            'tasks.*.verification_commands' => ['nullable', 'array'],
            'tasks.*.verification_commands.*' => ['string'],
            'tasks.*.browser_steps' => ['nullable', 'array'],
            'tasks.*.browser_steps.*' => ['string'],
            'tasks.*.depends_on_position' => ['nullable', 'integer', 'min:1'],
            'tasks.*.assigned_agent_role' => ['nullable', 'string', 'max:100'],
            'already_implemented' => ['nullable', 'boolean'],
            'delegations' => ['nullable', 'array'],
            'delegations.*.purpose' => ['required_with:delegations', 'string'],
            'delegations.*.role' => ['required_with:delegations', 'string'],
            'delegations.*.status' => ['required_with:delegations', 'string'],
            'delegations.*.evidence' => ['nullable', 'string'],
            'review' => ['nullable', 'array'],
            'review.candidate_sha' => ['required_with:review', 'string'],
            'review.status' => ['required_with:review', 'string', 'in:approved,changes_requested'],
            'review.summary' => ['required_with:review', 'string'],
            'review.findings' => ['required_with:review', 'array'],
        ]);

        if ($validator->fails()) {
            throw new UnexpectedValueException(
                'The Agent response does not satisfy the minimal completion contract (status, summary).',
            );
        }

        $summary = trim((string) $decoded['summary']);

        if ($summary === '') {
            throw new UnexpectedValueException('The Agent summary cannot be empty.');
        }

        return [
            'status' => $decoded['status'],
            'summary' => $summary,
            'handoff' => $subject instanceof Task ? ($decoded['handoff'] ?? null) : null,
            'commit_sha' => filled($decoded['commit_sha'] ?? null) ? trim((string) $decoded['commit_sha']) : null,
            'tasks' => $subject instanceof WorkRequest ? ($decoded['tasks'] ?? null) : null,
            'already_implemented' => $subject instanceof WorkRequest ? ($decoded['already_implemented'] ?? null) : null,
            'delegations' => array_values(is_array($decoded['delegations'] ?? null) ? $decoded['delegations'] : []),
            'review' => is_array($decoded['review'] ?? null) ? $decoded['review'] : null,
        ];
    }

    /**
     * Determine the configured Agent role for a subject, defaulting to the Foreman.
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
     * @return array{0: string, 1: bool} repository path and whether the Agent may write to it
     */
    private function executionTarget(Task|WorkRequest $subject): array
    {
        if ($subject instanceof WorkRequest) {
            $project = $this->projectFor($subject);

            return [$project->path, false];
        }

        if ($subject->last_handoff['to_role'] === 'qa') {
            $this->worktreeManager->ensureWorktree($subject);
            $subject->refresh();

            return [(string) $subject->worktree_path, false];
        }

        if ($subject->last_handoff['to_role'] !== 'coder') {
            return [(string) $this->projectFor($subject)->path, false];
        }

        $this->worktreeManager->ensureWorktree($subject);
        $subject->refresh();

        return [(string) $subject->worktree_path, true];
    }

    private function contractSection(Task|WorkRequest $subject, string $role): string
    {
        if ($subject instanceof WorkRequest) {
            return <<<'PROMPT'
RESPONSE CONTRACT
Inspect the repository read-only; do not edit, install, run commands that mutate state, or commit.
Return only one JSON object matching the supplied schema.
Set "status" to "completed" once you have finished deciding what to do about this request, "waiting" if you need another turn to keep planning, or "failed" if the request cannot be planned.
Decide for yourself what fields each Task needs — a documentation-only Task needs no acceptance criteria or browser steps. Use save_task_plan to persist one or more structured Tasks, then call handoff_task for every dependency-ready Task using to_role "coder" and reason "implementation_ready". Leave "tasks" empty or omit it, and set "already_implemented" to true with a concrete reason in "summary", if the requested behavior already exists.
PROMPT;
        }

        if ($role === 'qa') {
            return <<<'PROMPT'
RESPONSE CONTRACT
You have read-only access. Read the durable Task context using get_task_context, inspect the worktree diff and Coder evidence, then save_qa_review and handoff_task. Do not edit files or commit.
Return only one JSON object matching the supplied schema.
Set "status" to "completed" when your review handoff has been accepted.
PROMPT;
        }

        return <<<'PROMPT'
RESPONSE CONTRACT
You have write access only to this isolated Task worktree. First use get_task_context. Implement and test the Task, then use save_task_result and handoff_task to QA. Do not create a Git commit before QA approval.
Return only one JSON object matching the supplied schema and set "status" to "completed" only after the QA handoff has been accepted.
PROMPT;
    }
}
