<?php

namespace App\Mcp\Tools;

use App\Models\AgentRun;
use App\Models\Task;
use App\Services\TaskWorkflowService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Save structured Coder implementation evidence for the active Task run.')]
class SaveTaskResult extends Tool
{
    protected string $name = 'save_task_result';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $data = $request->validate(['task_id' => ['required', 'integer'], 'agent_run_id' => ['required', 'integer'], 'execution_token' => ['required', 'string'], 'result' => ['required', 'array']]);

        return Response::json(app(TaskWorkflowService::class)->saveResult(AgentRun::query()->whereKey($data['agent_run_id'])->sole(), Task::query()->whereKey($data['task_id'])->sole(), $data['result'], $data['execution_token']));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->integer()->required(), 'agent_run_id' => $schema->integer()->required(), 'execution_token' => $schema->string()->required(), 'result' => $schema->object()->required()];
    }
}
