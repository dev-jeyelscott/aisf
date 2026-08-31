<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\Task;
use App\Models\WorkRequest;
use Illuminate\Support\Facades\Validator;
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

    /** @var array<int, string> */
    private const TASK_ROLES = ['coder', 'quality_assurance_specialist'];

    public function __construct(
        private readonly AgentHarness $harness,
        private readonly AgentSessionManager $sessionManager,
        private readonly TaskWorktreeManager $worktreeManager,
    ) {}

    /**
     * @return array{status: string, summary: string, handoff: array<string, mixed>|null, commit_sha: string|null, tasks: list<array<string, mixed>>|null, already_implemented: bool|null}
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
        [$repositoryPath, $writable] = $this->executionTarget($subject, $role);
        $prompt = $this->buildPrompt($subject, $agent, $role, $repositoryPath, $operatorInstruction);

        $run = $this->sessionManager->startRun($session, $role, [
            'mode' => 'initial',
            'input' => $prompt,
            'sources' => [
                ['type' => 'agent_identity', 'label' => ucfirst(str_replace('_', ' ', $role)).' identity'],
                ['type' => 'agent_workflow', 'label' => 'Agent workflow instructions'],
                ['type' => 'subject', 'label' => $subject instanceof WorkRequest ? 'WorkRequest' : 'Task'],
            ],
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

        $this->sessionManager->completeRun($run, $completion['summary'], $result->exitCode);

        return $completion;
    }

    /**
     * Verify and integrate an Agent-reported commit for a completed Task, or return null if none was reported.
     *
     * @return array{commit_sha: string, worktree_cleaned: bool, branch_deleted: bool}|null
     */
    public function integrateReportedCommit(Task $task, ?string $commitSha): ?array
    {
        if (! filled($commitSha)) {
            return null;
        }

        $verifiedSha = $this->worktreeManager->verifyCommitExists($task, $commitSha);

        return $this->worktreeManager->integrateCommit($task, $verifiedSha);
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
            'required' => ['status', 'summary', 'handoff', 'commit_sha', 'tasks', 'already_implemented'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => self::STATUSES],
                'summary' => ['type' => 'string'],
                'handoff' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => false,
                    'required' => ['to_role', 'note'],
                    'properties' => [
                        'to_role' => ['type' => ['string', 'null'], 'enum' => [...self::TASK_ROLES, null]],
                        'note' => ['type' => ['string', 'null']],
                    ],
                ],
                'commit_sha' => ['type' => ['string', 'null']],
                'tasks' => [
                    'type' => ['array', 'null'],
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'objective', 'implementation_spec', 'depends_on_position'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'objective' => ['type' => ['string', 'null']],
                            'implementation_spec' => ['type' => ['string', 'null']],
                            'depends_on_position' => ['type' => ['integer', 'null']],
                        ],
                    ],
                ],
                'already_implemented' => ['type' => ['boolean', 'null']],
            ],
        ];
    }

    /**
     * @return array{status: string, summary: string, handoff: array<string, mixed>|null, commit_sha: string|null, tasks: list<array<string, mixed>>|null, already_implemented: bool|null}
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
            'handoff.to_role' => ['nullable', 'string', 'in:'.implode(',', self::TASK_ROLES)],
            'handoff.note' => ['nullable', 'string'],
            'commit_sha' => ['nullable', 'string'],
            'tasks' => ['nullable', 'array'],
            'tasks.*.title' => ['required_with:tasks', 'string'],
            'tasks.*.objective' => ['nullable', 'string'],
            'tasks.*.implementation_spec' => ['nullable', 'string'],
            'tasks.*.depends_on_position' => ['nullable', 'integer', 'min:1'],
            'already_implemented' => ['nullable', 'boolean'],
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
        ];
    }

    /**
     * Determine which Agent role should run next: the Project Manager for a WorkRequest, or the role the
     * last Task execution handed off to (defaulting to the Coder for a fresh Task).
     */
    private function roleFor(Task|WorkRequest $subject): string
    {
        if ($subject instanceof WorkRequest) {
            return 'project_manager';
        }

        $toRole = $subject->last_handoff['to_role'] ?? null;

        return in_array($toRole, self::TASK_ROLES, true) ? $toRole : 'coder';
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
    private function executionTarget(Task|WorkRequest $subject, string $role): array
    {
        if ($subject instanceof WorkRequest) {
            $project = $this->projectFor($subject);

            return [$project->path, false];
        }

        $this->worktreeManager->ensureWorktree($subject);
        $subject->refresh();

        return [(string) $subject->worktree_path, true];
    }

    private function buildPrompt(
        Task|WorkRequest $subject,
        ProjectAgent $agent,
        string $role,
        string $repositoryPath,
        ?string $operatorInstruction,
    ): string {
        $identity = filled($agent->identity) ? $agent->identity : "Act as the {$agent->name} for this software project.";
        $defaultContext = filled($agent->default_context) ? $agent->default_context : 'No additional default context configured.';
        $workflow = filled($agent->workflow_instructions) ? $agent->workflow_instructions : 'No additional workflow instructions configured.';
        $skills = $agent->skills()->where('project_skills.enabled', true)->get();
        $skillContext = $skills->isEmpty()
            ? 'No enabled Skills are assigned.'
            : $skills->map(fn ($skill, $index) => sprintf(
                "Skill %d: %s\n%s",
                $index + 1,
                $skill->name,
                $skill->instructions,
            ))->implode("\n\n");

        $sections = [
            $identity,
            "DEFAULT CONTEXT\n{$defaultContext}",
            "WORKFLOW INSTRUCTIONS\n{$workflow}",
            "ENABLED SKILLS\n{$skillContext}",
            "REPOSITORY PATH\n{$repositoryPath}",
        ];

        if ($subject instanceof WorkRequest) {
            $sections[] = "WORK REQUEST\n{$subject->prompt}";
        } else {
            $sections[] = "TASK\nTitle: {$subject->title}\nObjective: {$subject->objective}\nImplementation notes: ".(filled($subject->implementation_spec) ? $subject->implementation_spec : 'None provided.');

            $note = $subject->last_handoff['note'] ?? null;

            if (filled($note)) {
                $sections[] = "PREVIOUS AGENT HANDOFF\n{$note}";
            }
        }

        if (filled($operatorInstruction)) {
            $sections[] = "OPERATOR INSTRUCTION\n{$operatorInstruction}";
        }

        $sections[] = $this->contractSection($subject);

        return implode("\n\n", $sections);
    }

    private function contractSection(Task|WorkRequest $subject): string
    {
        if ($subject instanceof WorkRequest) {
            return <<<'PROMPT'
RESPONSE CONTRACT
Inspect the repository read-only; do not edit, install, run commands that mutate state, or commit.
Return only one JSON object matching the supplied schema.
Set "status" to "completed" once you have finished deciding what to do about this request, "waiting" if you need another turn to keep planning, or "failed" if the request cannot be planned.
Decide for yourself what fields each Task needs — a documentation-only Task needs no acceptance criteria or browser steps. Only include the "tasks" array with one entry per Task you want created (title required; objective, implementation_spec, and depends_on_position — one-based, referencing only an earlier entry — are optional). Leave "tasks" empty or omit it, and set "already_implemented" to true with a concrete reason in "summary", if the requested behavior already exists.
PROMPT;
        }

        return <<<'PROMPT'
RESPONSE CONTRACT
You have write access to this isolated Task worktree only. Inspect, edit, run commands, test, and commit as you judge appropriate — there is no fixed process you must follow.
Return only one JSON object matching the supplied schema.
Set "status" to "completed" once you consider the assigned work fully done (or judge no further action is needed), "waiting" if you are handing off to another role for another turn, or "failed" if you cannot complete the work.
If you are handing off, set "handoff" to {"to_role": "coder"|"quality_assurance_specialist", "note": "..."} describing what the next turn should do. If you committed, set "commit_sha" to the exact commit SHA you created; otherwise leave it null. Only commit when you judge the work is ready to be committed — there is no fixed timing requirement.
PROMPT;
    }
}
