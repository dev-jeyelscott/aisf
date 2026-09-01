<?php

namespace App\Mcp\Tools;

use App\Models\AgentRun;
use App\Models\Task;
use App\Models\WorkRequest;
use App\Services\WorkflowOutcomeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use UnexpectedValueException;

#[Description('Record an explicit durable already-implemented or blocked workflow outcome.')]
class RecordWorkflowOutcome extends Tool
{
    protected string $name = 'record_workflow_outcome';

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'subject_type' => ['required', 'in:work_request,task'],
            'subject_id' => ['required', 'integer'],
            'agent_run_id' => ['required', 'integer'],
            'execution_token' => ['required', 'string'],
            'outcome' => ['required', 'in:already_implemented,blocked'],
            'summary' => ['required', 'string'],
            'evidence' => ['present', 'array'],
            'evidence.*' => ['string'],
        ]);

        $subject = $data['subject_type'] === 'work_request'
            ? WorkRequest::query()->whereKey($data['subject_id'])->sole()
            : Task::query()->whereKey($data['subject_id'])->sole();

        if ($subject instanceof Task && $data['outcome'] !== 'blocked') {
            throw new UnexpectedValueException('Tasks may only use this tool to record a blocked outcome.');
        }

        $recorded = app(WorkflowOutcomeService::class)->record(
            AgentRun::query()->whereKey($data['agent_run_id'])->sole(),
            $subject,
            $data['outcome'],
            $data['summary'],
            $data['evidence'],
            $data['execution_token'],
        );

        return Response::json([
            'subject_type' => $data['subject_type'],
            'subject_id' => $recorded->id,
            'status' => $recorded->status,
            'outcome' => $recorded->outcome,
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'subject_type' => $schema->string()->required(),
            'subject_id' => $schema->integer()->required(),
            'agent_run_id' => $schema->integer()->required(),
            'execution_token' => $schema->string()->required(),
            'outcome' => $schema->string()->required(),
            'summary' => $schema->string()->required(),
            'evidence' => $schema->array()->required(),
        ];
    }
}
