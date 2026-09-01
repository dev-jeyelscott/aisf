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

#[Description('Save an independent QA review for the active Task run.')]
class SaveQaReview extends Tool
{
    protected string $name = 'save_qa_review';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $data = $request->validate(['task_id' => ['required', 'integer'], 'agent_run_id' => ['required', 'integer'], 'execution_token' => ['required', 'string'], 'candidate_sha' => ['required', 'string'], 'status' => ['required', 'in:approved,changes_requested'], 'summary' => ['required', 'string'], 'findings' => ['required', 'array']]);

        return Response::json(app(TaskWorkflowService::class)->saveReview(AgentRun::query()->whereKey($data['agent_run_id'])->sole(), Task::query()->whereKey($data['task_id'])->sole(), $data['candidate_sha'], $data['status'], $data['summary'], $data['findings'], $data['execution_token'])->toArray());
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->integer()->required(), 'agent_run_id' => $schema->integer()->required(), 'execution_token' => $schema->string()->required(), 'candidate_sha' => $schema->string()->required(), 'status' => $schema->string()->required(), 'summary' => $schema->string()->required(), 'findings' => $schema->array()->required()];
    }
}
