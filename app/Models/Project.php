<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'description', 'path', 'enabled', 'merge_policy', 'github_repository', 'github_webhook_secret', 'github_ready_label', 'notion_database_id', 'notion_integration_token', 'notion_ready_status'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Projects do not persist conventional created_at or updated_at timestamps.
     */
    public $timestamps = false;

    /**
     * Cast persisted Project state to application types. Integration credentials are encrypted at rest.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'github_webhook_secret' => 'encrypted',
            'notion_integration_token' => 'encrypted',
        ];
    }

    /**
     * Return configured Agents that belong to this Project.
     *
     * @return HasMany<ProjectAgent, $this>
     */
    public function agents(): HasMany
    {
        return $this->hasMany(ProjectAgent::class);
    }

    /**
     * Return reusable Skills that belong to this Project.
     *
     * @return HasMany<ProjectSkill, $this>
     */
    public function skills(): HasMany
    {
        return $this->hasMany(ProjectSkill::class);
    }

    /**
     * Return Project WorkRequests with the newest requests first.
     *
     * @return HasMany<WorkRequest, $this>
     */
    public function workRequests(): HasMany
    {
        return $this->hasMany(WorkRequest::class)->latest();
    }
}
