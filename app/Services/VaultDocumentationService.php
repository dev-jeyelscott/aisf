<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\AgentRunAction;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class VaultDocumentationService
{
    private const WORK_NOTE_METADATA_KEY = 'vault_work_note';

    private const WORK_NOTE_PENDING_METADATA_KEY = 'vault_work_note_pending';

    /**
     * Create the vault documentation service with durable AgentRun action recording.
     */
    public function __construct(
        private readonly AgentRunActionRecorder $actionRecorder,
    ) {}

    /**
     * Validate that an active Agent run can safely use baseline vault governance.
     */
    public function preflight(AgentRun $viewer, string $executionToken): void
    {
        $this->authorize($viewer, $executionToken);

        $root = $this->resolveVaultRoot();

        $this->governanceFiles($root, $root);
    }

    /**
     * Return only the applicable governance and minimal metadata for one
     * vault-relative directory.
     *
     * @return array{
     *     server_timestamp: string,
     *     directory: array{relative_path: string},
     *     rules: list<array{path: string, content: string}>
     * }
     */
    public function rulesForDirectory(
        string $directory,
        AgentRun $viewer,
        string $executionToken,
    ): array {
        $this->authorize($viewer, $executionToken);

        $root = $this->resolveVaultRoot();
        $target = $this->resolveTargetDirectory($root, $directory);

        return [
            'server_timestamp' => now()->toIso8601String(),
            'directory' => [
                'relative_path' => $this->relativePath($root, $target),
            ],
            'rules' => $this->governanceFiles($root, $target),
        ];
    }

    /**
     * Write or reconcile exactly one Agent-authored Markdown work note for an active AgentRun.
     *
     * @return array{
     *     relative_path: string,
     *     sha256: string,
     *     timestamp: string
     * }
     */
    public function writeWorkLog(
        AgentRun $viewer,
        string $executionToken,
        string $relativePath,
        string $markdown,
    ): array {
        $this->authorize($viewer, $executionToken);

        if ($markdown === '') {
            throw new UnexpectedValueException(
                'The vault work note Markdown must not be empty.',
            );
        }

        $root = $this->resolveVaultRoot();
        $target = $this->resolveWorkNoteTarget($root, $relativePath);
        $sha256 = hash('sha256', $markdown);

        $alreadyFinalized = $this->reserveWorkNote(
            $viewer,
            $executionToken,
            $target['relative_path'],
            $target['absolute_path'],
            $sha256,
        );

        if ($alreadyFinalized) {
            $this->verifyWorkNoteFile(
                $root,
                $target['absolute_path'],
                $sha256,
            );
        } else {
            $this->writeOrVerifyWorkNote(
                $root,
                $target['absolute_path'],
                $markdown,
                $sha256,
            );
        }

        return $this->finalizeWorkNote(
            $viewer,
            $executionToken,
            $root,
            $target['absolute_path'],
            $target['relative_path'],
            $sha256,
        );
    }

    /**
     * Reserve one path and content hash before any filesystem mutation so retries
     * cannot establish a conflicting second logical work note.
     */
    private function reserveWorkNote(
        AgentRun $viewer,
        string $executionToken,
        string $relativePath,
        string $absolutePath,
        string $sha256,
    ): bool {
        return DB::transaction(function () use (
            $viewer,
            $executionToken,
            $relativePath,
            $absolutePath,
            $sha256,
        ): bool {
            $run = AgentRun::query()
                ->whereKey($viewer->getKey())
                ->lockForUpdate()
                ->sole();

            $this->authorize($run, $executionToken);

            $metadata = $this->executionMetadata($run);
            $final = $metadata[self::WORK_NOTE_METADATA_KEY] ?? null;

            if ($final !== null) {
                $this->assertWorkNoteMetadataMatches(
                    $final,
                    $relativePath,
                    $sha256,
                    true,
                );

                return true;
            }

            $pending = $metadata[self::WORK_NOTE_PENDING_METADATA_KEY] ?? null;

            if ($pending !== null) {
                $this->assertWorkNoteMetadataMatches(
                    $pending,
                    $relativePath,
                    $sha256,
                    false,
                );

                return false;
            }

            if ($this->pathExistsOrIsSymbolicLink($absolutePath)) {
                throw new UnexpectedValueException(
                    'The vault work note destination already exists and is not an AISF retry or recovery.',
                );
            }

            $metadata[self::WORK_NOTE_PENDING_METADATA_KEY] = [
                'relative_path' => $relativePath,
                'sha256' => $sha256,
            ];

            $run->forceFill([
                'execution_metadata' => $metadata,
            ])->save();

            return false;
        }, 3);
    }

    /**
     * Persist successful work-note metadata and action evidence atomically after
     * the filesystem file has been verified to contain the expected bytes.
     *
     * @return array{
     *     relative_path: string,
     *     sha256: string,
     *     timestamp: string
     * }
     */
    private function finalizeWorkNote(
        AgentRun $viewer,
        string $executionToken,
        string $root,
        string $absolutePath,
        string $relativePath,
        string $sha256,
    ): array {
        return DB::transaction(function () use (
            $viewer,
            $executionToken,
            $root,
            $absolutePath,
            $relativePath,
            $sha256,
        ): array {
            $run = AgentRun::query()
                ->whereKey($viewer->getKey())
                ->lockForUpdate()
                ->sole();

            $this->authorize($run, $executionToken);

            $this->verifyWorkNoteFile(
                $root,
                $absolutePath,
                $sha256,
            );

            $metadata = $this->executionMetadata($run);
            $final = $metadata[self::WORK_NOTE_METADATA_KEY] ?? null;
            $pending = $metadata[self::WORK_NOTE_PENDING_METADATA_KEY] ?? null;

            if ($final === null) {
                if ($pending === null) {
                    throw new UnexpectedValueException(
                        'The AgentRun has no reserved vault work note to finalize.',
                    );
                }

                $this->assertWorkNoteMetadataMatches(
                    $pending,
                    $relativePath,
                    $sha256,
                    false,
                );

                $final = [
                    'relative_path' => $relativePath,
                    'sha256' => $sha256,
                    'timestamp' => now()->toIso8601String(),
                ];

                $metadata[self::WORK_NOTE_METADATA_KEY] = $final;
            } else {
                $this->assertWorkNoteMetadataMatches(
                    $final,
                    $relativePath,
                    $sha256,
                    true,
                );

                if ($pending !== null) {
                    $this->assertWorkNoteMetadataMatches(
                        $pending,
                        $relativePath,
                        $sha256,
                        false,
                    );
                }
            }

            unset($metadata[self::WORK_NOTE_PENDING_METADATA_KEY]);

            $run->forceFill([
                'execution_metadata' => $metadata,
            ])->save();

            $actions = $run->actions()
                ->where(
                    'action',
                    AgentRunAction::ACTION_VAULT_NOTE_WRITTEN,
                )
                ->get();

            if ($actions->count() > 1) {
                throw new UnexpectedValueException(
                    'The AgentRun has duplicate vault work note action evidence.',
                );
            }

            if ($actions->isNotEmpty()) {
                $action = $actions->sole();

                if (
                    $action->resource_type !== AgentRunAction::RESOURCE_AGENT_RUN
                    || $action->resource_id !== (int) $run->getKey()
                ) {
                    throw new UnexpectedValueException(
                        'The AgentRun vault work note action evidence is inconsistent.',
                    );
                }
            } else {
                $this->actionRecorder->record(
                    $run,
                    AgentRunAction::ACTION_VAULT_NOTE_WRITTEN,
                    $run,
                );
            }

            /** @var array{relative_path: string, sha256: string, timestamp: string} $final */
            return $final;
        }, 3);
    }

    /**
     * Return execution metadata as a mutable array.
     *
     * @return array<string, mixed>
     */
    private function executionMetadata(AgentRun $run): array
    {
        return $run->execution_metadata ?? [];
    }

    /**
     * Validate persisted pending or final work-note metadata against the logical request.
     */
    private function assertWorkNoteMetadataMatches(
        mixed $metadata,
        string $relativePath,
        string $sha256,
        bool $requireTimestamp,
    ): void {
        if (
            ! is_array($metadata)
            || ! isset($metadata['relative_path'], $metadata['sha256'])
            || ! is_string($metadata['relative_path'])
            || ! is_string($metadata['sha256'])
        ) {
            throw new UnexpectedValueException(
                'The AgentRun vault work note metadata is malformed.',
            );
        }

        if (
            $requireTimestamp
            && (
                ! isset($metadata['timestamp'])
                || ! is_string($metadata['timestamp'])
                || $metadata['timestamp'] === ''
            )
        ) {
            throw new UnexpectedValueException(
                'The AgentRun vault work note metadata is missing its timestamp.',
            );
        }

        if (
            $metadata['relative_path'] !== $relativePath
            || ! hash_equals($metadata['sha256'], $sha256)
        ) {
            throw new UnexpectedValueException(
                'This AgentRun already owns a different vault work note.',
            );
        }
    }

    /**
     * Resolve and validate one Markdown destination without exposing or mutating governance.
     *
     * @return array{absolute_path: string, relative_path: string}
     */
    private function resolveWorkNoteTarget(
        string $root,
        string $relativePath,
    ): array {
        $normalized = $this->normalizeWorkNoteRelativePath($relativePath);
        $filename = basename($normalized);

        if (
            strlen($filename) <= 3
            || ! str_ends_with(strtolower($filename), '.md')
        ) {
            throw new UnexpectedValueException(
                'The vault work note destination must be a Markdown .md file.',
            );
        }

        if (strcasecmp($filename, 'AGENTS.md') === 0) {
            throw new UnexpectedValueException(
                'Vault AGENTS.md governance files cannot be written by this tool.',
            );
        }

        $this->assertNoSymlinkedParentDirectories(
            $root,
            $normalized,
        );

        $parentRelative = dirname($normalized);
        $parent = $this->resolveTargetDirectory(
            $root,
            $parentRelative,
        );

        if (! is_writable($parent)) {
            throw new UnexpectedValueException(
                'The vault work note parent directory must be writable.',
            );
        }

        $absolutePath = $parent
            .DIRECTORY_SEPARATOR
            .$filename;

        if (is_link($absolutePath)) {
            throw new UnexpectedValueException(
                'The vault work note destination must not be a symbolic link.',
            );
        }

        if (
            file_exists($absolutePath)
            && ! is_file($absolutePath)
        ) {
            throw new UnexpectedValueException(
                'The vault work note destination must be a regular file.',
            );
        }

        $canonicalParent = $this->relativePath($root, $parent);
        $canonicalRelative = $canonicalParent === '.'
            ? $filename
            : $canonicalParent.'/'.$filename;

        return [
            'absolute_path' => $absolutePath,
            'relative_path' => $canonicalRelative,
        ];
    }

    /**
     * Normalize caller input to one deterministic vault-relative Markdown path.
     */
    private function normalizeWorkNoteRelativePath(
        string $relativePath,
    ): string {
        $normalized = str_replace(
            '\\',
            '/',
            trim($relativePath),
        );

        if ($normalized === '') {
            throw new UnexpectedValueException(
                'A vault-relative work note path is required.',
            );
        }

        if (
            str_contains($normalized, "\0")
            || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
        ) {
            throw new UnexpectedValueException(
                'The vault work note path must be relative to the configured vault.',
            );
        }

        $segments = explode('/', $normalized);
        $canonicalSegments = [];

        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new UnexpectedValueException(
                    'The vault work note path must not contain parent traversal.',
                );
            }

            if ($segment === '' || $segment === '.') {
                continue;
            }

            $canonicalSegments[] = $segment;
        }

        if ($canonicalSegments === []) {
            throw new UnexpectedValueException(
                'A vault-relative work note path is required.',
            );
        }

        return implode('/', $canonicalSegments);
    }

    /**
     * Reject symbolic links in every caller-addressed parent directory before writing.
     */
    private function assertNoSymlinkedParentDirectories(
        string $root,
        string $relativePath,
    ): void {
        $segments = explode('/', $relativePath);
        array_pop($segments);

        $cursor = $root;

        foreach ($segments as $segment) {
            $cursor .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($cursor)) {
                throw new UnexpectedValueException(
                    'The vault work note path must not traverse symbolic-link directories.',
                );
            }
        }
    }

    /**
     * Determine whether a filesystem path currently exists or represents a symbolic link.
     */
    private function pathExistsOrIsSymbolicLink(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    /**
     * Exclusively create the exact Agent-authored Markdown bytes or verify an
     * already-created recovery file for the reserved path and content hash.
     */
    private function writeOrVerifyWorkNote(
        string $root,
        string $absolutePath,
        string $markdown,
        string $sha256,
    ): void {
        if ($this->pathExistsOrIsSymbolicLink($absolutePath)) {
            $this->verifyWorkNoteFile(
                $root,
                $absolutePath,
                $sha256,
            );

            return;
        }

        $handle = @fopen($absolutePath, 'x+b');

        if ($handle === false) {
            if ($this->pathExistsOrIsSymbolicLink($absolutePath)) {
                $this->verifyWorkNoteFile(
                    $root,
                    $absolutePath,
                    $sha256,
                );

                return;
            }

            throw new RuntimeException(
                'The vault work note could not be created.',
            );
        }

        try {
            $length = strlen($markdown);
            $offset = 0;

            while ($offset < $length) {
                $written = fwrite(
                    $handle,
                    substr($markdown, $offset),
                );

                if ($written === false || $written === 0) {
                    throw new RuntimeException(
                        'The complete vault work note could not be written.',
                    );
                }

                $offset += $written;
            }

            if (! fflush($handle)) {
                throw new RuntimeException(
                    'The vault work note could not be flushed to the filesystem.',
                );
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($absolutePath);

            throw $exception;
        }

        fclose($handle);

        $this->verifyWorkNoteFile(
            $root,
            $absolutePath,
            $sha256,
        );
    }

    /**
     * Prove that the work note is a regular in-vault file with the exact expected SHA-256.
     */
    private function verifyWorkNoteFile(
        string $root,
        string $absolutePath,
        string $sha256,
    ): void {
        clearstatcache(true, $absolutePath);

        if (is_link($absolutePath)) {
            throw new UnexpectedValueException(
                'The vault work note destination must not be a symbolic link.',
            );
        }

        $resolved = realpath($absolutePath);

        if (
            $resolved === false
            || ! is_file($resolved)
            || ! is_readable($resolved)
            || ! $this->isWithinRoot($root, $resolved)
        ) {
            throw new UnexpectedValueException(
                'The vault work note must be a readable regular file inside the configured vault.',
            );
        }

        $actualSha256 = hash_file('sha256', $resolved);

        if (
            $actualSha256 === false
            || ! hash_equals($sha256, $actualSha256)
        ) {
            throw new UnexpectedValueException(
                'The existing vault work note does not match the reserved SHA-256 hash.',
            );
        }
    }

    /**
     * Require a persisted running AgentRun with a configured ProjectAgent and
     * the exact execution token issued for that invocation.
     */
    private function authorize(
        AgentRun $viewer,
        string $executionToken,
    ): void {
        $viewer->loadMissing('agentSession.projectAgent');

        $storedToken = $viewer->execution_token;

        if (
            ! $viewer->exists
            || $viewer->status !== 'running'
            || $viewer->agentSession?->projectAgent === null
            || ! is_string($storedToken)
            || $storedToken === ''
            || $executionToken === ''
            || ! hash_equals($storedToken, $executionToken)
        ) {
            throw new UnexpectedValueException(
                'The Agent run is not authorized to read vault governance.',
            );
        }
    }

    /**
     * Resolve the configured vault to a canonical existing readable directory.
     */
    private function resolveVaultRoot(): string
    {
        $configuredPath = config('aisf.obsidian_vault_path');

        if (
            ! is_string($configuredPath)
            || trim($configuredPath) === ''
        ) {
            throw new UnexpectedValueException(
                'The Obsidian vault path is not configured.',
            );
        }

        $expandedPath = $this->expandConfiguredHome(
            trim($configuredPath),
        );
        $resolvedPath = realpath($expandedPath);

        if (
            $resolvedPath === false
            || ! is_dir($resolvedPath)
            || ! is_readable($resolvedPath)
        ) {
            throw new UnexpectedValueException(
                'The configured Obsidian vault must be an existing readable directory.',
            );
        }

        if ($resolvedPath === DIRECTORY_SEPARATOR) {
            throw new UnexpectedValueException(
                'The Obsidian vault must not resolve to the filesystem root.',
            );
        }

        return rtrim($resolvedPath, DIRECTORY_SEPARATOR);
    }

    /**
     * Expand only the current user's configured "~" home-directory shorthand.
     */
    private function expandConfiguredHome(string $path): string
    {
        if ($path !== '~' && ! str_starts_with($path, '~/')) {
            if (str_starts_with($path, '~')) {
                throw new UnexpectedValueException(
                    'The Obsidian vault path supports only "~" or "~/" home-directory shorthand.',
                );
            }

            return $path;
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME');

        if (! is_string($home) || trim($home) === '') {
            throw new UnexpectedValueException(
                'The Obsidian vault path uses "~", but the server home directory is unavailable.',
            );
        }

        $home = rtrim(trim($home), DIRECTORY_SEPARATOR);

        if ($home === '') {
            $home = DIRECTORY_SEPARATOR;
        }

        if ($path === '~') {
            return $home;
        }

        return rtrim($home, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .ltrim(substr($path, 2), DIRECTORY_SEPARATOR);
    }

    /**
     * Resolve a caller-supplied vault-relative directory and prove that its
     * canonical location remains inside the configured vault.
     */
    private function resolveTargetDirectory(
        string $root,
        string $directory,
    ): string {
        $normalized = str_replace('\\', '/', trim($directory));

        if ($normalized === '') {
            throw new UnexpectedValueException(
                'A vault-relative directory is required.',
            );
        }

        if (
            str_contains($normalized, "\0")
            || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
        ) {
            throw new UnexpectedValueException(
                'The requested vault directory must be relative to the configured vault.',
            );
        }

        $segments = explode('/', $normalized);

        if (in_array('..', $segments, true)) {
            throw new UnexpectedValueException(
                'The requested vault directory must not contain parent traversal.',
            );
        }

        $candidate = $normalized === '.'
            ? $root
            : $root
                .DIRECTORY_SEPARATOR
                .str_replace('/', DIRECTORY_SEPARATOR, $normalized);

        $resolved = realpath($candidate);

        if (
            $resolved === false
            || ! is_dir($resolved)
            || ! is_readable($resolved)
        ) {
            throw new UnexpectedValueException(
                'The requested vault directory must be an existing readable directory.',
            );
        }

        if (! $this->isWithinRoot($root, $resolved)) {
            throw new UnexpectedValueException(
                'The requested vault directory must resolve inside the configured vault.',
            );
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /**
     * Read only literal AGENTS.md governance files from the root-to-target
     * ancestor chain in deterministic broad-to-specific order.
     *
     * @return list<array{path: string, content: string}>
     */
    private function governanceFiles(
        string $root,
        string $target,
    ): array {
        $rules = [];

        foreach ($this->ancestorDirectories($root, $target) as $directory) {
            $candidate = $directory.DIRECTORY_SEPARATOR.'AGENTS.md';
            $relativeCandidate = $this->relativePath($root, $candidate);
            $isRootGovernance = $directory === $root;

            if (is_link($candidate)) {
                throw new UnexpectedValueException(
                    "The vault governance file \"{$relativeCandidate}\" must not be a symbolic link.",
                );
            }

            if (! file_exists($candidate)) {
                if ($isRootGovernance) {
                    throw new UnexpectedValueException(
                        'The Obsidian vault root must contain a readable AGENTS.md governance file.',
                    );
                }

                continue;
            }

            $resolved = realpath($candidate);

            if (
                $resolved === false
                || ! $this->isWithinRoot($root, $resolved)
                || ! is_file($resolved)
                || ! is_readable($resolved)
            ) {
                throw new UnexpectedValueException(
                    "The vault governance file \"{$relativeCandidate}\" must be a readable regular file inside the configured vault.",
                );
            }

            $content = file_get_contents($resolved);

            if ($content === false) {
                throw new UnexpectedValueException(
                    "The vault governance file \"{$relativeCandidate}\" could not be read.",
                );
            }

            $rules[] = [
                'path' => $this->relativePath($root, $resolved),
                'content' => $content,
            ];
        }

        return $rules;
    }

    /**
     * Return the canonical ancestor directories from vault root through the
     * requested target in broad-to-specific order.
     *
     * @return list<string>
     */
    private function ancestorDirectories(
        string $root,
        string $target,
    ): array {
        $directories = [$root];

        if ($target === $root) {
            return $directories;
        }

        $relative = substr(
            $target,
            strlen($root) + 1,
        );

        if ($relative === '') {
            return $directories;
        }

        $cursor = $root;

        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR.$segment;
            $directories[] = $cursor;
        }

        return $directories;
    }

    /**
     * Convert a canonical vault path to a forward-slash relative path without
     * exposing the configured absolute vault location.
     */
    private function relativePath(
        string $root,
        string $path,
    ): string {
        if ($path === $root) {
            return '.';
        }

        $relative = substr(
            $path,
            strlen($root) + 1,
        );

        if ($relative === '') {
            return '.';
        }

        return str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            $relative,
        );
    }

    /**
     * Verify canonical containment using a directory-separator boundary so
     * similarly prefixed sibling paths cannot be mistaken for vault children.
     */
    private function isWithinRoot(
        string $root,
        string $path,
    ): bool {
        return $path === $root
            || str_starts_with(
                $path,
                $root.DIRECTORY_SEPARATOR,
            );
    }
}
