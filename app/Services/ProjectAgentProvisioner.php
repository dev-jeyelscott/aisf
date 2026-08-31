<?php

namespace App\Services;

use App\Models\Project;

class ProjectAgentProvisioner
{
    /** @var array<string, array{name: string, harness: string}> */
    private const DEFAULT_AGENTS = [
        'foreman' => ['name' => 'Foreman', 'harness' => 'codex'],
        'implementation_specialist' => ['name' => 'Implementation Specialist', 'harness' => 'codex'],
        'independent_reviewer' => ['name' => 'Independent Reviewer', 'harness' => 'claude'],
    ];

    public function ensureFor(Project $project): void
    {
        foreach (self::DEFAULT_AGENTS as $role => $defaults) {
            $project->agents()->firstOrCreate(['role' => $role], $defaults + ['enabled' => true]);
        }
    }
}
