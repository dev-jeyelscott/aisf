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

#[Description('Write exactly one idempotent Agent-authored Markdown work note inside the configured Obsidian vault.')]
class WriteVaultWorkLog extends Tool
{
    protected string $name = 'write_vault_work_log';

    /**
     * Validate the narrow MCP contract and delegate the authorized vault write.
     */
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'agent_run_id' => ['required', 'integer'],
            'execution_token' => ['required', 'string'],
            'relative_path' => ['required', 'string', 'max:4096'],
            'markdown' => ['required', 'string'],
        ]);

        return Response::json(
            app(VaultDocumentationService::class)->writeWorkLog(
                AgentRun::query()
                    ->whereKey($data['agent_run_id'])
                    ->sole(),
                (string) $data['execution_token'],
                (string) $data['relative_path'],
                (string) $data['markdown'],
            ),
        );
    }

    /**
     * Define the exact role-agnostic input contract for one Agent-authored work note.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_run_id' => $schema
                ->integer()
                ->description(
                    'The active AgentRun responsible for this vault work note.',
                )
                ->required(),
            'execution_token' => $schema
                ->string()
                ->description(
                    'The exact execution token issued for the active AgentRun.',
                )
                ->required(),
            'relative_path' => $schema
                ->string()
                ->description(
                    'The destination Markdown file relative to the configured Obsidian vault.',
                )
                ->required(),
            'markdown' => $schema
                ->string()
                ->description(
                    'The exact Agent-authored Markdown bytes to persist without templating or re-rendering.',
                )
                ->required(),
        ];
    }
}
