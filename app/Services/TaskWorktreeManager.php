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
    private const CONVENTIONAL_COMMIT_PATTERN = '/^(build|chore|ci|docs|feat|fix|perf|refactor|revert|style|test)(\\([a-z0-9._-]+\\))?!?: .+$/i';

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
     * Confirm the Task worktree HEAD still equals the captured base SHA, detecting an accidental pre-QA commit.
     */
    public function headMatchesBase(Task $task): bool
    {
        $worktreePath = (string) $task->worktree_path;
        $baseSha = (string) $task->base_sha;

        if ($worktreePath === '' || $baseSha === '') {
            throw new UnexpectedValueException(
                'The Task worktree must be created before its Git boundary can be verified.',
            );
        }

        $result = $this->run($worktreePath, ['git', 'rev-parse', 'HEAD']);

        if ($result === null || $result->failed()) {
            throw new RuntimeException('Unable to inspect the Task worktree HEAD.');
        }

        return trim($result->output()) === $baseSha;
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
     * Verify the one Coder-authored commit created from the approved Task worktree.
     *
     * @return array{commit_sha: string, commit_message: string}
     */
    public function verifyApprovedCommit(Task $task): array
    {
        $worktreePath = (string) $task->worktree_path;
        $baseSha = (string) $task->base_sha;
        $branchName = (string) $task->branch_name;

        if ($worktreePath === '' || ! is_dir($worktreePath) || $baseSha === '' || $branchName === '') {
            throw new UnexpectedValueException('The approved Task is missing the Git worktree information required to verify its commit.');
        }

        $head = $this->requiredOutput($worktreePath, ['git', 'rev-parse', 'HEAD'], 'Unable to inspect the approved Task commit.');
        $commitCount = $this->requiredOutput($worktreePath, ['git', 'rev-list', '--count', "{$baseSha}..{$head}"], 'Unable to count commits created by the Task.');

        if ($commitCount !== '1') {
            throw new UnexpectedValueException('The approved Task must create exactly one new commit from its recorded base.');
        }

        $branchHead = $this->requiredOutput($worktreePath, ['git', 'rev-parse', $branchName], 'The approved Task commit is not on its Task branch.');

        if ($branchHead !== $head) {
            throw new UnexpectedValueException('The approved Task commit is not the HEAD of its Task branch.');
        }

        $message = $this->requiredOutput($worktreePath, ['git', 'log', '-1', '--format=%s', $head], 'Unable to read the approved Task commit message.');

        if (preg_match(self::CONVENTIONAL_COMMIT_PATTERN, $message) !== 1) {
            throw new UnexpectedValueException('The approved Task commit message must use conventional commit syntax.');
        }

        $status = $this->requiredOutput($worktreePath, ['git', '--no-optional-locks', 'status', '--porcelain=v1'], 'Unable to verify whether the approved Task worktree is clean.');

        if ($status !== '') {
            throw new UnexpectedValueException('The approved Task worktree still contains uncommitted changes after its commit.');
        }

        return ['commit_sha' => $head, 'commit_message' => $message];
    }

    /**
     * Fast-forward the original Project branch, then clean the integrated Task workspace without invoking an Agent.
     *
     * @return array{commit_sha: string, worktree_cleaned: bool, branch_deleted: bool}
     */
    public function integrateApprovedCommit(Task $task): array
    {
        $task->loadMissing('workRequest.project');
        $projectPath = $this->repositoryInspector->normalizePath($task->workRequest->project->path);
        $repositoryError = $this->repositoryInspector->validationError($projectPath);

        if ($repositoryError !== null) {
            throw new UnexpectedValueException($repositoryError);
        }

        $branchName = (string) $task->branch_name;
        $baseBranch = (string) $task->base_branch;
        $commitSha = (string) $task->commit_sha;
        $worktreePath = (string) $task->worktree_path;

        if ($branchName === '' || $baseBranch === '' || $commitSha === '' || $worktreePath === '' || ! is_dir($worktreePath)) {
            throw new UnexpectedValueException('The verified Task is missing Git information required for integration.');
        }

        $currentBranch = $this->requiredOutput($projectPath, ['git', 'symbolic-ref', '--quiet', '--short', 'HEAD'], 'The Project repository must be on its original branch before integration.');

        if ($currentBranch !== $baseBranch) {
            throw new UnexpectedValueException('The Project repository is no longer on the Task’s original branch, so fast-forward integration cannot proceed.');
        }

        $projectStatus = $this->requiredOutput($projectPath, ['git', '--no-optional-locks', 'status', '--porcelain=v1'], 'Unable to verify whether the Project repository is clean before integration.');

        if ($projectStatus !== '') {
            throw new UnexpectedValueException('The Project repository has uncommitted changes, so fast-forward integration cannot proceed.');
        }

        $canFastForward = $this->run($projectPath, ['git', 'merge-base', '--is-ancestor', 'HEAD', $commitSha]);

        if ($canFastForward === null || $canFastForward->failed()) {
            throw new UnexpectedValueException('The Project branch has moved and cannot fast-forward to the approved Task commit.');
        }

        $merge = $this->run($projectPath, ['git', 'merge', '--ff-only', $commitSha]);

        if ($merge === null || $merge->failed()) {
            throw new UnexpectedValueException('Fast-forward integration of the approved Task commit failed.');
        }

        $integratedSha = $this->requiredOutput($projectPath, ['git', 'rev-parse', 'HEAD'], 'Unable to verify the integrated Project commit.');

        if ($integratedSha !== $commitSha) {
            throw new RuntimeException('The Project HEAD does not match the verified approved Task commit after fast-forward integration.');
        }

        $cleanup = $this->run($projectPath, ['git', 'worktree', 'remove', $worktreePath]);

        if ($cleanup === null || $cleanup->failed()) {
            throw new RuntimeException('The approved Task was integrated, but its worktree could not be cleaned up.');
        }

        $deleteBranch = $this->run($projectPath, ['git', 'branch', '-d', $branchName]);

        return [
            'commit_sha' => $integratedSha,
            'worktree_cleaned' => true,
            'branch_deleted' => $deleteBranch !== null && $deleteBranch->successful(),
        ];
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
