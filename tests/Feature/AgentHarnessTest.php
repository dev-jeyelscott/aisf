<?php

use App\Models\ProjectAgent;
use App\Services\AgentHarness;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

it('starts and resumes Codex with a persistent read-only thread when the installed CLI supports resume', function () {
    $executions = [];

    Process::fake(function (PendingProcess $process) use (&$executions) {
        if ($process->command === ['codex', 'exec', '--help']) {
            return Process::result(
                output: 'Usage: codex exec [OPTIONS] [COMMAND] resume --json --output-schema',
            );
        }

        $executions[] = $process->command;
        $isResume = is_array($process->command)
            && in_array('resume', $process->command, true);

        $message = $isResume
            ? '{"summary":"resumed"}'
            : '{"summary":"started"}';

        return Process::result(output: implode("\n", [
            json_encode([
                'type' => 'thread.started',
                'thread_id' => 'codex-session-1',
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'type' => 'item.completed',
                'item' => [
                    'type' => 'agent_message',
                    'text' => $message,
                ],
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'type' => 'turn.completed',
            ], JSON_THROW_ON_ERROR),
        ]));
    });

    Process::preventStrayProcesses();

    $agent = new ProjectAgent([
        'harness' => 'codex',
        'model' => 'gpt-5',
    ]);
    $harness = app(AgentHarness::class);

    $started = $harness->start(
        $agent,
        sys_get_temp_dir(),
        'Inspect the repository.',
        ['type' => 'object'],
    );

    $resumed = $harness->resume(
        $agent,
        sys_get_temp_dir(),
        'codex-session-1',
        'Only inspect the new delta.',
        ['type' => 'object'],
    );

    expect($started->successful)->toBeTrue()
        ->and($started->output)->toBe('{"summary":"started"}')
        ->and($started->providerSessionId)->toBe('codex-session-1')
        ->and($started->exitCode)->toBe(0)
        ->and($resumed->successful)->toBeTrue()
        ->and($resumed->output)->toBe('{"summary":"resumed"}')
        ->and($resumed->providerSessionId)->toBe('codex-session-1')
        ->and($executions)->toHaveCount(2)
        ->and(feature04NormalizeSchemaPath($executions[0]))->toBe([
            'codex',
            'exec',
            '--json',
            '--dangerously-bypass-approvals-and-sandbox',
            '--color',
            'never',
            ...feature04BoostMcpConfigArgs(),
            '--output-schema',
            '<schema>',
            '--model',
            'gpt-5',
            '-',
        ])
        ->and(feature04NormalizeSchemaPath($executions[1]))->toBe([
            'codex',
            'exec',
            'resume',
            'codex-session-1',
            '--json',
            ...feature04BoostMcpConfigArgs(),
            '--model',
            'gpt-5',
            '-',
        ]);
});

it('retains Codex ephemeral fallback when the installed CLI does not advertise resumable JSON execution', function () {
    $execution = null;

    Process::fake(function (PendingProcess $process) use (&$execution) {
        if ($process->command === ['codex', 'exec', '--help']) {
            return Process::result(output: 'Usage: codex exec [OPTIONS]');
        }

        $execution = $process->command;

        return Process::result(output: '{"summary":"fallback"}');
    });

    Process::preventStrayProcesses();

    $agent = new ProjectAgent([
        'harness' => 'codex',
        'model' => 'gpt-5',
    ]);

    $result = app(AgentHarness::class)->start(
        $agent,
        sys_get_temp_dir(),
        'Inspect the repository.',
        ['type' => 'object'],
    );

    expect($result->successful)->toBeTrue()
        ->and($result->output)->toBe('{"summary":"fallback"}')
        ->and($result->providerSessionId)->toBeNull()
        ->and(feature04NormalizeSchemaPath($execution))->toBe([
            'codex',
            'exec',
            '--ephemeral',
            '--dangerously-bypass-approvals-and-sandbox',
            '--color',
            'never',
            ...feature04BoostMcpConfigArgs(),
            '--output-schema',
            '<schema>',
            '--model',
            'gpt-5',
            '-',
        ]);
});

