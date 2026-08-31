<?php

namespace App\Models;

use Database\Factories\AgentInstructionDefaultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['role', 'instructions'])]
class AgentInstructionDefault extends Model
{
    /** @use HasFactory<AgentInstructionDefaultFactory> */
    use HasFactory;
}
