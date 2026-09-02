<?php

namespace App\Services;

use App\Models\AgentRun;
use UnexpectedValueException;

class VaultDocumentationService
{
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

        if ($relative === false || $relative === '') {
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

        if ($relative === false || $relative === '') {
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
