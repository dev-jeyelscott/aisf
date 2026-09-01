<?php

namespace App\Services;

use App\Jobs\ProcessAgentExecution;
use App\Models\Project;
use App\Models\WorkRequest;
use Illuminate\Database\QueryException;
use UnexpectedValueException;

/**
 * Normalize every WorkRequest source — a manually submitted prompt, a GitHub issue, or a Notion
 * task — into the same durable WorkRequest contract, then hand off to the unchanged PM -> Coder ->
 * QA execution workflow. External sources cannot choose an unsafe transition or bypass QA/CI: this
 * boundary only ever creates a WorkRequest exactly like a manual submission does.
 */
class WorkRequestIngestion
{
    public function __construct(
        private readonly AgentSessionManager $sessionManager,
    ) {}

    /**
     * Upsert by (project, source_type, source_external_id) so duplicate webhook deliveries or
     * repeated polling never duplicate a WorkRequest — the existing one is returned untouched.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function ingest(
        Project $project,
        string $sourceType,
        string $prompt,
        ?string $externalId = null,
        ?string $sourceUrl = null,
        array $metadata = [],
    ): WorkRequest {
        $existing = $this->findExisting($project, $sourceType, $externalId);

        if ($existing !== null) {
            return $existing;
        }

        $agents = $project->agents()
            ->whereIn('role', ['project_manager', 'coder', 'qa'])
            ->where('enabled', true)
            ->get()
            ->keyBy('role');

        foreach (['project_manager', 'coder', 'qa'] as $role) {
            if (! $agents->has($role)) {
                throw new UnexpectedValueException("An enabled {$role} Agent is required before ingesting work for this Project.");
            }
        }

        try {
            $workRequest = $project->workRequests()->create([
                'prompt' => $prompt,
                'source_type' => $sourceType,
                'source_external_id' => $externalId,
                'source_url' => $sourceUrl,
                'source_metadata' => $metadata,
            ]);
        } catch (QueryException $exception) {
            // A concurrent delivery of the same external event raced this one to the unique
            // (project, source_type, source_external_id) constraint; the existing row now wins.
            $existing = $this->findExisting($project, $sourceType, $externalId);

            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }

        foreach ($agents as $agent) {
            $this->sessionManager->forSubject($agent, $workRequest);
        }

        ProcessAgentExecution::dispatch($workRequest);

        return $workRequest;
    }

    private function findExisting(Project $project, string $sourceType, ?string $externalId): ?WorkRequest
    {
        return WorkRequest::query()
            ->where('project_id', $project->id)
            ->where('source_type', $sourceType)
            ->where('source_external_id', $externalId)
            ->first();
    }
}
