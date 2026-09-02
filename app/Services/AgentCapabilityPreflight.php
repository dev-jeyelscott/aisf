<?php

namespace App\Services;

use App\Exceptions\AgentCapabilityException;
use App\Models\ProjectAgent;
use Illuminate\Support\Facades\Process;
use Throwable;

/** Fail fast and clearly when the Agent worker lacks a host capability required for this turn. */
class AgentCapabilityPreflight
{
    public function __construct(
        private readonly AgentRuntimeEnvironment $runtimeEnvironment,
    ) {}

    /**
     * Verify the repository is reachable, the provider CLI resolves on the runtime PATH, and (only
     * when trusted local execution is enabled and the Project appears Docker-dependent) that Docker
     * is actually available to the Agent worker user.
     *
     * @throws AgentCapabilityException
     */
    public function verify(ProjectAgent $agent, string $repositoryPath): void
    {
        $this->assertRepositoryAccessible($repositoryPath);
        $this->assertProviderResolvable($agent->harness);

        if (config('aisf.trusted_local_execution') && $this->projectNeedsDocker($repositoryPath)) {
            $this->assertDockerAvailable();
        }
    }

    /** @throws AgentCapabilityException */
    private function assertRepositoryAccessible(string $repositoryPath): void
    {
        if (! is_dir($repositoryPath) || ! is_readable($repositoryPath)) {
            throw new AgentCapabilityException('The Project repository directory is not accessible to the Agent worker user.');
        }
    }

    /** @throws AgentCapabilityException */
    private function assertProviderResolvable(string $harness): void
    {
        $binary = $harness === 'codex' ? 'codex' : 'claude';

        try {
            $result = Process::env($this->runtimeEnvironment->resolve())
                ->timeout(5)
                ->idleTimeout(5)
                ->run(['which', $binary]);
        } catch (Throwable) {
            throw new AgentCapabilityException("The \"{$binary}\" provider CLI could not be resolved on the Agent worker's PATH.");
        }

        if ($result->failed()) {
            throw new AgentCapabilityException("The \"{$binary}\" provider CLI could not be resolved on the Agent worker's PATH.");
        }
    }

    private function projectNeedsDocker(string $repositoryPath): bool
    {
        foreach (['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml', 'vendor/bin/sail'] as $marker) {
            if (file_exists($repositoryPath.DIRECTORY_SEPARATOR.$marker)) {
                return true;
            }
        }

        return false;
    }

    /** @throws AgentCapabilityException */
    private function assertDockerAvailable(): void
    {
        try {
            $result = Process::env($this->runtimeEnvironment->resolve())
                ->timeout(10)
                ->idleTimeout(10)
                ->run(['docker', 'info']);
        } catch (Throwable) {
            throw new AgentCapabilityException('Docker is required for this Project but is not available to the Agent worker user.');
        }

        if ($result->failed()) {
            throw new AgentCapabilityException('Docker is required for this Project but is not available to the Agent worker user.');
        }
    }
}
