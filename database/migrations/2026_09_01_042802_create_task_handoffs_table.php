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
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->string('execution_token', 100)->nullable()->after('role');
            $table->unique('execution_token');
        });

        Schema::create('task_handoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_project_agent_id')->constrained('project_agents')->cascadeOnDelete();
            $table->foreignId('to_project_agent_id')->constrained('project_agents')->cascadeOnDelete();
            $table->foreignId('from_agent_run_id')->constrained('agent_runs')->cascadeOnDelete();
            $table->string('reason', 100);
            $table->json('payload')->nullable();
            $table->string('idempotency_key', 100);
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestamps();

            $table->unique(['from_agent_run_id', 'idempotency_key']);
            $table->index(['task_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_handoffs');

        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->dropUnique(['execution_token']);
            $table->dropColumn('execution_token');
        });
    }
};
