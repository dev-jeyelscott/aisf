<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add Task worktree lifecycle and Coder execution status columns.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('status', 32)->default('queued')->after('depends_on_task_id');
            $table->string('base_branch')->nullable()->after('status');
            $table->string('base_sha')->nullable()->after('base_branch');
            $table->string('branch_name')->nullable()->after('base_sha');
            $table->string('worktree_path')->nullable()->after('branch_name');
            $table->text('blocked_reason')->nullable()->after('worktree_path');
        });
    }

    /**
     * Remove Task worktree lifecycle and Coder execution status columns.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'base_branch',
                'base_sha',
                'branch_name',
                'worktree_path',
                'blocked_reason',
            ]);
        });
    }
};
