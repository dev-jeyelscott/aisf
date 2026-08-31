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
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('merge_policy')->default('human')->after('enabled');
        });

        Schema::create('agent_instruction_defaults', function (Blueprint $table): void {
            $table->id();
            $table->string('role')->unique();
            $table->text('instructions');
            $table->timestamps();
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('assigned_project_agent_id')->nullable()->after('work_request_id')
                ->constrained('project_agents')->nullOnDelete();
            $table->string('candidate_sha')->nullable()->after('commit_sha');
        });

        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->foreignId('parent_agent_run_id')->nullable()->after('agent_session_id')
                ->constrained('agent_runs')->nullOnDelete();
            $table->string('role')->nullable()->after('purpose');
            $table->json('agent_snapshot')->nullable()->after('context_sources');
            $table->json('prompt_snapshot')->nullable()->after('agent_snapshot');
            $table->json('execution_metadata')->nullable()->after('raw_output_reference');
            $table->json('artifacts')->nullable()->after('execution_metadata');
        });

        Schema::create('candidate_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_agent_run_id')->constrained('agent_runs')->cascadeOnDelete();
            $table->foreignId('reviewer_agent_run_id')->constrained('agent_runs')->cascadeOnDelete();
            $table->string('candidate_sha');
            $table->string('status');
            $table->text('summary');
            $table->json('findings')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'candidate_sha', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidate_reviews');

        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_agent_run_id');
            $table->dropColumn(['role', 'agent_snapshot', 'prompt_snapshot', 'execution_metadata', 'artifacts']);
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_project_agent_id');
            $table->dropColumn(['candidate_sha']);
        });

        Schema::dropIfExists('agent_instruction_defaults');

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('merge_policy');
        });
    }
};
