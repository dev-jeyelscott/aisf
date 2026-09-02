<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\Project;
use App\Models\ProjectVerificationRun;
use App\Models\Task;
use App\Models\WorkRequest;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use Throwable;
use UnexpectedValueException;

class ProjectVerificationService
{
    private const DOCKER_STAGE_ROOT = '/tmp/aisf-verification';

    /**
     * Inject candidate fingerprinting used to bind QA verification to immutable durable evidence.
     */
    public function __construct(
        private readonly TaskCandidateFingerprint $candidateFingerprint,
    ) {}

    /**
     * Execute one idempotent operator-approved verification profile for an active AgentRun.
     */
    public function run(
        AgentRun $viewer,
        string $executionToken,
        string $profileName,
        string $idempotencyKey,
    ): ProjectVerificationRun {
        $profileName = trim($profileName);
        $idempotencyKey = trim($idempotencyKey);

        $this->assertProfileName($profileName);
        $this->assertIdempotencyKey($idempotencyKey);

        $context = $this->authorizedContext(
            $viewer,
            $executionToken,
        );

        $profile = $this->profileDefinition(
            $context['project'],
            $profileName,
        );

        [$attempt, $shouldExecute] = $this->reserveAttempt(
            $viewer,
            $executionToken,
            $context,
            $profileName,
            $profile,
            $idempotencyKey,
        );

        if (! $shouldExecute) {
            return $attempt;
        }

        Log::info('Project verification started.', [
            'verification_run_id' => $attempt->id,
            'agent_run_id' => $viewer->id,
            'project_id' => $context['project']->id,
            'task_id' => $context['task']?->id,
            'profile' => $profileName,
            'driver' => $profile['driver'],
        ]);

        $startedAt = microtime(true);
        $target = $this->validateExecutionTarget($context);

        if ($target['status'] !== null) {
            return $this->finishAttempt(
                $attempt,
                $target['status'],
                null,
                $this->durationMilliseconds($startedAt),
                '',
                '',
                $target['diagnostic'],
            );
        }

        try {
            $result = match ($profile['driver']) {
                'native' => $this->executeNative(
                    $target['path'],
                    $profile,
                ),
                'docker_compose_exec' => $this->executeDockerCompose(
                    $attempt,
                    $context['project'],
                    $target['path'],
                    $profile,
                ),
                default => throw new UnexpectedValueException(
                    'The Project verification profile uses an unsupported driver.',
                ),
            };
        } catch (ProcessTimedOutException $exception) {
            return $this->finishAttempt(
                $attempt,
                ProjectVerificationRun::STATUS_TIMED_OUT,
                $exception->result->exitCode(),
                $this->durationMilliseconds($startedAt),
                $exception->result->output(),
                $exception->result->errorOutput(),
                'Verification exceeded its configured timeout.',
            );
        } catch (Throwable $exception) {
            Log::warning('Project verification process could not start.', [
                'verification_run_id' => $attempt->id,
                'agent_run_id' => $viewer->id,
                'project_id' => $context['project']->id,
                'task_id' => $context['task']?->id,
                'profile' => $profileName,
                'driver' => $profile['driver'],
                'exception_class' => $exception::class,
            ]);

            return $this->finishAttempt(
                $attempt,
                ProjectVerificationRun::STATUS_ENVIRONMENT_UNAVAILABLE,
                null,
                $this->durationMilliseconds($startedAt),
                '',
                '',
                'The AISF host could not start the configured verification process.',
            );
        }

        if (
            $context['task'] instanceof Task
            && $context['candidate_tree_sha'] !== null
        ) {
            try {
                $currentTreeSha = $this->candidateFingerprint
                    ->currentTreeSha($context['task']);

                if (
                    ! hash_equals(
                        $context['candidate_tree_sha'],
                        $currentTreeSha,
                    )
                ) {
                    $result['status'] = ProjectVerificationRun::STATUS_STALE_CANDIDATE;
                    $result['diagnostic'] = 'The Task candidate changed while verification was running.';
                }
            } catch (Throwable) {
                $result['status'] = ProjectVerificationRun::STATUS_STALE_CANDIDATE;
                $result['diagnostic'] = 'AISF could not prove that the Task candidate remained unchanged during verification.';
            }
        }

        return $this->finishAttempt(
            $attempt,
            $result['status'],
            $result['exit_code'],
            $this->durationMilliseconds($startedAt),
            $result['stdout'],
            $result['stderr'],
            $result['diagnostic'],
        );
    }

