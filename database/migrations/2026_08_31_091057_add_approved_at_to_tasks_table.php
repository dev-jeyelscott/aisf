<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the single QA approval timestamp that gates the Task's future commit step.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestampTz('approved_at')->nullable()->after('blocked_reason');
        });
    }

    /**
     * Remove the QA approval timestamp.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('approved_at');
        });
    }
};
