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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->unsignedInteger('position');
            $table->string('title');
            $table->text('objective');
            $table->text('implementation_spec');
            $table->json('acceptance_criteria');
            $table->json('verification_commands');
            $table->json('browser_steps');
            $table->timestamps();
            $table->unique(['work_request_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
