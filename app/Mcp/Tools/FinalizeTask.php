<?php

namespace App\Mcp\Tools;

use App\Models\AgentRun;
use App\Models\Task;
use App\Services\TaskCommitIntegrator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Finalize the current approved Task candidate through verified Git and CI state.')]
class FinalizeTask extends Tool
{
    protected string $name = 'finalize_task';

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'task_id' => ['required', 'integer'],
            'agent_run_id' => ['required', 'integer'],
            'execution_token' => ['required', 'string'],
            'commit_sha' => ['nullable', 'string'],
            'summary' => ['required', 'string'],
        ]);
        $task = Task::query()->whereKey($data['task_id'])->sole();

        app(TaskCommitIntegrator::class)->finalize(
            $task,
            AgentRun::query()->whereKey($data['agent_run_id'])->sole(),
            $data['commit_sha'] ?? null,
            $data['summary'],
            $data['execution_token'],
        );

        $task->refresh();

        return Response::json([
            'task_id' => $task->id,
            'status' => $task->status,
            'outcome' => $task->outcome,
            'commit_sha' => $task->commit_sha,
            'pull_request_url' => $task->pull_request_url,
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->required(),
            'agent_run_id' => $schema->integer()->required(),
            'execution_token' => $schema->string()->required(),
            'commit_sha' => $schema->string(),
            'summary' => $schema->string()->required(),
        ];
    }
}
