<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create one durable record for every Agent model invocation attempt.
     */
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_session_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 100);
            $table->string('status', 32);
            $table->unsignedInteger('attempt');
            $table->string('context_mode', 16);
            $table->text('submitted_input');
            $table->json('context_sources');
            $table->text('output_summary')->nullable();
            $table->string('raw_output_reference')->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_session_id', 'attempt']);
        });
    }

    /**
     * Remove persisted Agent model invocation records.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
