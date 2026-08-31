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

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(ProjectAgent::class)->withPivot('position');
    }
}
