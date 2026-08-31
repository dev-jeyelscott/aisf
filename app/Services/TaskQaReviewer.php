<?php

namespace App\Services;

use App\Models\AgentSession;
use App\Models\Task;
use Illuminate\Support\Facades\Validator;
use JsonException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class TaskQaReviewer
{
    private const STATUSES = ['approved', 'changes_required', 'manual_browser_check_required'];

    /**
     * Create the QA review execution service with durable session, harness, and worktree collaborators.
     */
    public function __construct(
        private readonly AgentHarness $harness,
        private readonly AgentContextAssembler $contextAssembler,
        private readonly AgentSessionManager $sessionManager,
        private readonly TaskWorktreeManager $worktreeManager,
    ) {}

    /**
     * Execute the enabled QA Agent read-only inside the Task worktree and return a strictly validated review.
     *
     * @return array{session: AgentSession, completion: array{status: string, summary: string, acceptance_criteria_results: list<array{criterion: string, met: bool, note: string}>, verification_results: list<array{command: string, passed: bool, notes: string}>, browser_result: array{mode: string, passed: bool|null, notes: string}, findings: list<string>}}
     */
    public function run(Task $task): array
    {
        $task->loadMissing('workRequest.project');

        if (blank($task->worktree_path) || ! is_dir($task->worktree_path)) {
            throw new UnexpectedValueException(
                'The Task worktree must exist before Quality Assurance can review it.',
            );
        }

        $project = $task->workRequest->project;

        $agent = $project->agents()
            ->where('role', 'quality_assurance_specialist')
            ->where('enabled', true)
            ->first();

        if ($agent === null) {
            throw new UnexpectedValueException(
                'The Project requires an enabled Quality Assurance Specialist Agent before review can run.',
            );
        }

        $session = $this->sessionManager->forSubject($agent, $task);
        $isRereview = $session->runs()->where('status', 'succeeded')->exists();
        $changedFiles = $this->worktreeManager->changedFiles($task);

        if ($isRereview) {
            $latestCoderRun = $task->agentSessions()
                ->whereHas('projectAgent', fn ($query) => $query->where('role', 'coder'))
                ->with('runs')
                ->get()
                ->flatMap(fn (AgentSession $coderSession) => $coderSession->runs)
                ->where('status', 'succeeded')
                ->sortByDesc('attempt')
                ->first();

            if ($latestCoderRun === null) {
                throw new UnexpectedValueException(
                    'A successful Coder fix run is required before QA can re-review the Task.',
                );
            }

            $latestChangesRequired = $task->qaReviews()
                ->where('status', 'changes_required')
                ->latest('id')
                ->first();

            $context = $this->contextAssembler->qaRereviewDelta(
                (string) $latestCoderRun->output_summary,
                $latestChangesRequired?->findings ?? [],
                $changedFiles,
            );
            $purpose = 'qa_rereview';
        } else {
            $context = $this->contextAssembler->qaInitial(
                $task,
                $agent,
                (string) $task->worktree_path,
                $changedFiles,
            );
            $purpose = 'qa_review';
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
                    writable: false,
                )
                : $this->harness->start(
                    $agent,
                    (string) $task->worktree_path,
                    $context['input'],
                    $schema,
                    writable: false,
                );

            $this->sessionManager->captureProviderSessionId(
                $session,
                $result->providerSessionId,
            );

            if (! $result->successful || $result->output === null) {
                throw new RuntimeException(
                    $result->failureMessage
                        ?? 'Quality Assurance harness execution failed.',
                );
            }

            $completion = $this->validateOutput($result->output, $task);

            $this->sessionManager->completeRun(
                $run,
                $completion['summary'],
                $result->exitCode,
            );

            return ['session' => $session, 'completion' => $completion];
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
     * Build the exact JSON schema required from the QA harness.
     *
     * @return array<string, mixed>
     */
    private function completionSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['status', 'summary', 'acceptance_criteria_results', 'verification_results', 'browser_result', 'findings'],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => self::STATUSES,
                ],
                'summary' => [
                    'type' => 'string',
                    'minLength' => 1,
                ],
                'acceptance_criteria_results' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['criterion', 'met', 'note'],
                        'properties' => [
                            'criterion' => ['type' => 'string', 'minLength' => 1],
                            'met' => ['type' => 'boolean'],
                            'note' => ['type' => 'string'],
                        ],
                    ],
                ],
                'verification_results' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['command', 'passed', 'notes'],
                        'properties' => [
                            'command' => ['type' => 'string', 'minLength' => 1],
                            'passed' => ['type' => 'boolean'],
                            'notes' => ['type' => 'string'],
                        ],
                    ],
                ],
                'browser_result' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['mode', 'passed', 'notes'],
                    'properties' => [
                        'mode' => ['type' => 'string', 'enum' => ['automated', 'manual']],
                        'passed' => ['type' => ['boolean', 'null']],
                        'notes' => ['type' => 'string'],
                    ],
                ],
                'findings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * Decode and strictly validate the QA completion payload before it can reach persistence.
     *
     * @return array{status: string, summary: string, acceptance_criteria_results: list<array{criterion: string, met: bool, note: string}>, verification_results: list<array{command: string, passed: bool, notes: string}>, browser_result: array{mode: string, passed: bool|null, notes: string}, findings: list<string>}
     */
    private function validateOutput(string $output, Task $task): array
    {
        try {
            $completion = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(
                'The Quality Assurance Agent returned malformed JSON.',
                previous: $exception,
            );
        }

        if (! is_array($completion) || array_is_list($completion)) {
            throw new UnexpectedValueException(
                'The Quality Assurance response must be one structured JSON object.',
            );
        }

        $expectedKeys = ['status', 'summary', 'acceptance_criteria_results', 'verification_results', 'browser_result', 'findings'];
        $actualKeys = array_keys($completion);
        sort($actualKeys);
        $sortedExpectedKeys = $expectedKeys;
        sort($sortedExpectedKeys);

        if ($actualKeys !== $sortedExpectedKeys) {
            throw new UnexpectedValueException(
                'Quality Assurance response contains missing or unexpected fields.',
            );
        }

        $validator = Validator::make($completion, [
            'status' => ['required', 'string', 'in:'.implode(',', self::STATUSES)],
            'summary' => ['required', 'string'],
            'acceptance_criteria_results' => ['present', 'array'],
            'acceptance_criteria_results.*.criterion' => ['required', 'string'],
            'acceptance_criteria_results.*.met' => ['required', 'boolean'],
            'acceptance_criteria_results.*.note' => ['present', 'string'],
            'verification_results' => ['present', 'array'],
            'verification_results.*.command' => ['required', 'string'],
            'verification_results.*.passed' => ['required', 'boolean'],
            'verification_results.*.notes' => ['present', 'string'],
            'browser_result' => ['required', 'array'],
            'browser_result.mode' => ['required', 'string', 'in:automated,manual,not_required'],
            'browser_result.passed' => ['present', 'nullable', 'boolean'],
            'browser_result.notes' => ['present', 'string'],
            'findings' => ['present', 'array'],
            'findings.*' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            throw new UnexpectedValueException(
                'The Quality Assurance response does not satisfy the required completion contract.',
            );
        }

        /**
         * @var array{status: string, summary: string, acceptance_criteria_results: array<int, array{criterion: string, met: bool, note: string}>, verification_results: array<int, array{command: string, passed: bool, notes: string}>, browser_result: array{mode: string, passed: bool|null, notes: string}, findings: array<int, string>} $completion
         */
        $summary = trim($completion['summary']);

        if ($summary === '') {
            throw new UnexpectedValueException(
                'The Quality Assurance summary cannot be empty.',
            );
        }

        $this->assertEveryAcceptanceCriterionCovered($task->acceptance_criteria, $completion['acceptance_criteria_results']);
        $this->assertEveryVerificationCommandCovered($task->verification_commands, $completion['verification_results']);

        $status = $completion['status'];
        $findings = array_values(array_map(static fn (string $value): string => trim($value), $completion['findings']));

        if ($status === 'changes_required' && (in_array('', $findings, true) || $findings === [])) {
            throw new UnexpectedValueException(
                'Quality Assurance changes-required responses must include at least one concrete, non-empty finding.',
            );
        }

        $allCriteriaMet = collect($completion['acceptance_criteria_results'])->every(fn (array $result): bool => $result['met'] === true);
        $allVerificationPassed = collect($completion['verification_results'])->every(fn (array $result): bool => $result['passed'] === true);
        $browserResult = $completion['browser_result'];
        $requiresBrowserCheck = $task->browser_steps !== [];

        if (! $requiresBrowserCheck && ($browserResult['mode'] !== 'not_required' || $browserResult['passed'] !== null)) {
            throw new UnexpectedValueException(
                'Quality Assurance must mark the browser check as not required when the Task has no browser test steps.',
            );
        }

        if ($status === 'manual_browser_check_required' && (! $requiresBrowserCheck || $browserResult['mode'] !== 'manual')) {
            throw new UnexpectedValueException(
                'A manual browser check status requires browser test steps and the browser result mode to be manual.',
            );
        }

        if ($status === 'manual_browser_check_required' && (! $allCriteriaMet || ! $allVerificationPassed)) {
            throw new UnexpectedValueException(
                'Quality Assurance cannot request a manual browser check while acceptance criteria or verification commands remain unmet.',
            );
        }

        if ($status === 'approved') {
            if (! $allCriteriaMet || ! $allVerificationPassed) {
                throw new UnexpectedValueException(
                    'Quality Assurance cannot approve the Task while acceptance criteria or verification commands remain unmet.',
                );
            }

            if ($requiresBrowserCheck && ($browserResult['mode'] !== 'automated' || $browserResult['passed'] !== true)) {
                throw new UnexpectedValueException(
                    'Quality Assurance can only approve directly when a required automated browser check has passed. Otherwise, request a manual browser check.',
                );
            }
        }

        return [
            'status' => $status,
            'summary' => $summary,
            'acceptance_criteria_results' => array_values($completion['acceptance_criteria_results']),
            'verification_results' => array_values($completion['verification_results']),
            'browser_result' => $browserResult,
            'findings' => $findings,
        ];
    }

    /**
     * Confirm QA returned exactly one result per Task acceptance criterion, matching the exact criterion text.
     *
     * @param  list<string>  $criteria
     * @param  array<int, array{criterion: string, met: bool, note: string}>  $results
     */
    private function assertEveryAcceptanceCriterionCovered(array $criteria, array $results): void
    {
        $reviewedCriteria = array_map(static fn (array $result): string => $result['criterion'], $results);
        sort($criteria);
        sort($reviewedCriteria);

        if ($criteria !== $reviewedCriteria) {
            throw new UnexpectedValueException(
                'Quality Assurance must evaluate every Task acceptance criterion exactly once.',
            );
        }
    }

    /**
     * Confirm QA returned exactly one result per Task verification command, matching the exact command text.
     *
     * @param  list<string>  $commands
     * @param  array<int, array{command: string, passed: bool, notes: string}>  $results
     */
    private function assertEveryVerificationCommandCovered(array $commands, array $results): void
    {
        $reviewedCommands = array_map(static fn (array $result): string => $result['command'], $results);
        sort($commands);
        sort($reviewedCommands);

        if ($commands !== $reviewedCommands) {
            throw new UnexpectedValueException(
                'Quality Assurance must run and report on every Task verification command exactly once.',
            );
        }
    }
}
