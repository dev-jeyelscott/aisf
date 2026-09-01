<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
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
        $worktreePath = rtrim((string) config('aisf.worktree_base_path'), '/')."/task-{$task->id}";

        File::ensureDirectoryExists(dirname($worktreePath));

        if (is_dir($worktreePath)) {
            File::deleteDirectory($worktreePath);
        }

        // Clear stale worktree admin entries and a leftover branch from an earlier attempt whose
        // directory was removed without going through `git worktree remove` (e.g. a prior failure,
        // or this same cleanup above) — otherwise `worktree add -b` fails even though nothing is
        // actually using the directory or branch any more.
        $this->run($repositoryPath, ['git', 'worktree', 'prune']);
        $this->run($repositoryPath, ['git', 'branch', '-D', $branchName]);

        $result = $this->run($repositoryPath, [
            'git', 'worktree', 'add', '-b', $branchName, $worktreePath, $headSha,
        ]);

        if ($result === null || $result->failed()) {
            throw new RuntimeException('Unable to create the isolated Task Git worktree.');
        }

        $this->seedInstalledDependencies($repositoryPath, $worktreePath);
        $this->skipWorktreeForTrackedLocalState($worktreePath);

        $task->update([
            'base_branch' => $status['branch'],
            'base_sha' => $headSha,
            'branch_name' => $branchName,
            'worktree_path' => $worktreePath,
        ]);
    }

    /**
     * Copy the Project's already-installed, gitignored local-environment files into a fresh Task
     * worktree. `git worktree add` only checks out tracked files, so without this an Agent's CI
     * check (composer/npm scripts, `artisan` itself) silently falls back to whatever global tooling
     * happens to be on PATH instead of the Project's pinned versions, fails outright with vendor/
     * missing, or can't boot at all without `.env`/the local database. A real copy (not a symlink)
     * keeps the Agent's `composer update`/`npm install`/migration writes isolated to the worktree
     * instead of mutating the Project's real checkout.
     */
    private function seedInstalledDependencies(string $repositoryPath, string $worktreePath): void
    {
        foreach (['vendor', 'node_modules'] as $directory) {
            $source = "{$repositoryPath}/{$directory}";

            if (! is_dir($source)) {
                continue;
            }

            $this->run($repositoryPath, ['cp', '-a', $source, "{$worktreePath}/{$directory}"], timeout: 300, idleTimeout: 300);
        }

        $files = [
            "{$repositoryPath}/.env",
            ...File::glob("{$repositoryPath}/database/*.sqlite"),
        ];

        foreach ($files as $source) {
            if (! is_file($source)) {
                continue;
            }

            $destination = $worktreePath.Str::after($source, $repositoryPath);
            File::ensureDirectoryExists(dirname($destination));
            File::copy($source, $destination);
        }
    }

    /**
     * Some Projects commit their local SQLite database into Git (rather than gitignoring it), so
     * running migrations or tests inside a Task worktree leaves it "modified" even though no Agent
     * touched it intentionally. That noise pollutes `git status`/`git diff` and confuses the Agent's
     * own report of what it changed, so mark every tracked SQLite file `--skip-worktree`: Git then
     * ignores local writes to it for status/diff purposes without altering the pristine checked-out
     * committed content or the Project's own repository.
     */
    private function skipWorktreeForTrackedLocalState(string $worktreePath): void
    {
        $tracked = $this->run($worktreePath, ['git', 'ls-files']);

        if ($tracked === null || $tracked->failed()) {
            return;
        }

        $paths = array_filter(preg_split('/\R/u', trim($tracked->output())) ?: [], fn (string $path): bool => $path !== '');

        foreach ($paths as $path) {
            if (! $this->looksLikeSqliteDatabase("{$worktreePath}/{$path}")) {
                continue;
            }

            $this->run($worktreePath, ['git', 'update-index', '--skip-worktree', $path]);
        }
    }

    /**
     * Sniff the SQLite file-format magic header rather than trusting a filename or extension, since a
     * committed local database is not guaranteed to be named `*.sqlite`.
     */
    private function looksLikeSqliteDatabase(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 16);
        fclose($handle);

        return is_string($header) && str_starts_with($header, "SQLite format 3\x00");
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
     * Run the Project's CI check script inside the Task worktree so a failing pull request is never opened.
     *
     * @return array{passed: bool, output: string}
     */
    public function runCiCheck(Task $task): array
    {
        $worktreePath = (string) $task->worktree_path;

        if ($worktreePath === '' || ! is_dir($worktreePath)) {
            throw new UnexpectedValueException('The Task worktree must exist before CI checks can run.');
        }

        $result = $this->run($worktreePath, ['composer', 'ci:check'], timeout: 900, idleTimeout: 900);

        return [
            'passed' => $result !== null && $result->successful(),
            'output' => $result !== null
                ? trim($result->output()."\n".$result->errorOutput())
                : 'Unable to run the Project CI check script.',
        ];
    }

    public function verifyHeadMatches(Task $task, string $expectedSha): void
    {
        $headSha = $this->requiredOutput((string) $task->worktree_path, ['git', 'rev-parse', 'HEAD'], 'Unable to resolve the Task worktree HEAD.');

        if ($headSha !== $expectedSha) {
            throw new UnexpectedValueException('The Task worktree HEAD no longer matches the candidate SHA.');
        }
    }

    /**
     * A Coder may not create a Git commit until a later, approved finalization turn.
     */
    public function assertNoCommitBeforeQa(Task $task): void
    {
        $baseSha = (string) $task->base_sha;
        $worktreePath = (string) $task->worktree_path;

        if ($baseSha === '' || $worktreePath === '' || ! is_dir($worktreePath)) {
            throw new UnexpectedValueException('The Task worktree baseline is required before QA handoff.');
        }

        $commits = $this->requiredOutput($worktreePath, ['git', 'rev-list', "{$baseSha}..HEAD"], 'Unable to inspect Task commits before QA.');

        if ($commits !== '') {
            throw new UnexpectedValueException('The Coder created a commit before QA approval.');
        }
    }

    public function mergePullRequest(Task $task, string $expectedSha): void
    {
        $this->verifyHeadMatches($task, $expectedSha);
        $task->loadMissing('workRequest.project');

        if (! $task->workRequest->project->enabled) {
            throw new UnexpectedValueException('Automatic merge requires an enabled Project authorization.');
        }

        $url = (string) $task->pull_request_url;

        if ($url === '') {
            throw new UnexpectedValueException('A pull request is required before automatic merge.');
        }

        $view = $this->run((string) $task->worktree_path, ['gh', 'pr', 'view', $url, '--json', 'headRefOid,state,isDraft']);

        try {
            $pullRequest = $view !== null && $view->successful()
                ? json_decode($view->output(), true, flags: JSON_THROW_ON_ERROR)
                : null;
        } catch (JsonException) {
            $pullRequest = null;
        }

        if (! is_array($pullRequest) || ($pullRequest['headRefOid'] ?? null) !== $expectedSha || ($pullRequest['state'] ?? null) !== 'OPEN' || ($pullRequest['isDraft'] ?? false) === true) {
            throw new UnexpectedValueException('The pull request is not open at the expected candidate SHA.');
        }

        $result = $this->run((string) $task->worktree_path, ['gh', 'pr', 'merge', $url, '--merge', '--delete-branch']);

        if ($result === null || $result->failed()) {
            throw new UnexpectedValueException('AISF could not automatically merge the verified pull request.');
        }
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
     * Run a bounded worktree command without allowing optional Git locks.
     *
     * @param  array<int, string>  $command
     */
    private function run(string $path, array $command, int $timeout = 30, int $idleTimeout = 30): ?ProcessResult
    {
        try {
            return Process::path($path)
                ->env(['GIT_OPTIONAL_LOCKS' => '0'])
                ->timeout($timeout)
                ->idleTimeout($idleTimeout)
                ->run($command);
        } catch (Throwable) {
            return null;
        }
    }
}
