<?php

use App\Models\ProjectAgent;
use App\Services\AgentHarness;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

it('executes Codex with an ephemeral read-only sandbox and structured output schema', function () {
    Process::fake([
        '*' => Process::result(output: '{"summary":"ok"}'),
    ]);
    Process::preventStrayProcesses();
    $agent = new ProjectAgent([
        'harness' => 'codex',
        'model' => 'gpt-5',
    ]);

    $output = app(AgentHarness::class)->execute(
        $agent,
        sys_get_temp_dir(),
        'Inspect the repository.',
        ['type' => 'object'],
    );

    expect($output)->toBe('{"summary":"ok"}');

    Process::assertRan(function (PendingProcess $process): bool {
        if (! is_array($process->command)) {
            return false;
        }

        return $process->path === sys_get_temp_dir()
            && $process->timeout === 70
            && in_array('codex', $process->command, true)
            && in_array('exec', $process->command, true)
            && in_array('--ephemeral', $process->command, true)
            && in_array('--sandbox', $process->command, true)
            && in_array('read-only', $process->command, true)
            && in_array('--output-schema', $process->command, true)
            && in_array('--model', $process->command, true)
            && in_array('gpt-5', $process->command, true);
    });
});

it('executes Claude in non-persistent read-only plan mode and returns only structured output', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'type' => 'result',
            'subtype' => 'success',
            'structured_output' => ['summary' => 'ok'],
        ], JSON_THROW_ON_ERROR)),
    ]);
    Process::preventStrayProcesses();
    $agent = new ProjectAgent([
        'harness' => 'claude',
        'model' => 'sonnet',
    ]);

    $output = app(AgentHarness::class)->execute(
        $agent,
        sys_get_temp_dir(),
        'Inspect the repository.',
        ['type' => 'object'],
    );

    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe(['summary' => 'ok']);

    Process::assertRan(function (PendingProcess $process): bool {
        if (! is_array($process->command)) {
            return false;
        }

        return $process->path === sys_get_temp_dir()
            && $process->timeout === 70
            && in_array('claude', $process->command, true)
            && in_array('--print', $process->command, true)
            && in_array('--output-format', $process->command, true)
            && in_array('--json-schema', $process->command, true)
            && in_array('--permission-mode', $process->command, true)
            && in_array('plan', $process->command, true)
            && in_array('--no-session-persistence', $process->command, true)
            && in_array('--model', $process->command, true)
            && in_array('sonnet', $process->command, true);
    });
});
