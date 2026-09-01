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

#[Description('Request an idempotent, durable Task handoff from the active Agent run.')]
class HandoffTask extends Tool
{
    protected string $name = 'handoff_task';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $data = $request->validate(['task_id' => ['required', 'integer'], 'agent_run_id' => ['required', 'integer'], 'execution_token' => ['required', 'string'], 'to_role' => ['required', 'string'], 'reason' => ['required', 'string'], 'idempotency_key' => ['required', 'string'], 'payload' => ['nullable', 'array']]);
        $handoff = app(TaskWorkflowService::class)->handoff(AgentRun::query()->whereKey($data['agent_run_id'])->sole(), Task::query()->whereKey($data['task_id'])->sole(), $data['to_role'], $data['reason'], $data['idempotency_key'], $data['payload'] ?? [], $data['execution_token']);

        return Response::json(['handoff_id' => $handoff->id, 'to_role' => $handoff->toProjectAgent->role]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->integer()->required(), 'agent_run_id' => $schema->integer()->required(), 'execution_token' => $schema->string()->required(), 'to_role' => $schema->string()->required(), 'reason' => $schema->string()->required(), 'idempotency_key' => $schema->string()->required(), 'payload' => $schema->object()];
    }
}
