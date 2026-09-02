<?php

namespace App\Services;

/** Resolve the environment overrides applied to every Codex/Claude subprocess call. */
class AgentRuntimeEnvironment
{
    /**
     * Build the PATH/HOME overrides that give the Agent subprocess terminal parity, falling back to
     * the queue worker's ambient process environment when no explicit runtime override is configured.
     *
     * @return array<string, string>
     */
    public function resolve(): array
    {
        $env = [];

        if (filled(config('aisf.agent_runtime_path'))) {
            $env['PATH'] = (string) config('aisf.agent_runtime_path');
        }

        if (filled(config('aisf.agent_runtime_home'))) {
            $env['HOME'] = (string) config('aisf.agent_runtime_home');
        }

        return $env;
    }
}
