<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Http;

/**
 * Thin adapter over the Notion API so SyncNotionTasks stays testable without a real network call.
 */
class NotionClient
{
    private const API_VERSION = '2022-06-28';

    /**
     * Return every page in the Project's configured database whose status property matches the
     * configured "ready" status, normalized to the shape WorkRequestIngestion expects.
     *
     * @return array<int, array{external_id: string, prompt: string, source_url: string|null}>
     */
    public function fetchReadyPages(Project $project): array
    {
        $response = Http::withToken((string) $project->notion_integration_token)
            ->withHeaders(['Notion-Version' => self::API_VERSION])
            ->post("https://api.notion.com/v1/databases/{$project->notion_database_id}/query", [
                'filter' => [
                    'property' => 'Status',
                    'status' => ['equals' => $project->notion_ready_status],
                ],
            ]);

        if ($response->failed()) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $pages */
        $pages = (array) $response->json('results', []);
        $ready = [];

        foreach ($pages as $page) {
            $prompt = $this->extractTitle($page);

            if ($prompt === '') {
                continue;
            }

            $ready[] = [
                'external_id' => (string) ($page['id'] ?? ''),
                'prompt' => $prompt,
                'source_url' => isset($page['url']) ? (string) $page['url'] : null,
            ];
        }

        return $ready;
    }

    /** @param array<string, mixed> $page */
    private function extractTitle(array $page): string
    {
        /** @var array<string, mixed> $properties */
        $properties = (array) ($page['properties'] ?? []);

        foreach ($properties as $property) {
            if (! is_array($property) || ($property['type'] ?? null) !== 'title') {
                continue;
            }

            /** @var array<int, array<string, mixed>> $fragments */
            $fragments = (array) ($property['title'] ?? []);
            $text = '';

            foreach ($fragments as $fragment) {
                $text .= (string) ($fragment['plain_text'] ?? '');
            }

            return $text;
        }

        return '';
    }
}
