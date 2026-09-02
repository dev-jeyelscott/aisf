<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create durable evidence for individual host-controlled verification attempts.
     */
    public function up(): void
    {
        Schema::create('project_verification_runs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('agent_run_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('project_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('task_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('idempotency_key', 100);
            $table->string('profile', 64);
            $table->string('driver', 32);
            $table->string('target_type', 32);
            $table->json('command');

            $table->string('candidate_tree_sha', 64)->nullable();

            $table->string('status', 32);
            $table->integer('exit_code')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();

            $table->text('stdout')->nullable();
            $table->text('stderr')->nullable();
            $table->text('diagnostic')->nullable();

            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->unique([
                'agent_run_id',
                'idempotency_key',
            ]);

            $table->index([
                'project_id',
                'profile',
                'status',
            ]);

            $table->index([
                'task_id',
                'id',
            ]);
        });
    }

    /**
     * Remove durable Project verification evidence.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_verification_runs');
    }
};
