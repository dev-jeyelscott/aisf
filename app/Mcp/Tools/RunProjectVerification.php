<?php

namespace App\Mcp\Tools;

use App\Models\AgentRun;
use App\Services\ProjectVerificationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Run one operator-approved host verification profile for the active AgentRun and persist bounded durable evidence.')]
class RunProjectVerification extends Tool
{
    protected string $name = 'run_project_verification';

    /**
     * Execute one authorized verification profile without accepting executable input from the Agent.
     */
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'agent_run_id' => [
                'required',
                'integer',
            ],
            'execution_token' => [
                'required',
                'string',
            ],
            'profile' => [
                'required',
                'string',
                'max:64',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
            ],
        ]);

        $verification = app(
            ProjectVerificationService::class,
        )->run(
            AgentRun::query()
                ->whereKey($data['agent_run_id'])
                ->sole(),
            $data['execution_token'],
            $data['profile'],
            $data['idempotency_key'],
        );

        return Response::json(
            $verification->toAgentEvidence(),
        );
    }

    /**
     * Return the complete Agent-controllable MCP schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_run_id' => $schema->integer()->required(),
            'execution_token' => $schema->string()->required(),
            'profile' => $schema->string()->required(),
            'idempotency_key' => $schema->string()->required(),
        ];
    }
}