    /**
     * Resolve and authorize the Project, Task, execution target, and candidate owned by this AgentRun.
     *
     * @return array{
     *     project: Project,
     *     task: Task|null,
     *     target_path: string,
     *     target_type: string,
     *     candidate_tree_sha: string|null
     * }
     */
    private function authorizedContext(
        AgentRun $viewer,
        string $executionToken,
    ): array {
        $viewer->loadMissing([
            'agentSession.projectAgent',
            'agentSession.task.workRequest.project',
            'agentSession.workRequest.project',
        ]);

        $session = $viewer->agentSession;
        $projectAgent = $session?->projectAgent;
        $task = $session?->task;
        $workRequest = $session?->workRequest;

        $project = null;

        if ($task instanceof Task) {
            $project = $task->workRequest->project;
        } elseif ($workRequest instanceof WorkRequest) {
            $project = $workRequest->project;
        }

        if (
            ! $viewer->exists
            || $viewer->status !== 'running'
            || $executionToken === ''
            || ! is_string($viewer->execution_token)
            || $viewer->execution_token === ''
            || ! hash_equals(
                (string) $viewer->execution_token,
                $executionToken,
            )
            || $projectAgent === null
            || ! $projectAgent->enabled
            || ! $project instanceof Project
            || ! $project->enabled
            || (int) $projectAgent->project_id !== (int) $project->id
        ) {
            throw new UnexpectedValueException(
                'The Agent run is not authorized to execute Project verification.',
            );
        }

        if ($task instanceof Task) {
            $acceptedHandoffId = $viewer->execution_metadata['accepted_handoff_id']
                ?? null;

            if (
                $acceptedHandoffId !== null
                && (int) ($task->last_handoff['id'] ?? 0)
                    !== (int) $acceptedHandoffId
            ) {
                throw new UnexpectedValueException(
                    'The Agent run is stale for the current Task handoff.',
                );
            }
        }

        $executionMode = (string) (
            $viewer->execution_metadata['execution_mode']
            ?? ''
        );

        $usesDurableCandidate = $task instanceof Task
            && (
                $viewer->role === 'qa'
                || $executionMode === 'approved'
                || (
                    (int) $task->candidate_created_by_run_id
                        === (int) $viewer->id
                    && filled($task->candidate_tree_sha)
                )
            );

        return [
            'project' => $project,
            'task' => $task,
            'target_path' => $usesDurableCandidate
                ? (string) $task->worktree_path
                : (string) $project->path,
            'target_type' => $usesDurableCandidate
                ? 'task_candidate'
                : 'project_checkout',
            'candidate_tree_sha' => $usesDurableCandidate
                ? (
                    filled($task->candidate_tree_sha)
                        ? (string) $task->candidate_tree_sha
                        : null
                )
                : null,
        ];
    }