it('starts and resumes Claude with persistent read-only plan sessions when the installed CLI supports resume', function () {
    $executions = [];

    Process::fake(function (PendingProcess $process) use (&$executions) {
        if ($process->command === ['claude', '--help']) {
            return Process::result(
                output: 'Usage: claude --resume --output-format --json-schema',
            );
        }

        $executions[] = $process->command;
        $isResume = is_array($process->command)
            && in_array('--resume', $process->command, true);

        return Process::result(output: json_encode([
            'type' => 'result',
            'subtype' => 'success',
            'session_id' => 'claude-session-1',
            'structured_output' => [
                'summary' => $isResume ? 'resumed' : 'started',
            ],
        ], JSON_THROW_ON_ERROR));
    });

    Process::preventStrayProcesses();

    $agent = new ProjectAgent([
        'harness' => 'claude',
        'model' => 'sonnet',
    ]);
    $harness = app(AgentHarness::class);

    $started = $harness->start(
        $agent,
        sys_get_temp_dir(),
        'Inspect the repository.',
        ['type' => 'object'],
    );

    $resumed = $harness->resume(
        $agent,
        sys_get_temp_dir(),
        'claude-session-1',
        'Inspect only the new delta.',
        ['type' => 'object'],
    );

    expect(json_decode(
        $started->output ?? '',
        true,
        flags: JSON_THROW_ON_ERROR,
    ))->toBe(['summary' => 'started'])
        ->and($started->providerSessionId)->toBe('claude-session-1')
        ->and(json_decode(
            $resumed->output ?? '',
            true,
            flags: JSON_THROW_ON_ERROR,
        ))->toBe(['summary' => 'resumed'])
        ->and($resumed->providerSessionId)->toBe('claude-session-1')
        ->and($executions)->toBe([
            [
                'claude',
                '--print',
                '--output-format',
                'json',
                '--mcp-config',
                feature04BoostMcpConfigJson(),
                '--json-schema',
                '{"type":"object"}',
                '--dangerously-skip-permissions',
                '--model',
                'sonnet',
            ],
            [
                'claude',
                '--print',
                '--output-format',
                'json',
                '--mcp-config',
                feature04BoostMcpConfigJson(),
                '--json-schema',
                '{"type":"object"}',
                '--dangerously-skip-permissions',
                '--resume',
                'claude-session-1',
                '--model',
                'sonnet',
            ],
        ]);
});

it('retains Claude non-persistent fallback when the installed CLI does not advertise resume', function () {
    $execution = null;

    Process::fake(function (PendingProcess $process) use (&$execution) {
        if ($process->command === ['claude', '--help']) {
            return Process::result(
                output: 'Usage: claude --output-format --json-schema',
            );
        }

        $execution = $process->command;

        return Process::result(output: json_encode([
            'type' => 'result',
            'subtype' => 'success',
            'session_id' => 'non-resumable-id',
            'structured_output' => ['summary' => 'ok'],
        ], JSON_THROW_ON_ERROR));
    });

    Process::preventStrayProcesses();

    $agent = new ProjectAgent([
        'harness' => 'claude',
        'model' => 'sonnet',
    ]);

    $result = app(AgentHarness::class)->start(
        $agent,
        sys_get_temp_dir(),
        'Inspect the repository.',
        ['type' => 'object'],
    );

    expect(json_decode(
        $result->output ?? '',
        true,
        flags: JSON_THROW_ON_ERROR,
    ))->toBe(['summary' => 'ok'])
        ->and($result->providerSessionId)->toBeNull()
        ->and($execution)->toBe([
            'claude',
            '--print',
            '--output-format',
            'json',
            '--mcp-config',
            feature04BoostMcpConfigJson(),
            '--json-schema',
            '{"type":"object"}',
            '--dangerously-skip-permissions',
            '--no-session-persistence',
            '--model',
            'sonnet',
        ]);
});

