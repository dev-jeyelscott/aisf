<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_agent_project_skill', function (Blueprint $table) {
            $table->foreignId('project_agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->unique(['project_agent_id', 'project_skill_id']);
            $table->unique(['project_agent_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_agent_project_skill');
    }
};
