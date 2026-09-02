<?php

use App\Mcp\Tools\GetVaultRules;
use App\Models\AgentRun;
use App\Models\Project;
use App\Services\AgentSessionManager;
use App\Services\ProjectAgentProvisioner;
use App\Services\VaultDocumentationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;
use UnexpectedValueException;

/**
 * Expose only get_vault_rules for focused MCP governance-access tests.
 */
class VaultDocumentationMcpServer extends Server
{
    /**
     * The governance-only tool exercised by this feature.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        GetVaultRules::class,
    ];
}

beforeEach(function (): void {
    $this->vaultPath = storage_path(
        'framework/testing/vaults/'.Str::uuid(),
    );

    File::ensureDirectoryExists($this->vaultPath);

    config()->set(
        'aisf.obsidian_vault_path',
        $this->vaultPath,
    );
});

afterEach(function (): void {
    if (
        isset($this->vaultPath)
        && is_string($this->vaultPath)
        && is_dir($this->vaultPath)
    ) {
        File::deleteDirectory($this->vaultPath);
    }
});

test('configured vault resolution supports home-directory shorthand and preflight governance', function (): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );

    $originalServerHome = $_SERVER['HOME'] ?? null;
    $originalEnvironmentHome = getenv('HOME');
    $testingHome = storage_path('framework/testing');

    $_SERVER['HOME'] = $testingHome;
    putenv('HOME='.$testingHome);

    config()->set(
        'aisf.obsidian_vault_path',
        '~/vaults/'.basename($this->vaultPath),
    );

    $run = vaultDocumentationRun('coder');

    try {
        app(VaultDocumentationService::class)->preflight(
            $run,
            (string) $run->execution_token,
        );

        $result = app(VaultDocumentationService::class)
            ->rulesForDirectory(
                '.',
                $run,
                (string) $run->execution_token,
            );

        expect($result['directory'])->toBe([
            'relative_path' => '.',
        ]);

        expect($result['rules'])->toBe([
            [
                'path' => 'AGENTS.md',
                'content' => 'Root governance.',
            ],
        ]);
    } finally {
        if ($originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $originalServerHome;
        }

        if ($originalEnvironmentHome === false) {
            putenv('HOME');
        } else {
            putenv('HOME='.$originalEnvironmentHome);
        }
    }
});

test('returns server timestamp and only minimal relative directory metadata', function (): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );
    File::ensureDirectoryExists(
        $this->vaultPath.'/Projects/AISF',
    );

    $run = vaultDocumentationRun('coder');

    $this->travelTo(
        Carbon::parse('2026-09-02T10:30:00+08:00'),
    );

    try {
        $result = app(VaultDocumentationService::class)
            ->rulesForDirectory(
                'Projects/AISF',
                $run,
                (string) $run->execution_token,
            );

        expect(array_keys($result))->toBe([
            'server_timestamp',
            'directory',
            'rules',
        ]);

        expect($result['server_timestamp'])
            ->toBe(now()->toIso8601String());

        expect($result['directory'])->toBe([
            'relative_path' => 'Projects/AISF',
        ]);

        expect(json_encode($result, JSON_THROW_ON_ERROR))
            ->not->toContain($this->vaultPath);
    } finally {
        $this->travelBack();
    }
});

test('returns root and nested AGENTS governance in exact broad-to-specific order', function (): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        'Projects',
        'Projects governance.',
    );
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        'Projects/AISF',
        'AISF governance.',
    );

    File::ensureDirectoryExists(
        $this->vaultPath.'/Projects/AISF/Logs',
    );

    $run = vaultDocumentationRun('qa');

    $result = app(VaultDocumentationService::class)
        ->rulesForDirectory(
            'Projects/AISF/Logs',
            $run,
            (string) $run->execution_token,
        );

    expect($result['rules'])->toBe([
        [
            'path' => 'AGENTS.md',
            'content' => 'Root governance.',
        ],
        [
            'path' => 'Projects/AGENTS.md',
            'content' => 'Projects governance.',
        ],
        [
            'path' => 'Projects/AISF/AGENTS.md',
            'content' => 'AISF governance.',
        ],
    ]);
});

test('get_vault_rules is available to every configured Agent role', function (string $role): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Role-agnostic governance.',
    );

    $run = vaultDocumentationRun($role);

    VaultDocumentationMcpServer::tool(
        GetVaultRules::class,
        [
            'directory' => '.',
            'agent_run_id' => $run->id,
            'execution_token' => $run->execution_token,
        ],
    )
        ->assertOk()
        ->assertSee('Role-agnostic governance.');
})->with([
    'project_manager',
    'coder',
    'qa',
]);

test('rejects an incorrect AgentRun execution token', function (): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );

    $run = vaultDocumentationRun('coder');

    expect(
        fn () => app(VaultDocumentationService::class)
            ->rulesForDirectory(
                '.',
                $run,
                'incorrect-execution-token',
            ),
    )->toThrow(
        UnexpectedValueException::class,
        'The Agent run is not authorized to read vault governance.',
    );
});

test('rejects stale AgentRuns regardless of role', function (string $status): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );

    $run = vaultDocumentationRun('qa');

    $run->update([
        'status' => $status,
        'finished_at' => now(),
    ]);

    expect(
        fn () => app(VaultDocumentationService::class)
            ->rulesForDirectory(
                '.',
                $run,
                (string) $run->execution_token,
            ),
    )->toThrow(
        UnexpectedValueException::class,
        'The Agent run is not authorized to read vault governance.',
    );
})->with([
    'succeeded',
    'failed',
]);

test('rejects parent-directory traversal before filesystem access', function (): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );

    $outsidePath = $this->vaultPath.'-outside';
    File::ensureDirectoryExists($outsidePath);
    $run = vaultDocumentationRun('coder');

    try {
        expect(
            fn () => app(VaultDocumentationService::class)
                ->rulesForDirectory(
                    '../'.basename($outsidePath),
                    $run,
                    (string) $run->execution_token,
                ),
        )->toThrow(
            UnexpectedValueException::class,
            'The requested vault directory must not contain parent traversal.',
        );
    } finally {
        File::deleteDirectory($outsidePath);
    }
});

test('rejects absolute directory requests', function (): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );

    $run = vaultDocumentationRun('coder');

    expect(
        fn () => app(VaultDocumentationService::class)
            ->rulesForDirectory(
                sys_get_temp_dir(),
                $run,
                (string) $run->execution_token,
            ),
    )->toThrow(
        UnexpectedValueException::class,
        'The requested vault directory must be relative to the configured vault.',
    );
});

test('rejects a directory symlink that canonically escapes the vault', function (): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );

    $outsidePath = $this->vaultPath.'-symlink-outside';
    $linkPath = $this->vaultPath.'/linked-outside';

    File::ensureDirectoryExists($outsidePath);

    if (! @symlink($outsidePath, $linkPath)) {
        File::deleteDirectory($outsidePath);
        $this->markTestSkipped(
            'The test environment does not permit symbolic links.',
        );
    }

    $run = vaultDocumentationRun('coder');

    try {
        expect(
            fn () => app(VaultDocumentationService::class)
                ->rulesForDirectory(
                    'linked-outside',
                    $run,
                    (string) $run->execution_token,
                ),
        )->toThrow(
            UnexpectedValueException::class,
            'The requested vault directory must resolve inside the configured vault.',
        );
    } finally {
        if (is_link($linkPath)) {
            unlink($linkPath);
        }

        File::deleteDirectory($outsidePath);
    }
});

test('rejects an AGENTS governance symlink instead of exposing its target', function (): void {
    File::put(
        $this->vaultPath.'/Secret.md',
        'This arbitrary note body must never be exposed.',
    );

    if (
        ! @symlink(
            $this->vaultPath.'/Secret.md',
            $this->vaultPath.'/AGENTS.md',
        )
    ) {
        $this->markTestSkipped(
            'The test environment does not permit symbolic links.',
        );
    }

    $run = vaultDocumentationRun('coder');

    try {
        expect(
            fn () => app(VaultDocumentationService::class)
                ->rulesForDirectory(
                    '.',
                    $run,
                    (string) $run->execution_token,
                ),
        )->toThrow(
            UnexpectedValueException::class,
            'The vault governance file "AGENTS.md" must not be a symbolic link.',
        );
    } finally {
        if (is_link($this->vaultPath.'/AGENTS.md')) {
            unlink($this->vaultPath.'/AGENTS.md');
        }
    }
});

test('fails clearly when root vault governance is missing', function (): void {
    $run = vaultDocumentationRun('project_manager');

    expect(
        fn () => app(VaultDocumentationService::class)
            ->preflight(
                $run,
                (string) $run->execution_token,
            ),
    )->toThrow(
        UnexpectedValueException::class,
        'The Obsidian vault root must contain a readable AGENTS.md governance file.',
    );
});

test('fails clearly when existing root governance is unreadable', function (): void {
    $governancePath = $this->vaultPath.'/AGENTS.md';

    File::put(
        $governancePath,
        'Unreadable governance.',
    );

    if (! @chmod($governancePath, 0000)) {
        $this->markTestSkipped(
            'The test environment does not permit changing file permissions.',
        );
    }

    clearstatcache(true, $governancePath);

    if (is_readable($governancePath)) {
        @chmod($governancePath, 0600);

        $this->markTestSkipped(
            'The test runtime can read mode-0000 files, commonly because it is running as root.',
        );
    }

    $run = vaultDocumentationRun('qa');

    try {
        expect(
            fn () => app(VaultDocumentationService::class)
                ->preflight(
                    $run,
                    (string) $run->execution_token,
                ),
        )->toThrow(UnexpectedValueException::class);
    } finally {
        @chmod($governancePath, 0600);
    }
});

test('does not expose arbitrary Markdown note bodies', function (): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );

    File::put(
        $this->vaultPath.'/Private Architecture.md',
        'NEVER-EXPOSE-THIS-NOTE-BODY',
    );

    $run = vaultDocumentationRun('coder');

    $result = app(VaultDocumentationService::class)
        ->rulesForDirectory(
            '.',
            $run,
            (string) $run->execution_token,
        );

    expect($result['rules'])->toHaveCount(1);

    expect(
        json_encode(
            $result,
            JSON_THROW_ON_ERROR,
        ),
    )->not->toContain(
        'NEVER-EXPOSE-THIS-NOTE-BODY',
    );
});

/**
 * Write one AGENTS.md governance file under an isolated test vault.
 */
function vaultDocumentationWriteGovernance(
    string $vaultPath,
    string $relativeDirectory,
    string $content,
): void {
    $directory = $relativeDirectory === '.'
        ? $vaultPath
        : $vaultPath.'/'.trim($relativeDirectory, '/');

    File::ensureDirectoryExists($directory);

    File::put(
        $directory.'/AGENTS.md',
        $content,
    );
}

/**
 * Build a persisted active AgentRun using the repository's real Project,
 * ProjectAgent, AgentSession, and AgentRun creation paths.
 */
function vaultDocumentationRun(string $role): AgentRun
{
    $project = Project::factory()->create();

    app(ProjectAgentProvisioner::class)->ensureFor($project);

    $workRequest = $project->workRequests()->create([
        'prompt' => 'Exercise vault documentation governance.',
    ]);

    if ($role === 'project_manager') {
        $subject = $workRequest;
    } else {
        $subject = $workRequest->tasks()->create([
            'position' => 1,
            'title' => 'Exercise vault governance',
            'objective' => 'Verify governance access.',
            'implementation_spec' => 'Use existing AgentRun conventions.',
            'acceptance_criteria' => [],
            'verification_commands' => [],
            'browser_steps' => [],
        ]);
    }

    $agent = $project->agents()
        ->where('role', $role)
        ->sole();

    $sessions = app(AgentSessionManager::class);

    return $sessions->startRun(
        $sessions->forSubject(
            $agent,
            $subject,
        ),
        $role,
        [
            'mode' => 'initial',
            'input' => 'Read vault governance.',
            'sources' => [],
            'agent_snapshot' => [],
            'prompt_snapshot' => [],
            'role' => $role,
        ],
    );
}
