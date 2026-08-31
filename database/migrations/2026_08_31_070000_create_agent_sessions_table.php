<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create durable logical Agent sessions with exactly one Task or WorkRequest subject.
     */
    public function up(): void
    {
        Schema::create('agent_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('work_request_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider_session_id')->nullable();
            $table->timestamps();

            $table->unique(['project_agent_id', 'task_id']);
            $table->unique(['project_agent_id', 'work_request_id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE agent_sessions
                ADD CONSTRAINT agent_sessions_exactly_one_subject
                CHECK ((task_id IS NULL) <> (work_request_id IS NULL))'
            );
        }
    }

    /**
     * Remove durable logical Agent sessions.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_sessions');
    }
};