    /**
     * Resolve one exact operator-approved Project profile and defensively validate its persisted shape.
     *
     * @return array{
     *     driver: 'native'|'docker_compose_exec',
     *     command: list<string>,
     *     timeout: int,
     *     compose_file?: string,
     *     compose_project?: string,
     *     service?: string,
     *     user?: string
     * }
     */
    private function profileDefinition(
        Project $project,
        string $profileName,
    ): array {
        $profiles = $project->getAttribute('verification_profiles');

        if ($profiles === null) {
            $profiles = [];
        }

        if (
            ! is_array($profiles)
            || ! array_key_exists($profileName, $profiles)
            || ! is_array($profiles[$profileName])
        ) {
            throw new UnexpectedValueException(
                'The requested Project verification profile is not configured.',
            );
        }

        $rawProfile = $profiles[$profileName];
        $driver = $rawProfile['driver'] ?? null;
        $command = $rawProfile['command'] ?? null;
        $timeout = $rawProfile['timeout'] ?? null;

        if (
            ! is_string($driver)
            || ! in_array(
                $driver,
                ['native', 'docker_compose_exec'],
                true,
            )
            || ! is_array($command)
            || $command === []
            || count($command) > 32
            || ! is_int($timeout)
            || $timeout < 1
            || $timeout > (int) config(
                'aisf.verification_max_timeout',
                1800,
            )
        ) {
            throw new UnexpectedValueException(
                'The requested Project verification profile is malformed.',
            );
        }

        $normalizedCommand = [];

        foreach ($command as $argument) {
            if (
                ! is_string($argument)
                || $argument === ''
                || strlen($argument) > 500
                || str_contains($argument, "\0")
            ) {
                throw new UnexpectedValueException(
                    'The requested Project verification command is malformed.',
                );
            }

            $normalizedCommand[] = $argument;
        }

        $executable = strtolower(
            basename(
                str_replace('\\', '/', $normalizedCommand[0]),
            ),
        );

        if (in_array(
            $executable,
            [
                'sh',
                'bash',
                'zsh',
                'fish',
                'cmd',
                'cmd.exe',
                'powershell',
                'powershell.exe',
                'pwsh',
            ],
            true,
        )) {
            throw new UnexpectedValueException(
                'Project verification profiles may not invoke a command shell.',
            );
        }

        if ($driver === 'native') {
            return [
                'driver' => 'native',
                'command' => $normalizedCommand,
                'timeout' => $timeout,
            ];
        }

        $composeFile = $rawProfile['compose_file'] ?? null;
        $composeProject = $rawProfile['compose_project'] ?? null;
        $service = $rawProfile['service'] ?? null;
        $user = $rawProfile['user'] ?? null;

        if (
            ! is_string($composeFile)
            || trim($composeFile) === ''
            || ! is_string($composeProject)
            || trim($composeProject) === ''
            || ! is_string($service)
            || trim($service) === ''
            || ! is_string($user)
            || trim($user) === ''
        ) {
            throw new UnexpectedValueException(
                'The Docker verification profile is missing required trusted infrastructure configuration.',
            );
        }

        return [
            'driver' => 'docker_compose_exec',
            'command' => $normalizedCommand,
            'timeout' => $timeout,
            'compose_file' => trim($composeFile),
            'compose_project' => trim($composeProject),
            'service' => trim($service),
            'user' => trim($user),
        ];
    }

    /**
     * Persist one idempotent verification attempt before any external process executes.
     *
     * @param array{
     *     project: Project,
     *     task: Task|null,
     *     target_path: string,
     *     target_type: string,
     *     candidate_tree_sha: string|null
     * } $context
     * @param array{
     *     driver: 'native'|'docker_compose_exec',
     *     command: list<string>,
     *     timeout: int,
     *     compose_file?: string,
     *     compose_project?: string,
     *     service?: string,
     *     user?: string
     * } $profile
     * @return array{0: ProjectVerificationRun, 1: bool}
     */
    private function reserveAttempt(
        AgentRun $viewer,
        string $executionToken,
        array $context,
        string $profileName,
        array $profile,
        string $idempotencyKey,
    ): array {
        return DB::transaction(function () use (
            $viewer,
            $executionToken,
            $context,
            $profileName,
            $profile,
            $idempotencyKey,
        ): array {
            $lockedRun = AgentRun::query()
                ->whereKey($viewer->getKey())
                ->lockForUpdate()
                ->sole();

            if (
                $lockedRun->status !== 'running'
                || ! is_string($lockedRun->execution_token)
                || ! hash_equals(
                    (string) $lockedRun->execution_token,
                    $executionToken,
                )
            ) {
                throw new UnexpectedValueException(
                    'The Agent run became stale before Project verification started.',
                );
            }

            $existing = ProjectVerificationRun::query()
                ->where('agent_run_id', $lockedRun->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof ProjectVerificationRun) {
                $existingCommand = $existing->getAttribute('command');

                if (! is_array($existingCommand)) {
                    throw new UnexpectedValueException(
                        'The persisted verification command evidence is malformed.',
                    );
                }

                if (
                    $existing->profile !== $profileName
                    || $existing->driver !== $profile['driver']
                    || $existing->target_type !== $context['target_type']
                    || $existing->candidate_tree_sha
                        !== $context['candidate_tree_sha']
                    || array_values($existingCommand)
                        !== $profile['command']
                ) {
                    throw new UnexpectedValueException(
                        'The verification idempotency key already belongs to a different logical request.',
                    );
                }

                return [$existing, false];
            }

            $attempt = $lockedRun->verificationRuns()->create([
                'project_id' => $context['project']->id,
                'task_id' => $context['task']?->id,
                'idempotency_key' => $idempotencyKey,
                'profile' => $profileName,
                'driver' => $profile['driver'],
                'target_type' => $context['target_type'],
                'command' => $profile['command'],
                'candidate_tree_sha' => $context['candidate_tree_sha'],
                'status' => ProjectVerificationRun::STATUS_RUNNING,
                'started_at' => now(),
            ]);

            return [$attempt, true];
        }, attempts: 3);
    }

