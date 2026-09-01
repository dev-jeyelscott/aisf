<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;
use UnexpectedValueException;

class TaskCandidateFingerprint
{
    /** @return array{tree_sha: string, base_tree_sha: string, kind: 'changes'|'no_change'} */
    public function forTask(Task $task): array
    {
        $worktreePath = $this->worktreePath($task);
        $baseTreeSha = $this->requiredOutput(
            $worktreePath,
            ['git', 'rev-parse', "{$task->base_sha}^{tree}"],
            'Unable to resolve the Task base tree.',
        );
        $treeSha = $this->writeWorkingTree($worktreePath);

        return [
            'tree_sha' => $treeSha,
            'base_tree_sha' => $baseTreeSha,
            'kind' => $treeSha === $baseTreeSha ? 'no_change' : 'changes',
        ];
    }

    public function currentTreeSha(Task $task): string
    {
        return $this->writeWorkingTree($this->worktreePath($task));
    }

    public function commitTreeSha(Task $task, string $commitSha): string
    {
        return $this->requiredOutput(
            $this->worktreePath($task),
            ['git', 'rev-parse', trim($commitSha).'^{tree}'],
            'Unable to resolve the Task commit tree.',
        );
    }

    private function writeWorkingTree(string $worktreePath): string
    {
        $temporaryIndex = tempnam(sys_get_temp_dir(), 'aisf-candidate-index-');

        if ($temporaryIndex === false) {
            throw new UnexpectedValueException('Unable to allocate a temporary Git index.');
        }

        try {
            $indexPath = $this->requiredOutput(
                $worktreePath,
                ['git', 'rev-parse', '--git-path', 'index'],
                'Unable to locate the Task Git index.',
            );

            if (! str_starts_with($indexPath, '/')) {
                $indexPath = $worktreePath.'/'.$indexPath;
            }

            if (is_file($indexPath)) {
                File::copy($indexPath, $temporaryIndex);
            } else {
                File::delete($temporaryIndex);
            }

            $environment = [
                'GIT_INDEX_FILE' => $temporaryIndex,
                'GIT_OPTIONAL_LOCKS' => '0',
            ];

            if (! is_file($temporaryIndex)) {
                $this->requiredProcess(
                    $worktreePath,
                    ['git', 'read-tree', 'HEAD'],
                    $environment,
                    'Unable to initialize the temporary Task Git index.',
                );
            }

            $this->requiredProcess(
                $worktreePath,
                ['git', 'add', '--all', '--', '.'],
                $environment,
                'Unable to stage the Task candidate in its temporary Git index.',
            );

            return $this->requiredOutput(
                $worktreePath,
                ['git', 'write-tree'],
                'Unable to fingerprint the Task candidate tree.',
                $environment,
            );
        } finally {
            File::delete($temporaryIndex);
            File::delete($temporaryIndex.'.lock');
        }
    }

    private function worktreePath(Task $task): string
    {
        $worktreePath = (string) $task->worktree_path;

        if ($worktreePath === '' || ! is_dir($worktreePath) || ! filled($task->base_sha)) {
            throw new UnexpectedValueException('The Task worktree baseline is required to fingerprint a candidate.');
        }

        return $worktreePath;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function requiredOutput(
        string $path,
        array $command,
        string $failureMessage,
        array $environment = ['GIT_OPTIONAL_LOCKS' => '0'],
    ): string {
        return trim($this->requiredProcess($path, $command, $environment, $failureMessage)->output());
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function requiredProcess(
        string $path,
        array $command,
        array $environment,
        string $failureMessage,
    ): ProcessResult {
        try {
            $result = Process::path($path)
                ->env($environment)
                ->timeout(30)
                ->idleTimeout(30)
                ->run($command);
        } catch (Throwable) {
            throw new UnexpectedValueException($failureMessage);
        }

        if ($result->failed()) {
            throw new UnexpectedValueException($failureMessage);
        }

        return $result;
    }
}
