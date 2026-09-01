<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\NotionClient;
use App\Services\WorkRequestIngestion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

#[Signature('notion:sync')]
#[Description('Poll each configured Project\'s Notion database for ready tasks and ingest them as WorkRequests.')]
class SyncNotionTasks extends Command
{
    public function __construct(
        private readonly NotionClient $notion,
        private readonly WorkRequestIngestion $ingestion,
    ) {
        parent::__construct();
    }

    /**
     * A source-sync failure for one Project (a Notion outage, an invalid token) must never corrupt
     * or interrupt another Project's sync or any active internal Agent execution.
     */
    public function handle(): int
    {
        Project::query()
            ->where('enabled', true)
            ->whereNotNull('notion_database_id')
            ->whereNotNull('notion_integration_token')
            ->each(function (Project $project): void {
                $this->syncProject($project);
            });

        return self::SUCCESS;
    }

    private function syncProject(Project $project): void
    {
        try {
            foreach ($this->notion->fetchReadyPages($project) as $page) {
                try {
                    $this->ingestion->ingest(
                        $project,
                        'notion',
                        $page['prompt'],
                        $page['external_id'],
                        $page['source_url'],
                    );
                } catch (UnexpectedValueException $exception) {
                    Log::warning('Skipped a Notion task ingestion.', [
                        'project_id' => $project->id,
                        'page_id' => $page['external_id'],
                        'reason' => $exception->getMessage(),
                    ]);
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Notion sync failed for a Project.', [
                'project_id' => $project->id,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
