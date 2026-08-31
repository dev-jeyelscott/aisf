<?php

namespace App\Models;

use Database\Factories\WorkRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['prompt', 'status', 'summary', 'evidence', 'failure_reason'])]
class WorkRequest extends Model
{
    /** @use HasFactory<WorkRequestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position');
    }
}
