<?php

namespace App\Models;

use Database\Factories\ProjectAgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['role', 'name', 'identity', 'harness', 'model', 'settings', 'default_context', 'workflow_instructions', 'enabled'])]
class ProjectAgent extends Model
{
    /** @use HasFactory<ProjectAgentFactory> */
    use HasFactory;

    /**
     * Cast configurable Agent settings and enabled state.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['settings' => 'array', 'enabled' => 'boolean'];
    }

    /**
     * Return the Project that owns this configured Agent.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return assigned Skills in configured pivot order.
     */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(ProjectSkill::class)->withPivot('position')->orderByPivot('position');
    }

    /**
     * Return durable logical sessions owned by this configured Agent.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(AgentSession::class);
    }
}
