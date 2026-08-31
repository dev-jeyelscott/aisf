<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'description', 'path', 'enabled'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function agents(): HasMany
    {
        return $this->hasMany(ProjectAgent::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(ProjectSkill::class);
    }

    public function workRequests(): HasMany
    {
        return $this->hasMany(WorkRequest::class)->latest();
    }
}
