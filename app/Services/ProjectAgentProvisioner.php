<?php

namespace App\Services;

use App\Models\Project;

class ProjectAgentProvisioner
{
    /** @var array<string, array{name: string, harness: string}> */
    private const DEFAULT_AGENTS = [
        'project_manager' => ['name' => 'Project Manager', 'harness' => 'codex'],
        'coder' => ['name' => 'Coder', 'harness' => 'codex'],
        'qa' => ['name' => 'QA', 'harness' => 'claude'],
    ];

    public function ensureFor(Project $project): void
    {
        foreach (self::DEFAULT_AGENTS as $role => $defaults) {
            $project->agents()->firstOrCreate(['role' => $role], $defaults + ['enabled' => true]);
        }
    }
}
