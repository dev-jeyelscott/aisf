<?php

namespace App\Services;

use App\Models\ProjectAgent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

class AgentHarness
{
    /**
     * Execute a configured Project Agent in read-only planning mode and return its structured JSON payload.
     *
     * @param  array<string, mixed>  $schema
     */
    public function execute(ProjectAgent $agent, string $repositoryPath, string $prompt, array $schema): string
    {
        return match ($agent->harness) {
            'codex' => $this->executeCodex($agent, $repositoryPath, $prompt, $schema),
            'claude' => $this->executeClaude($agent, $repositoryPath, $prompt, $schema),
            default => throw new UnexpectedValueException('The Project Manager harness must be Codex or Claude.'),
        };
    }

    /**
     * Execute Codex with an ephemeral read-only sandbox and a required JSON schema.
     *
     * @param  array<string, mixed>  $schema
     */
    private function executeCodex(ProjectAgent $agent, string $repositoryPath, string $prompt, array $schema): string
    {
        $schemaPath = tempnam(sys_get_temp_dir(), 'aisf-pm-schema-');

        if ($schemaPath === false) {
            throw new RuntimeException('Unable to prepare the Project Manager response schema.');
        }

        try {
            File::put($schemaPath, $this->encodeJson($schema));

            $command = [
                'codex',
                'exec',
                '--ephemeral',
                '--sandbox',
                'read-only',
                '--color',
                'never',
                '--output-schema',
                $schemaPath,
            ];

            if (filled($agent->model)) {
                $command[] = '--model';
                $command[] = (string) $agent->model;
            }

            $command[] = '-';

            $result = Process::path($repositoryPath)
                ->input($prompt)
                ->timeout(70)
                ->idleTimeout(70)
                ->run($command);

            if ($result->failed()) {
                throw new RuntimeException(sprintf(
                    'Codex Project Manager execution failed with exit code %s.',
                    $result->exitCode() ?? 'unknown',
                ));
            }

            $output = trim($result->output());

            if ($output === '') {
                throw new RuntimeException('Codex Project Manager execution returned an empty response.');
            }

            return $output;
        } finally {
            File::delete($schemaPath);
        }
    }

    /**
     * Execute Claude Code in non-persistent read-only plan mode and normalize its structured output.
     *
     * @param  array<string, mixed>  $schema
     */
    private function executeClaude(ProjectAgent $agent, string $repositoryPath, string $prompt, array $schema): string
    {
        $command = [
            'claude',
            '--print',
            '--output-format',
            'json',
            '--json-schema',
            $this->encodeJson($schema),
            '--permission-mode',
            'plan',
            '--no-session-persistence',
        ];

        if (filled($agent->model)) {
            $command[] = '--model';
            $command[] = (string) $agent->model;
        }

        $result = Process::path($repositoryPath)
            ->input($prompt)
            ->timeout(70)
            ->idleTimeout(70)
            ->run($command);

        if ($result->failed()) {
            throw new RuntimeException(sprintf(
                'Claude Project Manager execution failed with exit code %s.',
                $result->exitCode() ?? 'unknown',
            ));
        }

        try {
            $payload = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Claude Project Manager execution returned invalid JSON metadata.', previous: $exception);
        }

        if (! is_array($payload) || ! isset($payload['structured_output']) || ! is_array($payload['structured_output'])) {
            throw new RuntimeException('Claude Project Manager execution did not return structured output.');
        }

        return $this->encodeJson($payload['structured_output']);
    }

    /**
     * Encode structured harness data as JSON while failing loudly on invalid values.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function encodeJson(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode Project Manager harness data.', previous: $exception);
        }
    }
}