it('grants Codex workspace-write access only for writable Coder execution', function () {
    $execution = null;

    Process::fake(function (PendingProcess $process) use (&$execution) {
        if ($process->command === ['codex', 'exec', '--help']) {
            return Process::result(output: 'Usage: codex exec [OPTIONS]');
        }

        $execution = $process->command;

        return Process::result(output: '{"summary":"implemented"}');
    });

    Process::preventStrayProcesses();

    $agent = new ProjectAgent(['harness' => 'codex']);

    $result = app(AgentHarness::class)->start(
        $agent,
        sys_get_temp_dir(),
        'Implement the Task.',
        writable: true,
    );

    expect($result->successful)->toBeTrue()
        ->and($execution)->toBe([
            'codex',
            'exec',
            '--ephemeral',
            '--dangerously-bypass-approvals-and-sandbox',
            '--color',
            'never',
            ...feature04BoostMcpConfigArgs(),
            '-',
        ]);
});

it('grants Claude accept-edits access only for writable Coder execution', function () {
    $execution = null;

    Process::fake(function (PendingProcess $process) use (&$execution) {
        if ($process->command === ['claude', '--help']) {
            return Process::result(output: 'Usage: claude --output-format');
        }

        $execution = $process->command;

        return Process::result(output: json_encode([
            'type' => 'result',
            'subtype' => 'success',
            'session_id' => 'writable-session',
            'result' => 'implemented',
        ], JSON_THROW_ON_ERROR));
    });

    Process::preventStrayProcesses();

    $agent = new ProjectAgent(['harness' => 'claude']);

    $result = app(AgentHarness::class)->start(
        $agent,
        sys_get_temp_dir(),
        'Implement the Task.',
        writable: true,
    );

    expect($result->successful)->toBeTrue()
        ->and($result->output)->toBe('implemented')
        ->and($execution)->toBe([
            'claude',
            '--print',
            '--output-format',
            'json',
            '--mcp-config',
            feature04BoostMcpConfigJson(),
            '--dangerously-skip-permissions',
            '--no-session-persistence',
        ]);
});

it('returns process failure metadata without exposing raw provider output', function () {
    Process::fake(function (PendingProcess $process) {
        if ($process->command === ['codex', 'exec', '--help']) {
            return Process::result(output: 'Usage: codex exec [OPTIONS]');
        }

        return Process::result(
            output: 'provider diagnostic output',
            errorOutput: 'provider error output',
            exitCode: 17,
        );
    });

    Process::preventStrayProcesses();

    $agent = new ProjectAgent([
        'harness' => 'codex',
    ]);

    $result = app(AgentHarness::class)->start(
        $agent,
        sys_get_temp_dir(),
        'Inspect the repository.',
        ['type' => 'object'],
    );

    expect($result->successful)->toBeFalse()
        ->and($result->output)->toBeNull()
        ->and($result->exitCode)->toBe(17)
        ->and($result->failureMessage)->toBe(
            'Codex Agent execution failed with exit code 17. Provider diagnostic: provider error output',
        );
});

/**
 * The Codex `-c` overrides that wire this application's Boost MCP server into every execution.
 *
 * @return list<string>
 */
function feature04BoostMcpConfigArgs(): array
{
    return [
        '-c',
        'mcp_servers.boost.command="php"',
        '-c',
        'mcp_servers.boost.args='.json_encode([base_path('artisan'), 'boost:mcp'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    ];
}

/**
 * The Claude `--mcp-config` JSON payload that wires this application's Boost MCP server into every execution.
 */
function feature04BoostMcpConfigJson(): string
{
    return json_encode([
        'mcpServers' => [
            'boost' => [
                'command' => 'php',
                'args' => [base_path('artisan'), 'boost:mcp'],
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/**
 * Normalize the unpredictable temporary schema filename so full Codex command arrays remain exactly assertable.
 *
 * @param  array<int, string>|null  $command
 * @return array<int, string>|null
 */
function feature04NormalizeSchemaPath(?array $command): ?array
{
    if ($command === null) {
        return null;
    }

    $index = array_search('--output-schema', $command, true);

    if ($index !== false && isset($command[$index + 1])) {
        $command[$index + 1] = '<schema>';
    }

    return $command;
}
