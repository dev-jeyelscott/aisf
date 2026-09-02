<?php

namespace App\Services;

use App\Models\ProjectAgent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class AgentHarness
{
    /** @var array<string, bool> */
    private array $resumeSupport = [];

    public function __construct(
        private readonly AgentRuntimeEnvironment $runtimeEnvironment,
    ) {}

    /**
     * Determine whether the installed provider CLI advertises stable resumable execution support.
     */
    public function canResume(ProjectAgent $agent): bool
    {
        return match ($agent->harness) {
            'codex' => $this->resumeSupport['codex'] ??= $this->probeResumeSupport(
                ['codex', 'exec', '--help'],
                ['resume', '--json', '--output-schema'],
            ),
            'claude' => $this->resumeSupport['claude'] ??= $this->probeResumeSupport(
                ['claude', '--help'],
                ['--resume', '--output-format', '--json-schema'],
            ),
            default => false,
        };
    }

    /**
     * Start a new provider execution and return structured process metadata.
     *
     * @param  array<string, mixed>|null  $schema
     */
    public function start(
        ProjectAgent $agent,
        string $repositoryPath,
        string $prompt,
        ?array $schema = null,
        bool $writable = false,
    ): AgentHarnessResult {
        return match ($agent->harness) {
            'codex' => $this->executeCodex($agent, $repositoryPath, $prompt, $schema, writable: $writable),
            'claude' => $this->executeClaude($agent, $repositoryPath, $prompt, $schema, writable: $writable),
            default => throw new UnexpectedValueException('The Agent harness must be Codex or Claude.'),
        };
    }

    /**
     * Resume a known provider session when the installed CLI supports it, otherwise run the supplied minimal fallback context as a fresh non-persistent invocation.
     *
     * @param  array<string, mixed>|null  $schema
     */
    public function resume(
        ProjectAgent $agent,
        string $repositoryPath,
        string $providerSessionId,
        string $prompt,
        ?array $schema = null,
        bool $writable = false,
    ): AgentHarnessResult {
        $providerSessionId = trim($providerSessionId);

        if ($providerSessionId === '') {
            throw new UnexpectedValueException('A provider session identifier is required for resume.');
        }

        if (! $this->canResume($agent)) {
            return $this->start($agent, $repositoryPath, $prompt, $schema, $writable);
        }

        return match ($agent->harness) {
            'codex' => $this->executeCodex(
                $agent,
                $repositoryPath,
                $prompt,
                $schema,
                $providerSessionId,
                $writable,
            ),
            'claude' => $this->executeClaude(
                $agent,
                $repositoryPath,
                $prompt,
                $schema,
                $providerSessionId,
                $writable,
            ),
            default => throw new UnexpectedValueException('The Agent harness must be Codex or Claude.'),
        };
    }

    /**
     * Probe provider help output without assuming a resumable syntax that the installed CLI does not expose.
     *
     * @param  list<string>  $command
     * @param  list<string>  $requiredHelpTokens
     */
    private function probeResumeSupport(array $command, array $requiredHelpTokens): bool
    {
        try {
            $result = Process::env($this->runtimeEnvironment->resolve())
                ->timeout(5)
                ->idleTimeout(5)
                ->run($command);
        } catch (Throwable) {
            return false;
        }

        if ($result->failed()) {
            return false;
        }

        $help = $result->output()."\n".$result->errorOutput();

        foreach ($requiredHelpTokens as $requiredHelpToken) {
            if (! Str::contains($help, $requiredHelpToken)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Execute Codex with host-level permissions for every Agent role, with provider continuity only when the installed CLI proves support.
     *
     * @param  array<string, mixed>|null  $schema
     */
    private function executeCodex(
        ProjectAgent $agent,
        string $repositoryPath,
        string $prompt,
        ?array $schema,
        ?string $providerSessionId = null,
        bool $writable = false,
    ): AgentHarnessResult {
        $schemaPath = null;

        if ($schema !== null) {
            $schemaPath = tempnam(sys_get_temp_dir(), 'aisf-agent-schema-');

            if ($schemaPath === false) {
                throw new RuntimeException('Unable to prepare the Agent response schema.');
            }

            File::put($schemaPath, $this->encodeJsonValue($schema));
        }

        try {
            $supportsResume = $this->canResume($agent);
            $isResuming = $providerSessionId !== null && $supportsResume;
            $command = ['codex', 'exec'];

            if ($isResuming) {
                $command[] = 'resume';
                $command[] = $providerSessionId;
                $command[] = '--json';
            } else {
                $command[] = $supportsResume ? '--json' : '--ephemeral';
                $command[] = '--dangerously-bypass-approvals-and-sandbox';
                $command[] = '--color';
                $command[] = 'never';
            }

            $command[] = '-c';
            $command[] = 'mcp_servers.boost.command="php"';
            $command[] = '-c';
            $command[] = 'mcp_servers.boost.args='.$this->encodeJsonValue($this->boostMcpArgs());

            if (! $isResuming && $schemaPath !== null) {
                $command[] = '--output-schema';
                $command[] = $schemaPath;
            }

            if (filled($agent->model)) {
                $command[] = '--model';
                $command[] = (string) $agent->model;
            }

            $command[] = '-';

            $result = Process::path($repositoryPath)
                ->env($this->runtimeEnvironment->resolve())
                ->input($prompt)
                ->forever()
                ->run($command);

            if (! $supportsResume) {
                if ($result->failed()) {
                    return new AgentHarnessResult(
                        successful: false,
                        output: null,
                        providerSessionId: null,
                        exitCode: $result->exitCode(),
                        failureMessage: $this->processFailureMessage(
                            'Codex',
                            $result->exitCode(),
                            $result->errorOutput(),
                        ),
                    );
                }

                $output = trim($result->output());

                if ($output === '') {
                    return new AgentHarnessResult(
                        successful: false,
                        output: null,
                        providerSessionId: null,
                        exitCode: $result->exitCode(),
                        failureMessage: 'Codex Agent execution returned an empty response.',
                    );
                }

                return new AgentHarnessResult(
                    successful: true,
                    output: $output,
                    providerSessionId: null,
                    exitCode: $result->exitCode(),
                );
            }

            return $this->parseCodexJsonResult(
                $result->output(),
                $result->errorOutput(),
                $result->exitCode(),
                $result->failed(),
            );
        } finally {
            if ($schemaPath !== null) {
                File::delete($schemaPath);
            }
        }
    }

    /**
     * Parse Codex JSONL while retaining only the final Agent message and resumable thread identifier.
     */
    private function parseCodexJsonResult(
        string $rawOutput,
        string $errorOutput,
        ?int $exitCode,
        bool $processFailed,
    ): AgentHarnessResult {
        $providerSessionId = null;
        $finalMessage = null;
        $lines = preg_split('/\R/u', trim($rawOutput)) ?: [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            try {
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return new AgentHarnessResult(
                    successful: false,
                    output: null,
                    providerSessionId: $providerSessionId,
                    exitCode: $exitCode,
                    failureMessage: 'Codex Agent execution returned invalid JSON event metadata.',
                );
            }

            if (! is_array($event)) {
                continue;
            }

            if (
                ($event['type'] ?? null) === 'thread.started'
                && isset($event['thread_id'])
                && is_string($event['thread_id'])
            ) {
                $providerSessionId = trim($event['thread_id']);
            }

            if (
                ($event['type'] ?? null) === 'item.completed'
                && isset($event['item'])
                && is_array($event['item'])
                && ($event['item']['type'] ?? null) === 'agent_message'
                && isset($event['item']['text'])
                && is_string($event['item']['text'])
            ) {
                $finalMessage = trim($event['item']['text']);
            }
        }

        if ($processFailed) {
            return new AgentHarnessResult(
                successful: false,
                output: $finalMessage,
                providerSessionId: filled($providerSessionId) ? $providerSessionId : null,
                exitCode: $exitCode,
                failureMessage: $this->processFailureMessage('Codex', $exitCode, $errorOutput),
            );
        }

        if ($finalMessage === null || $finalMessage === '') {
            return new AgentHarnessResult(
                successful: false,
                output: null,
                providerSessionId: filled($providerSessionId) ? $providerSessionId : null,
                exitCode: $exitCode,
                failureMessage: 'Codex Agent execution returned no final Agent message.',
            );
        }

        return new AgentHarnessResult(
            successful: true,
            output: $finalMessage,
            providerSessionId: filled($providerSessionId) ? $providerSessionId : null,
            exitCode: $exitCode,
        );
    }

    /**
     * Format a bounded provider failure diagnostic for durable run evidence.
     */
    private function processFailureMessage(string $provider, ?int $exitCode, string $errorOutput): string
    {
        $message = sprintf('%s Agent execution failed with exit code %s.', $provider, $exitCode ?? 'unknown');
        $diagnostic = trim($errorOutput);

        if ($diagnostic !== '') {
            $message .= ' Provider diagnostic: '.Str::limit($diagnostic, 2000, '');
        }

        return $message;
    }

    /**
     * Execute Claude with host-level permissions for every Agent role, enabling local session persistence only when stable resume support is advertised.
     *
     * @param  array<string, mixed>|null  $schema
     */
    private function executeClaude(
        ProjectAgent $agent,
        string $repositoryPath,
        string $prompt,
        ?array $schema,
        ?string $providerSessionId = null,
        bool $writable = false,
    ): AgentHarnessResult {
        $supportsResume = $this->canResume($agent);
        $command = [
            'claude',
            '--print',
            '--output-format',
            'json',
            '--mcp-config',
            $this->encodeJsonValue([
                'mcpServers' => [
                    'boost' => [
                        'command' => 'php',
                        'args' => $this->boostMcpArgs(),
                    ],
                ],
            ]),
        ];

        if ($schema !== null) {
            $command[] = '--json-schema';
            $command[] = $this->encodeJsonValue($schema);
        }

        $command[] = '--dangerously-skip-permissions';

        if ($providerSessionId !== null && $supportsResume) {
            $command[] = '--resume';
            $command[] = $providerSessionId;
        }

        if (! $supportsResume) {
            $command[] = '--no-session-persistence';
        }

        if (filled($agent->model)) {
            $command[] = '--model';
            $command[] = (string) $agent->model;
        }

        $result = Process::path($repositoryPath)
            ->env($this->runtimeEnvironment->resolve())
            ->input($prompt)
            ->forever()
            ->run($command);

        try {
            $payload = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new AgentHarnessResult(
                successful: false,
                output: null,
                providerSessionId: null,
                exitCode: $result->exitCode(),
                failureMessage: 'Claude Agent execution returned invalid JSON metadata.',
            );
        }

        if (! is_array($payload)) {
            return new AgentHarnessResult(
                successful: false,
                output: null,
                providerSessionId: null,
                exitCode: $result->exitCode(),
                failureMessage: 'Claude Agent execution returned invalid result metadata.',
            );
        }

        $capturedSessionId = null;

        if (
            $supportsResume
            && isset($payload['session_id'])
            && is_string($payload['session_id'])
            && trim($payload['session_id']) !== ''
        ) {
            $capturedSessionId = trim($payload['session_id']);
        }

        if ($result->failed()) {
            return new AgentHarnessResult(
                successful: false,
                output: null,
                providerSessionId: $capturedSessionId,
                exitCode: $result->exitCode(),
                failureMessage: sprintf(
                    'Claude Agent execution failed with exit code %s.',
                    $result->exitCode() ?? 'unknown',
                ),
            );
        }

        if ($schema !== null) {
            if (! array_key_exists('structured_output', $payload) || $payload['structured_output'] === null) {
                return new AgentHarnessResult(
                    successful: false,
                    output: null,
                    providerSessionId: $capturedSessionId,
                    exitCode: $result->exitCode(),
                    failureMessage: 'Claude Agent execution did not return structured output.',
                );
            }

            return new AgentHarnessResult(
                successful: true,
                output: $this->encodeJsonValue($payload['structured_output']),
                providerSessionId: $capturedSessionId,
                exitCode: $result->exitCode(),
            );
        }

        if (! isset($payload['result']) || ! is_string($payload['result']) || trim($payload['result']) === '') {
            return new AgentHarnessResult(
                successful: false,
                output: null,
                providerSessionId: $capturedSessionId,
                exitCode: $result->exitCode(),
                failureMessage: 'Claude Agent execution returned an empty result.',
            );
        }

        return new AgentHarnessResult(
            successful: true,
            output: trim($payload['result']),
            providerSessionId: $capturedSessionId,
            exitCode: $result->exitCode(),
        );
    }

    /**
     * Arguments that start this application's Boost MCP server, addressed by absolute path so the
     * provider CLI can reach it regardless of the working directory of the repository under execution.
     *
     * @return list<string>
     */
    private function boostMcpArgs(): array
    {
        return [base_path('artisan'), 'boost:mcp'];
    }

    /**
     * Encode schema or structured provider output while failing loudly on invalid values.
     */
    private function encodeJsonValue(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode Agent harness data.', previous: $exception);
        }
    }
}