    /**
     * Validate the host path and immutable Task candidate before executing external code.
     *
     * @param array{
     *     project: Project,
     *     task: Task|null,
     *     target_path: string,
     *     target_type: string,
     *     candidate_tree_sha: string|null
     * } $context
     * @return array{
     *     path: string,
     *     status: string|null,
     *     diagnostic: string|null
     * }
     */
    private function validateExecutionTarget(array $context): array
    {
        $path = realpath($context['target_path']);

        if (
            $path === false
            || ! is_dir($path)
            || ! is_readable($path)
        ) {
            return [
                'path' => '',
                'status' => ProjectVerificationRun::STATUS_ENVIRONMENT_UNAVAILABLE,
                'diagnostic' => 'The configured verification checkout is unavailable.',
            ];
        }

        if ($context['target_type'] !== 'task_candidate') {
            return [
                'path' => $path,
                'status' => null,
                'diagnostic' => null,
            ];
        }

        if (
            ! $context['task'] instanceof Task
            || $context['candidate_tree_sha'] === null
        ) {
            return [
                'path' => $path,
                'status' => ProjectVerificationRun::STATUS_ENVIRONMENT_UNAVAILABLE,
                'diagnostic' => 'The Task has no durable candidate available for verification.',
            ];
        }

        try {
            $currentTreeSha = $this->candidateFingerprint
                ->currentTreeSha($context['task']);
        } catch (Throwable) {
            return [
                'path' => $path,
                'status' => ProjectVerificationRun::STATUS_ENVIRONMENT_UNAVAILABLE,
                'diagnostic' => 'AISF could not fingerprint the Task candidate before verification.',
            ];
        }

        if (
            ! hash_equals(
                $context['candidate_tree_sha'],
                $currentTreeSha,
            )
        ) {
            return [
                'path' => $path,
                'status' => ProjectVerificationRun::STATUS_STALE_CANDIDATE,
                'diagnostic' => 'The Task worktree no longer matches the durable candidate_tree_sha.',
            ];
        }

        return [
            'path' => $path,
            'status' => null,
            'diagnostic' => null,
        ];
    }

    /**
     * Execute trusted native verification only when the operator explicitly enables host code execution.
     *
     * @param  array<string, mixed>  $profile
     * @return array{
     *     status: string,
     *     exit_code: int|null,
     *     stdout: string,
     *     stderr: string,
     *     diagnostic: string|null
     * }
     */
    private function executeNative(
        string $targetPath,
        array $profile,
    ): array {
        if (! config('aisf.allow_trusted_native_verification', false)) {
            return [
                'status' => ProjectVerificationRun::STATUS_ENVIRONMENT_UNAVAILABLE,
                'exit_code' => null,
                'stdout' => '',
                'stderr' => '',
                'diagnostic' => 'Native Project verification is disabled on this AISF host.',
            ];
        }

        $result = Process::path($targetPath)
            ->env($this->sanitizedProcessEnvironment())
            ->timeout((int) $profile['timeout'])
            ->idleTimeout((int) $profile['timeout'])
            ->run(array_values($profile['command']));

        if (in_array($result->exitCode(), [126, 127], true)) {
            return [
                'status' => ProjectVerificationRun::STATUS_ENVIRONMENT_UNAVAILABLE,
                'exit_code' => $result->exitCode(),
                'stdout' => $result->output(),
                'stderr' => $result->errorOutput(),
                'diagnostic' => 'The configured native verification executable is unavailable.',
            ];
        }

        return [
            'status' => $result->successful()
                ? ProjectVerificationRun::STATUS_PASSED
                : ProjectVerificationRun::STATUS_FAILED,
            'exit_code' => $result->exitCode(),
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
            'diagnostic' => null,
        ];
    }

