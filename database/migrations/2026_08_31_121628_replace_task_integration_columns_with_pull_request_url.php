<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Integration now opens a pull request on the Project's Git remote instead of silently
     * fast-forwarding the local branch, so the old direct-merge bookkeeping columns are unused.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['approved_at', 'commit_message', 'integrated_sha', 'integrated_at', 'worktree_cleaned_at', 'branch_deleted_at']);
            $table->string('pull_request_url')->nullable()->after('commit_sha');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('pull_request_url');
            $table->timestampTz('approved_at')->nullable();
            $table->text('commit_message')->nullable();
            $table->string('integrated_sha', 64)->nullable();
            $table->timestampTz('integrated_at')->nullable();
            $table->timestampTz('worktree_cleaned_at')->nullable();
            $table->timestampTz('branch_deleted_at')->nullable();
        });
    }
};
