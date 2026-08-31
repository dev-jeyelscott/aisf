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
        Schema::create('project_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->string('name');
            $table->text('identity')->nullable();
            $table->string('harness');
            $table->string('model')->nullable();
            $table->json('settings')->nullable();
            $table->text('default_context')->nullable();
            $table->text('workflow_instructions')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['project_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_agents');
    }
};
