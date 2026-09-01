<?php

namespace App\Mcp\Tools;

use App\Models\AgentRun;
use App\Models\Task;
use App\Services\TaskContextBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Read the current durable, project-scoped context for one Task.')]
class GetTaskContext extends Tool
{
    protected string $name = 'get_task_context';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $data = $request->validate(['task_id' => ['required', 'integer'], 'agent_run_id' => ['required', 'integer'], 'execution_token' => ['required', 'string']]);

        return Response::json(app(TaskContextBuilder::class)->forTask(Task::query()->whereKey($data['task_id'])->sole(), AgentRun::query()->whereKey($data['agent_run_id'])->sole(), $data['execution_token']));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->integer()->required(), 'agent_run_id' => $schema->integer()->required(), 'execution_token' => $schema->string()->required()];
    }
}
