<?php

use App\Exceptions\AgentCapabilityException;
use App\Models\ProjectAgent;
use App\Services\AgentCapabilityPreflight;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

it('passes preflight for a non-Docker project regardless of trusted local execution', function (bool $trustedLocal) {
    config()->set('aisf.trusted_local_execution', $trustedLocal);
    $repositoryPath = feature12TemporaryDirectory();
    $agent = new ProjectAgent(['harness' => 'codex']);

    Process::fake(fn (PendingProcess $process) => Process::result(exitCode: 0));
    Process::preventStrayProcesses();

    expect(fn () => app(AgentCapabilityPreflight::class)->verify($agent, $repositoryPath))
        ->not->toThrow(AgentCapabilityException::class);
})->with([true, false]);

it('passes preflight for a Docker-dependent project when trusted local execution is on and Docker is available', function () {
    config()->set('aisf.trusted_local_execution', true);
    $repositoryPath = feature12TemporaryDirectory();
    File::put($repositoryPath.'/compose.yaml', "services:\n  app: {}\n");
    $agent = new ProjectAgent(['harness' => 'codex']);

    Process::fake(fn (PendingProcess $process) => Process::result(exitCode: 0));
    Process::preventStrayProcesses();

    expect(fn () => app(AgentCapabilityPreflight::class)->verify($agent, $repositoryPath))
        ->not->toThrow(AgentCapabilityException::class);
});

it('does not require Docker for a Docker-dependent project when trusted local execution is off', function () {
    config()->set('aisf.trusted_local_execution', false);
    $repositoryPath = feature12TemporaryDirectory();
    File::put($repositoryPath.'/compose.yaml', "services:\n  app: {}\n");
    $agent = new ProjectAgent(['harness' => 'codex']);

    $dockerCalled = false;
    Process::fake(function (PendingProcess $process) use (&$dockerCalled) {
        if (($process->command[0] ?? null) === 'docker') {
            $dockerCalled = true;

            return Process::result(exitCode: 1);
        }

        return Process::result(exitCode: 0);
    });
    Process::preventStrayProcesses();

    app(AgentCapabilityPreflight::class)->verify($agent, $repositoryPath);

    expect($dockerCalled)->toBeFalse();
});

it('throws when the repository path is not accessible', function () {
    $agent = new ProjectAgent(['harness' => 'codex']);

    expect(fn () => app(AgentCapabilityPreflight::class)->verify($agent, '/nonexistent/path/'.Str::uuid()))
        ->toThrow(AgentCapabilityException::class);
});

it('throws when the provider binary cannot be resolved on the runtime PATH', function () {
    $repositoryPath = feature12TemporaryDirectory();
    $agent = new ProjectAgent(['harness' => 'codex']);

    Process::fake(fn (PendingProcess $process) => Process::result(exitCode: 1));
    Process::preventStrayProcesses();

    expect(fn () => app(AgentCapabilityPreflight::class)->verify($agent, $repositoryPath))
        ->toThrow(AgentCapabilityException::class, 'codex');
});

it('throws a clean capability diagnostic without leaking environment details when Docker is required but unavailable', function () {
    config()->set('aisf.trusted_local_execution', true);
    $repositoryPath = feature12TemporaryDirectory();
    File::put($repositoryPath.'/compose.yaml', "services:\n  app: {}\n");
    $agent = new ProjectAgent(['harness' => 'codex']);

    Process::fake(function (PendingProcess $process) {
        if (($process->command[0] ?? null) === 'docker') {
            return Process::result(exitCode: 1, errorOutput: 'permission denied while trying to connect to the Docker daemon socket');
        }

        return Process::result(exitCode: 0);
    });
    Process::preventStrayProcesses();

    try {
        app(AgentCapabilityPreflight::class)->verify($agent, $repositoryPath);
        expect(false)->toBeTrue('Expected an AgentCapabilityException to be thrown.');
    } catch (AgentCapabilityException $exception) {
        expect($exception->getMessage())
            ->toContain('Docker is required for this Project')
            ->not->toContain('permission denied')
            ->not->toContain('PATH=')
            ->not->toContain('HOME=');
    }
});

function feature12TemporaryDirectory(): string
{
    $path = sys_get_temp_dir().'/aisf-feature12-'.Str::uuid();
    File::ensureDirectoryExists($path);

    return $path;
}
