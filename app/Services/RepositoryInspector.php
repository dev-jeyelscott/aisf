<?php

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Throwable;

class RepositoryInspector
{
    /**
     * Expand a home-directory shortcut into the absolute path used by PHP.
     */
    public function normalizePath(string $path): string
    {
        if (! str_starts_with($path, '~/')) {
            return $path;
        }

        $homeDirectory = getenv('HOME');

        if ($homeDirectory === false || $homeDirectory === '') {
            return $path;
        }

        return rtrim($homeDirectory, '/').substr($path, 1);
    }

    /**
     * Determine whether a path is a usable Git working tree.
     */
    public function validationError(string $path): ?string
    {
        $path = $this->normalizePath($path);

        if (! file_exists($path)) {
            return 'The project path does not exist.';
        }

        if (! is_dir($path)) {
            return 'The project path must be a directory.';
        }

        $result = $this->run($path, ['git', 'rev-parse', '--is-inside-work-tree']);

        if ($result === null || $result->failed() || trim($result->output()) !== 'true') {
            return 'The project path must be a valid Git working tree.';
        }

        return null;
    }

    /**
     * Inspect the live, read-only state of a Git working tree.
     *
     * @return array{branch: string|null, headSha: string, isClean: bool}|null
     */
    public function status(string $path): ?array
    {
        $path = $this->normalizePath($path);

        if ($this->validationError($path) !== null) {
            return null;
        }

        $branch = $this->run($path, ['git', 'symbolic-ref', '--quiet', '--short', 'HEAD']);
        $head = $this->run($path, ['git', 'rev-parse', '--short', 'HEAD']);
        $workingTree = $this->run($path, ['git', '--no-optional-locks', 'status', '--porcelain=v1']);

        if ($head === null || $head->failed() || $workingTree === null || $workingTree->failed()) {
            return null;
        }

        return [
            'branch' => $branch?->successful() ? trim($branch->output()) : null,
            'headSha' => trim($head->output()),
            'isClean' => trim($workingTree->output()) === '',
        ];
    }

    /**
     * Run a bounded Git inspection command without allowing optional locks.
     *
     * @param  array<int, string>  $command
     */
    private function run(string $path, array $command): ?ProcessResult
    {
        try {
            return Process::path($path)
                ->env(['GIT_OPTIONAL_LOCKS' => '0'])
                ->timeout(5)
                ->idleTimeout(5)
                ->run($command);
        } catch (Throwable) {
            return null;
        }
    }
}