    /**
     * Execute Docker verification in a dedicated pre-existing verifier container without exposing Docker to the Agent.
     *
     * @param  array<string, mixed>  $profile
     * @return array{
     *     status: string,
     *     exit_code: int|null,
     *     stdout: string,
     *     stderr: string,
     *     diagnostic: string|null
     * }
     */
    private function executeDockerCompose(
        ProjectVerificationRun $attempt,
        Project $project,
        string $targetPath,
        array $profile,
    ): array {
        $definitionRoot = $this->verificationDefinitionRoot();
        $composeFile = $this->trustedComposeFile(
            $definitionRoot,
            $project,
            $targetPath,
            (string) $profile['compose_file'],
        );

        $composeProject = trim((string) $profile['compose_project']);
        $service = trim((string) $profile['service']);
        $user = trim((string) $profile['user']);
        $timeout = (int) $profile['timeout'];

        $prefix = [
            'docker',
            'compose',
            '--ansi',
            'never',
            '-f',
            $composeFile,
            '-p',
            $composeProject,
        ];

        $ps = $this->dockerProcess(
            $definitionRoot,
            [
                ...$prefix,
                'ps',
                '-q',
                $service,
            ],
            30,
        );

        if (
            $ps->failed()
            || $this->looksLikeDockerEnvironmentFailure(
                $ps->output(),
                $ps->errorOutput(),
            )
        ) {
            return $this->environmentFailure(
                $ps,
                'The configured Docker verifier service is unavailable.',
            );
        }

        $containerIds = array_values(
            array_filter(
                preg_split('/\R/u', trim($ps->output())) ?: [],
                fn (string $value): bool => trim($value) !== '',
            ),
        );

        if (count($containerIds) !== 1) {
            return [
                'status' => ProjectVerificationRun::STATUS_ENVIRONMENT_UNAVAILABLE,
                'exit_code' => $ps->exitCode(),
                'stdout' => $ps->output(),
                'stderr' => $ps->errorOutput(),
                'diagnostic' => 'The verification profile must resolve exactly one running verifier container.',
            ];
        }

        $containerId = trim($containerIds[0]);

        $containerSecurityFailure = $this->verifierContainerSecurityFailure(
            $definitionRoot,
            $containerId,
        );

        if ($containerSecurityFailure !== null) {
            return [
                'status' => ProjectVerificationRun::STATUS_ENVIRONMENT_UNAVAILABLE,
                'exit_code' => null,
                'stdout' => '',
                'stderr' => '',
                'diagnostic' => $containerSecurityFailure,
            ];
        }

        $stagePath = self::DOCKER_STAGE_ROOT.'/run-'.$attempt->id;
        $stageCreated = false;

        try {
            $prepare = $this->dockerProcess(
                $definitionRoot,
                [
                    ...$prefix,
                    'exec',
                    '-T',
                    '--user',
                    '0',
                    $service,
                    'mkdir',
                    '-p',
                    $stagePath,
                ],
                30,
            );

            if ($prepare->failed()) {
                return $this->environmentFailure(
                    $prepare,
                    'AISF could not prepare the verifier workspace.',
                );
            }

            $stageCreated = true;

            $copy = $this->dockerProcess(
                $definitionRoot,
                [
                    'docker',
                    'cp',
                    rtrim($targetPath, DIRECTORY_SEPARATOR).'/.',
                    $containerId.':'.$stagePath,
                ],
                min($timeout, 300),
            );

            if ($copy->failed()) {
                return $this->environmentFailure(
                    $copy,
                    'AISF could not stage the verification checkout.',
                );
            }

            $ownership = $this->dockerProcess(
                $definitionRoot,
                [
                    ...$prefix,
                    'exec',
                    '-T',
                    '--user',
                    '0',
                    $service,
                    'chown',
                    '-R',
                    '--no-dereference',
                    $user,
                    $stagePath,
                ],
                min($timeout, 120),
            );

            if ($ownership->failed()) {
                return $this->environmentFailure(
                    $ownership,
                    'AISF could not prepare verifier workspace ownership.',
                );
            }

            $verification = $this->dockerProcess(
                $definitionRoot,
                [
                    ...$prefix,
                    'exec',
                    '-T',
                    '--user',
                    $user,
                    '--workdir',
                    $stagePath,
                    $service,
                    ...array_values($profile['command']),
                ],
                $timeout,
            );

            if (
                $this->looksLikeDockerEnvironmentFailure(
                    $verification->output(),
                    $verification->errorOutput(),
                )
            ) {
                return $this->environmentFailure(
                    $verification,
                    'Docker verification infrastructure became unavailable.',
                );
            }

            return [
                'status' => $verification->successful()
                    ? ProjectVerificationRun::STATUS_PASSED
                    : ProjectVerificationRun::STATUS_FAILED,
                'exit_code' => $verification->exitCode(),
                'stdout' => $verification->output(),
                'stderr' => $verification->errorOutput(),
                'diagnostic' => null,
            ];
        } finally {
            if ($stageCreated) {
                try {
                    $cleanup = $this->dockerProcess(
                        $definitionRoot,
                        [
                            ...$prefix,
                            'exec',
                            '-T',
                            '--user',
                            '0',
                            $service,
                            'rm',
                            '-rf',
                            '--',
                            $stagePath,
                        ],
                        30,
                    );

                    if ($cleanup->failed()) {
                        Log::warning(
                            'Project verification workspace cleanup failed.',
                            [
                                'verification_run_id' => $attempt->id,
                                'agent_run_id' => $attempt->agent_run_id,
                                'project_id' => $attempt->project_id,
                                'task_id' => $attempt->task_id,
                            ],
                        );
                    }
                } catch (Throwable) {
                    Log::warning(
                        'Project verification workspace cleanup could not run.',
                        [
                            'verification_run_id' => $attempt->id,
                            'agent_run_id' => $attempt->agent_run_id,
                            'project_id' => $attempt->project_id,
                            'task_id' => $attempt->task_id,
                        ],
                    );
                }
            }
        }
    }

