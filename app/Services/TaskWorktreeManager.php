<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class TaskWorktreeManager
{
    public function __construct(
        private readonly RepositoryInspector $repositoryInspector,
    ) {}

    /**
     * Create the isolated Task branch and Git worktree from the Project's current branch and HEAD, if one does not already exist.
     */
    public function ensureWorktree(Task $task): void
    {
        if (filled($task->worktree_path) && is_dir($task->worktree_path)) {
            return;
        }

        $task->loadMissing('workRequest.project');
        $project = $task->workRequest->project;
        $repositoryPath = $this->repositoryInspector->normalizePath($project->path);
        $repositoryError = $this->repositoryInspector->validationError($repositoryPath);

        if ($repositoryError !== null) {
            throw new UnexpectedValueException($repositoryError);
        }

        $status = $this->repositoryInspector->status($repositoryPath);
        $headResult = $this->run($repositoryPath, ['git', 'rev-parse', 'HEAD']);

        if ($status === null || $headResult === null || $headResult->failed()) {
            throw new UnexpectedValueException(
                'Unable to inspect the Project repository before creating the Task worktree.',
            );
        }

        $headSha = trim($headResult->output());
        $branchName = sprintf('aisf/task-%d', $task->id);
        $worktreePath = storage_path("app/worktrees/task-{$task->id}");

        File::ensureDirectoryExists(dirname($worktreePath));

        if (is_dir($worktreePath)) {
            File::deleteDirectory($worktreePath);
        }

        $result = $this->run($repositoryPath, [
            'git', 'worktree', 'add', '-b', $branchName, $worktreePath, $headSha,
        ]);

        if ($result === null || $result->failed()) {
            throw new RuntimeException('Unable to create the isolated Task Git worktree.');
        }

        $task->update([
            'base_branch' => $status['branch'],
            'base_sha' => $headSha,
            'branch_name' => $branchName,
            'worktree_path' => $worktreePath,
        ]);
    }

    /**
     * List the Task worktree's currently changed, repository-relative file paths.
     *
     * @return list<string>
     */
    public function changedFiles(Task $task): array
    {
        $worktreePath = (string) $task->worktree_path;

        if ($worktreePath === '' || ! is_dir($worktreePath)) {
            return [];
        }

        $result = $this->run($worktreePath, ['git', '--no-optional-locks', 'status', '--porcelain=v1']);

        if ($result === null || $result->failed()) {
            return [];
        }

        $lines = preg_split('/\R/u', trim($result->output())) ?: [];
        $files = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $files[] = trim(substr($line, 3));
        }

        return array_values(array_unique($files));
    }

    /**
     * Verify the Agent-reported commit actually exists in the Task worktree before recording or integrating it.
     */
    public function verifyCommitExists(Task $task, string $commitSha): string
    {
        $worktreePath = (string) $task->worktree_path;
        $commitSha = trim($commitSha);

        if ($worktreePath === '' || ! is_dir($worktreePath) || $commitSha === '') {
            throw new UnexpectedValueException('The Task worktree and a commit SHA are required to verify a reported commit.');
        }

        $result = $this->run($worktreePath, ['git', 'cat-file', '-e', $commitSha.'^{commit}']);

        if ($result === null || $result->failed()) {
            throw new UnexpectedValueException('The Agent reported a commit SHA that does not exist in the Task worktree.');
        }

        return $this->requiredOutput($worktreePath, ['git', 'rev-parse', $commitSha], 'Unable to resolve the reported commit SHA.');
    }

    /**
     * Push the Task branch to the Project's Git remote and open (or reuse) a pull request for human review,
     * instead of merging directly. The worktree and branch are left in place until the PR is merged elsewhere.
     *
     * @return array{commit_sha: string, pull_request_url: string}
     */
    public function pushAndOpenPullRequest(Task $task, string $commitSha, string $title, string $body): array
    {
        $worktreePath = (string) $task->worktree_path;
        $branchName = (string) $task->branch_name;
        $baseBranch = (string) $task->base_branch;
        $commitSha = trim($commitSha);

        if ($worktreePath === '' || ! is_dir($worktreePath) || $branchName === '' || $baseBranch === '' || $commitSha === '') {
            throw new UnexpectedValueException('The Task is missing Git information required to open a pull request.');
        }

        $push = $this->run($worktreePath, ['git', 'push', '-u', 'origin', $branchName]);

        if ($push === null || $push->failed()) {
            throw new UnexpectedValueException('Unable to push the Task branch to the Project’s Git remote.');
        }

        $create = $this->run($worktreePath, [
            'gh', 'pr', 'create',
            '--base', $baseBranch,
            '--head', $branchName,
            '--title', $title,
            '--body', $body,
        ]);

        if ($create !== null && $create->successful() && trim($create->output()) !== '') {
            return ['commit_sha' => $commitSha, 'pull_request_url' => trim($create->output())];
        }

        $existing = $this->run($worktreePath, ['gh', 'pr', 'view', $branchName, '--json', 'url', '-q', '.url']);

        if ($existing !== null && $existing->successful() && trim($existing->output()) !== '') {
            return ['commit_sha' => $commitSha, 'pull_request_url' => trim($existing->output())];
        }

        throw new UnexpectedValueException('Unable to open a pull request for the Task branch.');
    }

    /**
     * Return concise successful Git output or raise the supplied operator-visible failure message.
     *
     * @param  array<int, string>  $command
     */
    private function requiredOutput(string $path, array $command, string $failureMessage): string
    {
        $result = $this->run($path, $command);

        if ($result === null || $result->failed()) {
            throw new UnexpectedValueException($failureMessage);
        }

        return trim($result->output());
    }

    /**
     * Run a bounded Git worktree command without allowing optional locks.
     *
     * @param  array<int, string>  $command
     */
    private function run(string $path, array $command): ?ProcessResult
    {
        try {
            return Process::path($path)
                ->env(['GIT_OPTIONAL_LOCKS' => '0'])
                ->timeout(30)
                ->idleTimeout(30)
                ->run($command);
        } catch (Throwable) {
            return null;
        }
    }
}
