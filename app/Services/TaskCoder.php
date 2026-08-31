<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\Validator;
use JsonException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class TaskCoder
{
    /**
     * Create the Coder execution service with durable session and harness collaborators.
     */
    public function __construct(
        private readonly AgentHarness $harness,
        private readonly AgentContextAssembler $contextAssembler,
        private readonly AgentSessionManager $sessionManager,
    ) {}

    /**
     * Execute the enabled Coder Agent inside the Task worktree and return a strictly validated completion summary.
     *
     * When the Coder's logical session already has a succeeded run, this resumes that same session with only the
     * latest unresolved QA findings and any new operator instruction instead of replaying the original context.
     *
     * @return array{summary: string, verification_performed: list<string>}
     */
    public function run(Task $task, ?string $operatorInstruction = null): array
    {
        $task->loadMissing('workRequest.project');

        if (blank($task->worktree_path) || ! is_dir($task->worktree_path)) {
            throw new UnexpectedValueException(
                'The Task worktree must be created before the Coder can run.',
            );
        }

        $project = $task->workRequest->project;

        $agent = $project->agents()
            ->where('role', 'coder')
            ->where('enabled', true)
            ->first();

        if ($agent === null) {
            throw new UnexpectedValueException(
                'The Project requires an enabled Coder Agent before implementation can run.',
            );
        }

        $session = $this->sessionManager->forSubject($agent, $task);
        $isFixLoop = $session->runs()->where('status', 'succeeded')->exists();

        if ($isFixLoop) {
            $latestFindings = $task->qaReviews()
                ->where('status', 'changes_required')
                ->latest('id')
                ->first();

            if ($latestFindings === null) {
                throw new UnexpectedValueException(
                    'The Task requires the latest QA changes-required findings before the Coder fix loop can run.',
                );
            }

            $unresolvedCriteria = collect($latestFindings->acceptance_criteria_results)
                ->where('met', false)
                ->pluck('criterion')
                ->values()
                ->all();

            $context = $this->contextAssembler->coderFixDelta(
                $latestFindings->findings,
                $unresolvedCriteria,
                $operatorInstruction,
            );
            $purpose = 'coder_fix';
        } else {
            $context = $this->contextAssembler->coderInitial($task, $agent);
            $purpose = 'coder_implementation';
        }

        $run = $this->sessionManager->startRun($session, $purpose, $context);
        $schema = $this->completionSchema();
        $result = null;

        try {
            $result = $session->provider_session_id !== null
                ? $this->harness->resume(
                    $agent,
                    (string) $task->worktree_path,
                    (string) $session->provider_session_id,
                    $context['input'],
                    $schema,
                    writable: true,
                )
                : $this->harness->start(
                    $agent,
                    (string) $task->worktree_path,
                    $context['input'],
                    $schema,
                    writable: true,
                );

            $this->sessionManager->captureProviderSessionId(
                $session,
                $result->providerSessionId,
            );

            if (! $result->successful || $result->output === null) {
                throw new RuntimeException(
                    $result->failureMessage
                        ?? 'Coder harness execution failed.',
                );
            }

            $completion = $this->validateOutput($result->output);

            $this->sessionManager->completeRun(
                $run,
                $completion['summary'],
                $result->exitCode,
            );

            return $completion;
        } catch (Throwable $exception) {
            $this->sessionManager->failRun(
                $run,
                $exception,
                $result?->exitCode,
            );

            throw $exception;
        }
    }

    /**
     * Resume the approved Task's Coder session for its one permitted commit-only finalization run.
     *
     * @return array{commit_sha: string, commit_message: string}
     */
    public function finalizeCommit(Task $task): array
    {
        $task->loadMissing('workRequest.project');

        if ($task->status !== 'committing' || $task->approved_at === null) {
            throw new UnexpectedValueException('Only a persistently QA-approved Task may enter Coder commit finalization.');
        }

        if (blank($task->worktree_path) || ! is_dir($task->worktree_path)) {
            throw new UnexpectedValueException('The approved Task worktree must exist before Coder commit finalization can run.');
        }

        $agent = $task->workRequest->project->agents()
            ->where('role', 'coder')
            ->where('enabled', true)
            ->first();

        if ($agent === null) {
            throw new UnexpectedValueException('The Project requires an enabled Coder Agent before commit finalization can run.');
        }

        $session = $task->agentSessions()
            ->where('project_agent_id', $agent->id)
            ->first();

        if ($session === null || ! $session->runs()->where('status', 'succeeded')->exists()) {
            throw new UnexpectedValueException('A successful existing Coder Task session is required before commit finalization can resume.');
        }

        $context = $this->contextAssembler->coderCommitDelta();
        $run = $this->sessionManager->startRun($session, 'coder_commit', $context);
        $result = null;

        try {
            $result = $session->provider_session_id !== null
                ? $this->harness->resume($agent, (string) $task->worktree_path, (string) $session->provider_session_id, $context['input'], $this->commitCompletionSchema(), writable: true)
                : $this->harness->start($agent, (string) $task->worktree_path, $context['input'], $this->commitCompletionSchema(), writable: true);

            $this->sessionManager->captureProviderSessionId($session, $result->providerSessionId);

            if (! $result->successful || $result->output === null) {
                throw new RuntimeException($result->failureMessage ?? 'Coder commit finalization failed.');
            }

            $completion = $this->validateCommitOutput($result->output);
            $this->sessionManager->completeRun($run, $completion['commit_message'], $result->exitCode);

            return $completion;
        } catch (Throwable $exception) {
            $this->sessionManager->failRun($run, $exception, $result?->exitCode);

            throw $exception;
        }
    }

    /**
     * Build the exact JSON schema required from either supported Coder harness.
     *
     * @return array<string, mixed>
     */
    private function completionSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'verification_performed'],
            'properties' => [
                'summary' => [
                    'type' => 'string',
                    'minLength' => 1,
                ],
                'verification_performed' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'string',
                        'minLength' => 1,
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function commitCompletionSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['commit_sha', 'commit_message'],
            'properties' => [
                'commit_sha' => ['type' => 'string', 'minLength' => 40, 'maxLength' => 64],
                'commit_message' => ['type' => 'string', 'minLength' => 1],
            ],
        ];
    }

    /** @return array{commit_sha: string, commit_message: string} */
    private function validateCommitOutput(string $output): array
    {
        try {
            $completion = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('The Coder returned malformed commit finalization JSON.', previous: $exception);
        }

        if (! is_array($completion) || array_is_list($completion) || array_diff(array_keys($completion), ['commit_sha', 'commit_message']) !== [] || count($completion) !== 2) {
            throw new UnexpectedValueException('Coder commit finalization response contains missing or unexpected fields.');
        }

        $validator = Validator::make($completion, [
            'commit_sha' => ['required', 'string', 'regex:/^[0-9a-f]{40,64}$/'],
            'commit_message' => ['required', 'string'],
        ]);

        if ($validator->fails() || trim((string) $completion['commit_message']) === '') {
            throw new UnexpectedValueException('The Coder response does not satisfy the required commit finalization contract.');
        }

        return [
            'commit_sha' => trim((string) $completion['commit_sha']),
            'commit_message' => trim((string) $completion['commit_message']),
        ];
    }

    /**
     * Decode and strictly validate the Coder completion payload before it can reach persistence.
     *
     * @return array{summary: string, verification_performed: list<string>}
     */
    private function validateOutput(string $output): array
    {
        try {
            $completion = json_decode(
                $output,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(
                'The Coder returned malformed JSON.',
                previous: $exception,
            );
        }

        if (! is_array($completion) || array_is_list($completion)) {
            throw new UnexpectedValueException(
                'The Coder response must be one structured JSON object.',
            );
        }

        $actualKeys = array_keys($completion);
        $expectedKeys = ['summary', 'verification_performed'];
        sort($actualKeys);
        sort($expectedKeys);

        if ($actualKeys !== $expectedKeys) {
            throw new UnexpectedValueException(
                'Coder response contains missing or unexpected fields.',
            );
        }

        $validator = Validator::make($completion, [
            'summary' => ['required', 'string'],
            'verification_performed' => ['required', 'array', 'min:1'],
            'verification_performed.*' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            throw new UnexpectedValueException(
                'The Coder response does not satisfy the required completion contract.',
            );
        }

        /**
         * @var array{summary: string, verification_performed: array<int, string>} $completion
         */
        $summary = trim($completion['summary']);

        if ($summary === '') {
            throw new UnexpectedValueException(
                'The Coder summary cannot be empty.',
            );
        }

        $verificationPerformed = array_values(array_map(
            static fn (string $value): string => trim($value),
            $completion['verification_performed'],
        ));

        if (in_array('', $verificationPerformed, true)) {
            throw new UnexpectedValueException(
                'Coder verification steps cannot contain empty values.',
            );
        }

        return [
            'summary' => $summary,
            'verification_performed' => $verificationPerformed,
        ];
    }
}
