<?php

namespace App\Mcp\Tools;

use App\Models\AgentRun;
use App\Models\WorkRequest;
use App\Services\TaskWorkflowService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Save structured Project Manager Task plans before explicit Coder handoffs.')]
class SaveTaskPlan extends Tool
{
    protected string $name = 'save_task_plan';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'work_request_id' => ['required', 'integer'],
            'agent_run_id' => ['required', 'integer'],
            'execution_token' => ['required', 'string'],
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*.title' => ['required', 'string'],
            'tasks.*.objective' => ['nullable', 'string'],
            'tasks.*.implementation_spec' => ['nullable', 'string'],
            'tasks.*.acceptance_criteria' => ['nullable', 'array'],
            'tasks.*.verification_commands' => ['nullable', 'array'],
            'tasks.*.browser_steps' => ['nullable', 'array'],
            'tasks.*.depends_on_position' => ['nullable', 'integer', 'min:1'],
        ]);
        $tasks = app(TaskWorkflowService::class)->savePlan(
            AgentRun::query()->whereKey($data['agent_run_id'])->sole(),
            WorkRequest::query()->whereKey($data['work_request_id'])->sole(),
            $data['tasks'],
            $data['execution_token'],
        );

        return Response::json(['task_ids' => collect($tasks)->pluck('id')->all()]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'work_request_id' => $schema->integer()->required(),
            'agent_run_id' => $schema->integer()->required(),
            'execution_token' => $schema->string()->required(),
            'tasks' => $schema->array()->required(),
        ];
    }
}
