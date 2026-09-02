<?php

use App\Mcp\Tools\GetVaultRules;
use App\Mcp\Tools\WriteVaultWorkLog;
use App\Models\AgentRun;
use App\Models\AgentRunAction;
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

test('writes exact Agent-authored Markdown and persists only auditable metadata', function (): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );
    File::ensureDirectoryExists(
        $this->vaultPath.'/Work Logs',
    );

    $run = vaultDocumentationRun('coder');
    $markdown = "# Implemented Slice 2\n\nExact Agent-authored content.\n";
    $sha256 = hash('sha256', $markdown);

    $this->travelTo(
        Carbon::parse('2026-09-02T11:30:00+08:00'),
    );

    try {
        $result = app(VaultDocumentationService::class)
            ->writeWorkLog(
                $run,
                (string) $run->execution_token,
                'Work Logs/run-1.md',
                $markdown,
            );

        expect($result)->toBe([
            'relative_path' => 'Work Logs/run-1.md',
            'sha256' => $sha256,
            'timestamp' => now()->toIso8601String(),
        ]);

        expect(
            File::get($this->vaultPath.'/Work Logs/run-1.md'),
        )->toBe($markdown);

        $run->refresh();

        expect(
            $run->execution_metadata['vault_work_note'],
        )->toBe($result);

        expect(
            $run->execution_metadata,
        )->not->toHaveKey('vault_work_note_pending');

        expect(
            json_encode(
                $run->execution_metadata,
                JSON_THROW_ON_ERROR,
            ),
        )->not->toContain($markdown);

        expect($run->artifacts)->toBeNull();

        $action = $run->actions()
            ->where(
                'action',
                AgentRunAction::ACTION_VAULT_NOTE_WRITTEN,
            )
            ->sole();

        expect($action->resource_type)
            ->toBe(AgentRunAction::RESOURCE_AGENT_RUN);
        expect($action->resource_id)
            ->toBe($run->id);
    } finally {
        $this->travelBack();
    }
});

test('write_vault_work_log is available to every configured Agent role', function (string $role): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );
    File::ensureDirectoryExists(
        $this->vaultPath.'/Work Logs',
    );

    $run = vaultDocumentationRun($role);
    $markdown = "# {$role}\n\nRole-agnostic work note.\n";

    VaultDocumentationMcpServer::tool(
        WriteVaultWorkLog::class,
        [
            'agent_run_id' => $run->id,
            'execution_token' => $run->execution_token,
            'relative_path' => "Work Logs/{$role}.md",
            'markdown' => $markdown,
        ],
    )->assertOk();

    expect(
        File::get(
            $this->vaultPath."/Work Logs/{$role}.md",
        ),
    )->toBe($markdown);
})->with([
    'project_manager',
    'coder',
    'qa',
]);

test('identical vault work note retries are idempotent', function (): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );
    File::ensureDirectoryExists(
        $this->vaultPath.'/Work Logs',
    );

    $run = vaultDocumentationRun('coder');
    $markdown = "# Retry\n\nSame bytes.\n";

    $first = app(VaultDocumentationService::class)
        ->writeWorkLog(
            $run,
            (string) $run->execution_token,
            'Work Logs/retry.md',
            $markdown,
        );

    $second = app(VaultDocumentationService::class)
        ->writeWorkLog(
            $run->refresh(),
            (string) $run->execution_token,
            'Work Logs/retry.md',
            $markdown,
        );

    expect($second)->toBe($first);

    expect(
        $run->refresh()
            ->actions()
            ->where(
                'action',
                AgentRunAction::ACTION_VAULT_NOTE_WRITTEN,
            )
            ->count(),
    )->toBe(1);

    expect(
        File::get($this->vaultPath.'/Work Logs/retry.md'),
    )->toBe($markdown);
});

test('rejects a conflicting second vault work note path', function (): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );
    File::ensureDirectoryExists(
        $this->vaultPath.'/Work Logs',
    );

    $run = vaultDocumentationRun('coder');
    $markdown = "# First note\n";

    app(VaultDocumentationService::class)->writeWorkLog(
        $run,
        (string) $run->execution_token,
        'Work Logs/first.md',
        $markdown,
    );

    expect(
        fn () => app(VaultDocumentationService::class)
            ->writeWorkLog(
                $run->refresh(),
                (string) $run->execution_token,
                'Work Logs/second.md',
                $markdown,
            ),
    )->toThrow(
        UnexpectedValueException::class,
        'This AgentRun already owns a different vault work note.',
    );

    expect(
        File::exists($this->vaultPath.'/Work Logs/second.md'),
    )->toBeFalse();
});

test('rejects conflicting Markdown for the same reserved path', function (): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );
    File::ensureDirectoryExists(
        $this->vaultPath.'/Work Logs',
    );

    $run = vaultDocumentationRun('coder');

    app(VaultDocumentationService::class)->writeWorkLog(
        $run,
        (string) $run->execution_token,
        'Work Logs/one.md',
        "# Original\n",
    );

    expect(
        fn () => app(VaultDocumentationService::class)
            ->writeWorkLog(
                $run->refresh(),
                (string) $run->execution_token,
                'Work Logs/one.md',
                "# Different\n",
            ),
    )->toThrow(
        UnexpectedValueException::class,
        'This AgentRun already owns a different vault work note.',
    );

    expect(
        File::get($this->vaultPath.'/Work Logs/one.md'),
    )->toBe("# Original\n");
});

