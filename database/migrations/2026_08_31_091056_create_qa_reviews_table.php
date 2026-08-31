<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create one durable QA review record per QA Agent completion for a Task.
     */
    public function up(): void
    {
        Schema::create('qa_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_session_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32);
            $table->text('summary');
            $table->json('acceptance_criteria_results');
            $table->json('verification_results');
            $table->json('browser_result');
            $table->json('findings');
            $table->timestampTz('operator_confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Remove persisted QA review records.
     */
    public function down(): void
    {
        Schema::dropIfExists('qa_reviews');
    }
};
