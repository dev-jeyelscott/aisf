<?php

namespace App\Models;

use Database\Factories\ProjectAgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['role', 'name', 'identity', 'harness', 'model', 'settings', 'default_context', 'workflow_instructions', 'enabled'])]
class ProjectAgent extends Model
{
    /** @use HasFactory<ProjectAgentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['settings' => 'array', 'enabled' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(ProjectSkill::class)->withPivot('position')->orderByPivot('position');
    }
}