test('rejects unsafe vault work note paths', function (
    string $relativePath,
    string $expectedMessage,
): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );
    File::ensureDirectoryExists(
        $this->vaultPath.'/Work Logs',
    );

    $run = vaultDocumentationRun('coder');

    expect(
        fn () => app(VaultDocumentationService::class)
            ->writeWorkLog(
                $run,
                (string) $run->execution_token,
                $relativePath,
                "# Unsafe\n",
            ),
    )->toThrow(
        UnexpectedValueException::class,
        $expectedMessage,
    );
})->with([
    'parent traversal' => [
        '../outside.md',
        'The vault work note path must not contain parent traversal.',
    ],
    'unix absolute path' => [
        '/tmp/outside.md',
        'The vault work note path must be relative to the configured vault.',
    ],
    'windows absolute path' => [
        'C:\\temp\\outside.md',
        'The vault work note path must be relative to the configured vault.',
    ],
    'unc path' => [
        '\\\\server\\share\\outside.md',
        'The vault work note path must be relative to the configured vault.',
    ],
    'non Markdown file' => [
        'Work Logs/note.txt',
        'The vault work note destination must be a Markdown .md file.',
    ],
]);

test('rejects stale AgentRuns and incorrect tokens for vault writes', function (
    bool $makeStale,
    string $token,
): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );
    File::ensureDirectoryExists(
        $this->vaultPath.'/Work Logs',
    );

    $run = vaultDocumentationRun('coder');

    if ($makeStale) {
        $run->update([
            'status' => 'succeeded',
            'finished_at' => now(),
        ]);
    }

    $executionToken = $token === 'correct'
        ? (string) $run->execution_token
        : $token;

    expect(
        fn () => app(VaultDocumentationService::class)
            ->writeWorkLog(
                $run,
                $executionToken,
                'Work Logs/rejected.md',
                "# Rejected\n",
            ),
    )->toThrow(UnexpectedValueException::class);

    expect(
        File::exists(
            $this->vaultPath.'/Work Logs/rejected.md',
        ),
    )->toBeFalse();
})->with([
    'stale run' => [true, 'correct'],
    'bad execution token' => [false, 'incorrect-token'],
]);

test('rejects writing AGENTS governance files', function (string $relativePath): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );
    File::ensureDirectoryExists(
        $this->vaultPath.'/Work Logs',
    );

    $run = vaultDocumentationRun('coder');

    expect(
        fn () => app(VaultDocumentationService::class)
            ->writeWorkLog(
                $run,
                (string) $run->execution_token,
                $relativePath,
                "# Replacement governance\n",
            ),
    )->toThrow(
        UnexpectedValueException::class,
        'Vault AGENTS.md governance files cannot be written by this tool.',
    );

    expect(
        File::get($this->vaultPath.'/AGENTS.md'),
    )->toBe('Root governance.');
})->with([
    'root governance' => ['AGENTS.md'],
    'nested governance' => ['Work Logs/AGENTS.md'],
]);

test('rejects a symlinked parent directory for vault work notes', function (): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );

    $outsidePath = $this->vaultPath.'-outside';
    $linkPath = $this->vaultPath.'/linked';

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
                ->writeWorkLog(
                    $run,
                    (string) $run->execution_token,
                    'linked/work.md',
                    "# Unsafe symlink\n",
                ),
        )->toThrow(
            UnexpectedValueException::class,
            'The vault work note path must not traverse symbolic-link directories.',
        );

        expect(
            File::exists($outsidePath.'/work.md'),
        )->toBeFalse();
    } finally {
        if (is_link($linkPath)) {
            unlink($linkPath);
        }

        File::deleteDirectory($outsidePath);
    }
});

test('rejects a symbolic-link vault work note destination', function (): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );

    File::ensureDirectoryExists(
        $this->vaultPath.'/Work Logs',
    );

    $outsideFile = $this->vaultPath.'-outside.md';

    File::put($outsideFile, 'Outside file.');

    $linkPath = $this->vaultPath.'/Work Logs/note.md';

    if (! @symlink($outsideFile, $linkPath)) {
        File::delete($outsideFile);

        $this->markTestSkipped(
            'The test environment does not permit symbolic links.',
        );
    }

    $run = vaultDocumentationRun('coder');

    try {
        expect(
            fn () => app(VaultDocumentationService::class)
                ->writeWorkLog(
                    $run,
                    (string) $run->execution_token,
                    'Work Logs/note.md',
                    "# Unsafe destination\n",
                ),
        )->toThrow(
            UnexpectedValueException::class,
            'The vault work note destination must not be a symbolic link.',
        );

        expect(File::get($outsideFile))
            ->toBe('Outside file.');
    } finally {
        if (is_link($linkPath)) {
            unlink($linkPath);
        }

        File::delete($outsideFile);
    }
});

test('does not claim a pre-existing vault file as a first work note', function (): void {
    vaultDocumentationWriteGovernance(
        $this->vaultPath,
        '.',
        'Root governance.',
    );

    File::ensureDirectoryExists(
        $this->vaultPath.'/Work Logs',
    );

    $markdown = "# Existing\n";

    File::put(
        $this->vaultPath.'/Work Logs/existing.md',
        $markdown,
    );

    $run = vaultDocumentationRun('coder');

    expect(
        fn () => app(VaultDocumentationService::class)
            ->writeWorkLog(
                $run,
                (string) $run->execution_token,
                'Work Logs/existing.md',
                $markdown,
            ),
    )->toThrow(
        UnexpectedValueException::class,
        'The vault work note destination already exists and is not an AISF retry or recovery.',
    );

    expect(
        $run->refresh()->execution_metadata,
    )->toBeNull();
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
