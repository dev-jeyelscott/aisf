<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create durable action evidence attributed to exact AgentRun invocations.
     */
    public function up(): void
    {
        Schema::create('agent_run_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_run_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('action', 64);
            $table->string('resource_type', 64);
            $table->unsignedBigInteger('resource_id');
            $table->timestamps();

            $table->index(['agent_run_id', 'id']);
            $table->index(['resource_type', 'resource_id']);
        });
    }

    /**
     * Remove durable AgentRun action evidence.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_run_actions');
    }
};