    /**
     * Run one Docker CLI command with a minimal inherited host environment.
     *
     * @param  list<string>  $command
     */
    private function dockerProcess(
        string $path,
        array $command,
        int $timeout,
    ): ProcessResult {
        return Process::path($path)
            ->env($this->sanitizedProcessEnvironment())
            ->timeout($timeout)
            ->idleTimeout($timeout)
            ->run($command);
    }

    /**
     * Reject verifier containers that could provide untrusted Project code direct host authority.
     */
    private function verifierContainerSecurityFailure(
        string $path,
        string $containerId,
    ): ?string {
        $hostConfigResult = $this->dockerProcess(
            $path,
            [
                'docker',
                'inspect',
                '--format',
                '{{json .HostConfig}}',
                $containerId,
            ],
            30,
        );

        $mountsResult = $this->dockerProcess(
            $path,
            [
                'docker',
                'inspect',
                '--format',
                '{{json .Mounts}}',
                $containerId,
            ],
            30,
        );

        if ($hostConfigResult->failed() || $mountsResult->failed()) {
            return 'AISF could not inspect the configured verifier container security boundary.';
        }

        try {
            $hostConfig = json_decode(
                trim($hostConfigResult->output()),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            $mounts = json_decode(
                trim($mountsResult->output()),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return 'AISF could not parse the configured verifier container security metadata.';
        }

        if (! is_array($hostConfig) || ! is_array($mounts)) {
            return 'The configured verifier container returned invalid security metadata.';
        }

        if (
            ($hostConfig['Privileged'] ?? false) === true
            || filled($hostConfig['Binds'] ?? null)
            || filled($hostConfig['Devices'] ?? null)
            || filled($hostConfig['CapAdd'] ?? null)
            || ($hostConfig['NetworkMode'] ?? null) === 'host'
            || ($hostConfig['PidMode'] ?? null) === 'host'
            || ($hostConfig['IpcMode'] ?? null) === 'host'
        ) {
            return 'The configured verifier container has prohibited host privileges.';
        }

        foreach ($mounts as $mount) {
            if (! is_array($mount)) {
                continue;
            }

            if (($mount['Type'] ?? null) === 'bind') {
                return 'The configured verifier container may not use host bind mounts.';
            }

            $destination = $mount['Destination'] ?? null;

            if (
                is_string($destination)
                && str_contains(
                    strtolower($destination),
                    'docker.sock',
                )
            ) {
                return 'The configured verifier container may not expose the Docker socket.';
            }
        }

        return null;
    }

    /**
     * Resolve the trusted AISF-owned verification definition root.
     */
    private function verificationDefinitionRoot(): string
    {
        $configured = config('aisf.verification_definition_path');

        if (! is_string($configured) || trim($configured) === '') {
            throw new UnexpectedValueException(
                'The Project verification definition path is not configured.',
            );
        }

        $expanded = $this->expandConfiguredHome(trim($configured));
        $resolved = realpath($expanded);

        if (
            $resolved === false
            || ! is_dir($resolved)
            || ! is_readable($resolved)
            || $resolved === DIRECTORY_SEPARATOR
        ) {
            throw new UnexpectedValueException(
                'The Project verification definition path must be a readable non-root directory.',
            );
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /**
     * Resolve one trusted Compose definition while preventing Project-controlled path traversal.
     */
    private function trustedComposeFile(
        string $definitionRoot,
        Project $project,
        string $targetPath,
        string $relativePath,
    ): string {
        $normalized = str_replace(
            '\\',
            '/',
            trim($relativePath),
        );

        if (
            $normalized === ''
            || str_contains($normalized, "\0")
            || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
            || in_array('..', explode('/', $normalized), true)
        ) {
            throw new UnexpectedValueException(
                'The Docker verification definition must use a trusted relative path.',
            );
        }

        $resolved = realpath(
            $definitionRoot.DIRECTORY_SEPARATOR.$normalized,
        );

        if (
            $resolved === false
            || ! is_file($resolved)
            || ! is_readable($resolved)
            || ! $this->isWithinRoot(
                $definitionRoot,
                $resolved,
            )
        ) {
            throw new UnexpectedValueException(
                'The configured Docker verification definition is unavailable.',
            );
        }

        $projectRoot = realpath((string) $project->path);
        $targetRoot = realpath($targetPath);

        if (
            (
                is_string($projectRoot)
                && $this->isWithinRoot($projectRoot, $resolved)
            )
            || (
                is_string($targetRoot)
                && $this->isWithinRoot($targetRoot, $resolved)
            )
        ) {
            throw new UnexpectedValueException(
                'Docker verification definitions must not be stored inside an Agent-managed repository.',
            );
        }

        return $resolved;
    }

    /**
     * Expand only the current user's "~" home shorthand for trusted operator configuration.
     */
    private function expandConfiguredHome(string $path): string
    {
        if ($path !== '~' && ! str_starts_with($path, '~/')) {
            if (str_starts_with($path, '~')) {
                throw new UnexpectedValueException(
                    'Verification paths support only "~" or "~/" home-directory shorthand.',
                );
            }

            return $path;
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME');

        if (! is_string($home) || trim($home) === '') {
            throw new UnexpectedValueException(
                'The AISF host home directory is unavailable.',
            );
        }

        $home = rtrim(trim($home), DIRECTORY_SEPARATOR);

        return $path === '~'
            ? $home
            : $home
                .DIRECTORY_SEPARATOR
                .ltrim(
                    substr($path, 2),
                    DIRECTORY_SEPARATOR,
                );
    }

    /**
     * Determine whether one canonical path remains inside the supplied canonical root.
     */
    private function isWithinRoot(
        string $root,
        string $path,
    ): bool {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        return $path === $root
            || str_starts_with(
                $path,
                $root.DIRECTORY_SEPARATOR,
            );
    }

    /**
     * Remove the AISF worker environment before invoking Project-controlled verification.
     *
     * @return array<string, string|false>
     */
    private function sanitizedProcessEnvironment(): array
    {
        $environment = [];
        $currentEnvironment = getenv();

        foreach (array_keys($currentEnvironment) as $name) {
            $environment[$name] = false;
        }

        foreach ([
            'PATH',
            'HOME',
            'TMPDIR',
            'XDG_RUNTIME_DIR',
            'DOCKER_HOST',
            'DOCKER_CONTEXT',
            'DOCKER_TLS_VERIFY',
            'DOCKER_CERT_PATH',
        ] as $allowedName) {
            $value = getenv($allowedName);

            if ($value !== false) {
                $environment[$allowedName] = $value;
            }
        }

        return $environment;
    }

    /**
     * Detect well-known Docker and external-infrastructure failures separately from test failures.
     */
    private function looksLikeDockerEnvironmentFailure(
        string $stdout,
        string $stderr,
    ): bool {
        $output = Str::lower(
            trim($stdout."\n".$stderr),
        );

        foreach ([
            'cannot connect to the docker daemon',
            'is the docker daemon running',
            'permission denied while trying to connect',
            'no such service',
            'no container found',
            'is not running',
            'connection refused',
            'executable file not found',
            'docker: command not found',
        ] as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert one Docker setup failure into a structured environment-unavailable result.
     *
     * @return array{
     *     status: string,
     *     exit_code: int|null,
     *     stdout: string,
     *     stderr: string,
     *     diagnostic: string|null
     * }
     */
    private function environmentFailure(
        ProcessResult $result,
        string $diagnostic,
    ): array {
        return [
            'status' => ProjectVerificationRun::STATUS_ENVIRONMENT_UNAVAILABLE,
            'exit_code' => $result->exitCode(),
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
            'diagnostic' => $diagnostic,
        ];
    }

    /**
     * Persist one bounded terminal verification result and emit structured operational logging.
     */
    private function finishAttempt(
        ProjectVerificationRun $attempt,
        string $status,
        ?int $exitCode,
        int $durationMilliseconds,
        string $stdout,
        string $stderr,
        ?string $diagnostic,
    ): ProjectVerificationRun {
        $attempt->update([
            'status' => $status,
            'exit_code' => $exitCode,
            'duration_ms' => $durationMilliseconds,
            'stdout' => $this->boundedOutput($stdout),
            'stderr' => $this->boundedOutput($stderr),
            'diagnostic' => $diagnostic === null
                ? null
                : $this->boundedOutput($diagnostic),
            'finished_at' => now(),
        ]);

        Log::info('Project verification completed.', [
            'verification_run_id' => $attempt->id,
            'agent_run_id' => $attempt->agent_run_id,
            'project_id' => $attempt->project_id,
            'task_id' => $attempt->task_id,
            'profile' => $attempt->profile,
            'driver' => $attempt->driver,
            'status' => $status,
            'duration_ms' => $durationMilliseconds,
            'exit_code' => $exitCode,
        ]);

        return $attempt->refresh();
    }

    /**
     * Keep the beginning and end of large process output while enforcing one deterministic limit.
     */
    private function boundedOutput(string $value): string
    {
        $value = trim($value);
        $limit = max(
            1000,
            (int) config(
                'aisf.verification_output_limit',
                12000,
            ),
        );

        if (Str::length($value) <= $limit) {
            return $value;
        }

        $marker = "\n...[verification output truncated]...\n";
        $available = max(
            2,
            $limit - Str::length($marker),
        );
        $headLength = intdiv($available, 2);
        $tailLength = $available - $headLength;

        return Str::substr(
            $value,
            0,
            $headLength,
        )
            .$marker
            .Str::substr(
                $value,
                -$tailLength,
            );
    }

    /**
     * Return elapsed wall-clock time in milliseconds.
     */
    private function durationMilliseconds(float $startedAt): int
    {
        return max(
            0,
            (int) round(
                (microtime(true) - $startedAt) * 1000,
            ),
        );
    }

    /**
     * Validate an Agent-selectable verification profile identifier.
     */
    private function assertProfileName(string $profileName): void
    {
        if (
            preg_match(
                '/^[a-z0-9][a-z0-9_-]{0,63}$/',
                $profileName,
            ) !== 1
        ) {
            throw new UnexpectedValueException(
                'The Project verification profile name is invalid.',
            );
        }
    }

    /**
     * Validate the mutation idempotency key without accepting shell-like free-form text.
     */
    private function assertIdempotencyKey(
        string $idempotencyKey,
    ): void {
        if (
            preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/',
                $idempotencyKey,
            ) !== 1
        ) {
            throw new UnexpectedValueException(
                'The Project verification idempotency key is invalid.',
            );
        }
    }
}
