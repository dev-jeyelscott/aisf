<?php

namespace App\Services;

use App\Models\WorkRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class ProjectManagerPlanner
{
    /**
     * Create the Project Manager planning service with durable session and context collaborators.
     */
    public function __construct(
        private readonly AgentHarness $harness,
        private readonly AgentContextAssembler $contextAssembler,
        private readonly AgentSessionManager $sessionManager,
        private readonly RepositoryInspector $repositoryInspector,
    ) {}

    /**
     * Execute the enabled Project Manager Agent and return a strictly validated implementation plan.
     *
     * @return array{
     *     summary: string,
     *     already_implemented: bool,
     *     already_implemented_reason: string|null,
     *     tasks: list<array{
     *         title: string,
     *         objective: string,
     *         implementation_spec: string,
     *         acceptance_criteria: list<string>,
     *         verification_commands: list<string>,
     *         browser_test_steps: list<string>,
     *         depends_on_position: int|null
     *     }>
     * }
     */
    public function plan(WorkRequest $workRequest): array
    {
        $workRequest->loadMissing('project');
        $project = $workRequest->project;
        $repositoryPath = $this->repositoryInspector->normalizePath($project->path);
        $repositoryError = $this->repositoryInspector->validationError($repositoryPath);

        if ($repositoryError !== null) {
            throw new UnexpectedValueException($repositoryError);
        }

        $agent = $project->agents()
            ->where('role', 'project_manager')
            ->where('enabled', true)
            ->first();

        if ($agent === null) {
            throw new UnexpectedValueException('The Project requires an enabled Project Manager Agent before planning can run.');
        }

        $session = $this->sessionManager->forSubject($agent, $workRequest);
        $canResume = $session->runs()->exists()
            && filled($session->provider_session_id)
            && $this->harness->canResume($agent);

        $context = $canResume
            ? $this->contextAssembler->projectManagerRetryDelta()
            : $this->contextAssembler->projectManagerInitial(
                $workRequest,
                $agent,
                $repositoryPath,
            );

        $run = $this->sessionManager->startRun(
            $session,
            'project_manager_planning',
            $context,
        );
        $schema = $this->planningSchema();
        $result = null;

        try {
            $result = $canResume
                ? $this->harness->resume(
                    $agent,
                    $repositoryPath,
                    (string) $session->provider_session_id,
                    $context['input'],
                    $schema,
                )
                : $this->harness->start(
                    $agent,
                    $repositoryPath,
                    $context['input'],
                    $schema,
                );

            $this->sessionManager->captureProviderSessionId(
                $session,
                $result->providerSessionId,
            );

            if (! $result->successful || $result->output === null) {
                throw new RuntimeException(
                    $result->failureMessage ?? 'Project Manager harness execution failed.',
                );
            }

            $plan = $this->validateOutput($result->output, $repositoryPath);

            $this->sessionManager->completeRun(
                $run,
                $plan['summary'],
                $result->exitCode,
            );

            return $plan;
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
     * Build the exact JSON schema required from either supported planning harness.
     *
     * @return array<string, mixed>
     */
    private function planningSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'already_implemented', 'already_implemented_reason', 'tasks'],
            'properties' => [
                'summary' => ['type' => 'string', 'minLength' => 1],
                'already_implemented' => ['type' => 'boolean'],
                'already_implemented_reason' => [
                    'anyOf' => [
                        ['type' => 'string', 'minLength' => 1],
                        ['type' => 'null'],
                    ],
                ],
                'tasks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'title',
                            'objective',
                            'implementation_spec',
                            'acceptance_criteria',
                            'verification_commands',
                            'browser_test_steps',
                            'depends_on_position',
                        ],
                        'properties' => [
                            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                            'objective' => ['type' => 'string', 'minLength' => 1],
                            'implementation_spec' => ['type' => 'string', 'minLength' => 1],
                            'acceptance_criteria' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => ['type' => 'string', 'minLength' => 1],
                            ],
                            'verification_commands' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => ['type' => 'string', 'minLength' => 1],
                            ],
                            'browser_test_steps' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => ['type' => 'string', 'minLength' => 1],
                            ],
                            'depends_on_position' => [
                                'anyOf' => [
                                    ['type' => 'integer', 'minimum' => 1],
                                    ['type' => 'null'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Decode, normalize, and strictly validate the PM payload before it can reach persistence.
     *
     * @return array{
     *     summary: string,
     *     already_implemented: bool,
     *     already_implemented_reason: string|null,
     *     tasks: list<array{
     *         title: string,
     *         objective: string,
     *         implementation_spec: string,
     *         acceptance_criteria: list<string>,
     *         verification_commands: list<string>,
     *         browser_test_steps: list<string>,
     *         depends_on_position: int|null
     *     }>
     * }
     */
    private function validateOutput(string $output, string $repositoryPath): array
    {
        try {
            $plan = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('The Project Manager returned malformed JSON.', previous: $exception);
        }

        if (! is_array($plan) || array_is_list($plan)) {
            throw new UnexpectedValueException('The Project Manager response must be one structured JSON object.');
        }

        $this->assertExactKeys(
            $plan,
            ['summary', 'already_implemented', 'already_implemented_reason', 'tasks'],
            'Project Manager response',
        );

        $validator = Validator::make($plan, [
            'summary' => ['required', 'string'],
            'already_implemented' => ['required', 'boolean'],
            'already_implemented_reason' => ['nullable', 'string'],
            'tasks' => ['required', 'array'],
            'tasks.*' => ['required', 'array'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.objective' => ['required', 'string'],
            'tasks.*.implementation_spec' => ['required', 'string'],
            'tasks.*.acceptance_criteria' => ['required', 'array', 'min:1'],
            'tasks.*.acceptance_criteria.*' => ['required', 'string'],
            'tasks.*.verification_commands' => ['required', 'array', 'min:1'],
            'tasks.*.verification_commands.*' => ['required', 'string'],
            'tasks.*.browser_test_steps' => ['required', 'array', 'min:1'],
            'tasks.*.browser_test_steps.*' => ['required', 'string'],
            'tasks.*.depends_on_position' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            throw new UnexpectedValueException('The Project Manager response does not satisfy the required planning contract.');
        }

        /** @var array{summary: string, already_implemented: bool, already_implemented_reason: string|null, tasks: array<int, array<string, mixed>>} $plan */
        if (! array_is_list($plan['tasks'])) {
            throw new UnexpectedValueException('Project Manager Tasks must be returned as an ordered JSON array.');
        }

        $summary = trim($plan['summary']);
        $alreadyImplemented = $plan['already_implemented'];
        $alreadyImplementedReason = is_string($plan['already_implemented_reason'])
            ? trim($plan['already_implemented_reason'])
            : null;

        if ($summary === '') {
            throw new UnexpectedValueException('The Project Manager summary cannot be empty.');
        }

        if ($alreadyImplemented) {
            if (
                $alreadyImplementedReason === null
                || $alreadyImplementedReason === ''
                || $plan['tasks'] !== []
            ) {
                throw new UnexpectedValueException(
                    'An already-implemented result requires evidence and cannot contain Tasks.',
                );
            }

            if (! $this->containsExistingRepositoryEvidence(
                $alreadyImplementedReason,
                $repositoryPath,
            )) {
                throw new UnexpectedValueException(
                    'An already-implemented result must cite concrete evidence from an existing repository path.',
                );
            }

            return [
                'summary' => $summary,
                'already_implemented' => true,
                'already_implemented_reason' => $alreadyImplementedReason,
                'tasks' => [],
            ];
        }

        if ($alreadyImplementedReason !== null || $plan['tasks'] === []) {
            throw new UnexpectedValueException(
                'A remaining-work result requires one or more Tasks and no already-implemented reason.',
            );
        }

        $normalizedTasks = [];

        foreach ($plan['tasks'] as $index => $task) {
            if (! is_array($task) || array_is_list($task)) {
                throw new UnexpectedValueException(
                    'Each Project Manager Task must be one structured JSON object.',
                );
            }

            $this->assertExactKeys($task, [
                'title',
                'objective',
                'implementation_spec',
                'acceptance_criteria',
                'verification_commands',
                'browser_test_steps',
                'depends_on_position',
            ], sprintf('Task %d', $index + 1));

            /** @var array{title: string, objective: string, implementation_spec: string, acceptance_criteria: array<int, string>, verification_commands: array<int, string>, browser_test_steps: array<int, string>, depends_on_position: int|null} $task */
            $position = $index + 1;
            $dependsOnPosition = $task['depends_on_position'];

            if ($dependsOnPosition !== null && $dependsOnPosition >= $position) {
                throw new UnexpectedValueException(
                    sprintf(
                        'Task %d may depend only on an earlier Task position.',
                        $position,
                    ),
                );
            }

            $browserSteps = $this->normalizeStringList(
                $task['browser_test_steps'],
            );

            if (! $this->isBrowserTestable($browserSteps)) {
                throw new UnexpectedValueException(
                    sprintf(
                        'Task %d does not contain a concrete independently browser-testable outcome.',
                        $position,
                    ),
                );
            }

            $normalizedTasks[] = [
                'title' => trim($task['title']),
                'objective' => trim($task['objective']),
                'implementation_spec' => trim($task['implementation_spec']),
                'acceptance_criteria' => $this->normalizeStringList(
                    $task['acceptance_criteria'],
                ),
                'verification_commands' => $this->normalizeStringList(
                    $task['verification_commands'],
                ),
                'browser_test_steps' => $browserSteps,
                'depends_on_position' => $dependsOnPosition,
            ];
        }

        return [
            'summary' => $summary,
            'already_implemented' => false,
            'already_implemented_reason' => null,
            'tasks' => $normalizedTasks,
        ];
    }

    /**
     * Reject missing or extra structured-output keys so persistence receives only the published contract.
     *
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expectedKeys
     */
    private function assertExactKeys(
        array $value,
        array $expectedKeys,
        string $label,
    ): void {
        $actualKeys = array_keys($value);
        sort($actualKeys);
        sort($expectedKeys);

        if ($actualKeys !== $expectedKeys) {
            throw new UnexpectedValueException(
                "{$label} contains missing or unexpected fields.",
            );
        }
    }

    /**
     * Normalize required string arrays and reject whitespace-only values defensively.
     *
     * @param  array<int, string>  $values
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        if (! array_is_list($values)) {
            throw new UnexpectedValueException(
                'Planning detail collections must be ordered JSON arrays.',
            );
        }

        $normalized = array_map(
            static fn (string $value): string => trim($value),
            $values,
        );

        if (in_array('', $normalized, true)) {
            throw new UnexpectedValueException(
                'Planning detail collections cannot contain empty values.',
            );
        }

        return $normalized;
    }

    /**
     * Require browser steps to include both a browser action and an observable result.
     *
     * @param  list<string>  $browserSteps
     */
    private function isBrowserTestable(array $browserSteps): bool
    {
        $steps = Str::lower(implode(' ', $browserSteps));
        $hasBrowserAction = preg_match(
            '/\b(open|visit|navigate|go to|click|submit|enter|type|select|refresh|reload|load|press|choose|browser)\b/u',
            $steps,
        ) === 1;
        $hasVisibleResult = preg_match(
            '/\b(see|confirm|verify|visible|display|displays|displayed|appear|appears|show|shows|render|renders|rendered|observe|observed|notice|contains)\b/u',
            $steps,
        ) === 1;

        return $hasBrowserAction && $hasVisibleResult;
    }

    /**
     * Confirm an already-implemented explanation cites at least one repository-relative file that actually exists.
     */
    private function containsExistingRepositoryEvidence(
        string $reason,
        string $repositoryPath,
    ): bool {
        /** @var array{0: list<string>, 1: list<string>} $matches */
        $matches = [[], []];

        preg_match_all(
            '/(?<![A-Za-z0-9_.-])((?:[A-Za-z0-9_.@-]+\/)+[A-Za-z0-9_.@-]+(?:\.[A-Za-z0-9]+)?|[A-Za-z0-9_.@-]+\.[A-Za-z0-9]+)(?::\d+(?:-\d+)?)?/u',
            $reason,
            $matches,
        );

        $root = realpath($repositoryPath);

        if ($root === false) {
            return false;
        }

        foreach (array_unique($matches[1]) as $relativePath) {
            $candidate = realpath(
                $root.DIRECTORY_SEPARATOR.$relativePath,
            );

            if (
                $candidate !== false
                && is_file($candidate)
                && Str::startsWith(
                    $candidate,
                    $root.DIRECTORY_SEPARATOR,
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
