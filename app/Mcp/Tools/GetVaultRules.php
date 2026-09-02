<?php

namespace App\Mcp\Tools;

use App\Models\AgentRun;
use App\Services\VaultDocumentationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Read only the applicable Obsidian vault AGENTS.md governance for one vault-relative directory.')]
class GetVaultRules extends Tool
{
    protected string $name = 'get_vault_rules';

    /**
     * Validate the active AgentRun authorization and return only applicable
     * vault governance for the requested relative directory.
     */
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'directory' => ['required', 'string', 'max:4096'],
            'agent_run_id' => ['required', 'integer'],
            'execution_token' => ['required', 'string'],
        ]);

        return Response::json(
            app(VaultDocumentationService::class)->rulesForDirectory(
                (string) $data['directory'],
                AgentRun::query()
                    ->whereKey($data['agent_run_id'])
                    ->sole(),
                (string) $data['execution_token'],
            ),
        );
    }

    /**
     * Define the narrow input contract for governance-only vault access.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'directory' => $schema
                ->string()
                ->description(
                    'A directory relative to the configured Obsidian vault. Use "." for the vault root.',
                )
                ->required(),
            'agent_run_id' => $schema
                ->integer()
                ->required(),
            'execution_token' => $schema
                ->string()
                ->required(),
        ];
    }
}
