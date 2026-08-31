<?php

namespace App\Models;

use Database\Factories\ProjectSkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'description', 'instructions', 'enabled'])]
class ProjectSkill extends Model
{
    /** @use HasFactory<ProjectSkillFactory> */
    use HasFactory;

    /**
     * Cast persisted Skill state to application types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * Return the Project that owns this Skill.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return configured Agents that use this Skill.
     *
     * @return BelongsToMany<ProjectAgent, $this>
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(ProjectAgent::class)
            ->withPivot('position');
    }
}
