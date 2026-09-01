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
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('candidate_tree_sha', 64)->nullable()->after('candidate_sha');
            $table->foreignId('candidate_created_by_run_id')->nullable()->after('candidate_tree_sha')
                ->constrained('agent_runs')->nullOnDelete();
            $table->string('candidate_kind', 16)->nullable()->after('candidate_created_by_run_id');
            $table->string('outcome', 32)->nullable()->after('status');
            $table->unsignedInteger('protocol_recovery_count')->default(0)->after('outcome');
        });

        Schema::table('work_requests', function (Blueprint $table): void {
            $table->string('outcome', 32)->nullable()->after('status');
            $table->unsignedInteger('protocol_recovery_count')->default(0)->after('outcome');
        });

        Schema::table('candidate_reviews', function (Blueprint $table): void {
            $table->string('candidate_tree_sha', 64)->nullable()->after('candidate_sha');
            $table->index(['task_id', 'candidate_tree_sha', 'id'], 'candidate_reviews_current_tree_index');
        });

        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->string('reconciliation_status', 16)->nullable()->after('status');
            $table->string('failure_class', 32)->nullable()->after('reconciliation_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->dropColumn(['reconciliation_status', 'failure_class']);
        });

        Schema::table('candidate_reviews', function (Blueprint $table): void {
            $table->dropIndex('candidate_reviews_current_tree_index');
            $table->dropColumn('candidate_tree_sha');
        });

        Schema::table('work_requests', function (Blueprint $table): void {
            $table->dropColumn(['outcome', 'protocol_recovery_count']);
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('candidate_created_by_run_id');
            $table->dropColumn(['candidate_tree_sha', 'candidate_kind', 'outcome', 'protocol_recovery_count']);
        });
    }
};
