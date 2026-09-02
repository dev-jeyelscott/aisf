<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add durable evidence boundaries that define the current operator repair episode.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('repair_cycle_review_boundary_id')
                ->default(0)
                ->after('protocol_recovery_count');

            $table->unsignedBigInteger('repair_cycle_handoff_boundary_id')
                ->default(0)
                ->after('repair_cycle_review_boundary_id');
        });
    }

    /**
     * Remove repair-cycle boundaries without deleting historical review or handoff evidence.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn([
                'repair_cycle_review_boundary_id',
                'repair_cycle_handoff_boundary_id',
            ]);
        });
    }
};
