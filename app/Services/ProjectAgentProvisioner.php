<?php

namespace App\Services;

use App\Models\Project;

class ProjectAgentProvisioner
{
    /** @var array<string, array{name: string, harness: string}> */
    private const ROLES = [
        'project_manager' => ['name' => 'Project Manager', 'harness' => 'codex'],
        'coder' => ['name' => 'Coder', 'harness' => 'codex'],
        'quality_assurance_specialist' => ['name' => 'Quality Assurance Specialist', 'harness' => 'claude'],
    ];

    public function ensureFor(Project $project): void
    {
        foreach (self::ROLES as $role => $defaults) {
            $project->agents()->firstOrCreate(['role' => $role], $defaults + ['enabled' => true]);
        }
    }
}
