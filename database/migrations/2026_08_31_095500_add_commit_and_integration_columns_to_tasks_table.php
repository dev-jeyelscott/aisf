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
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('commit_sha', 64)->nullable()->after('approved_at');
            $table->text('commit_message')->nullable()->after('commit_sha');
            $table->string('integrated_sha', 64)->nullable()->after('commit_message');
            $table->timestampTz('integrated_at')->nullable()->after('integrated_sha');
            $table->timestampTz('worktree_cleaned_at')->nullable()->after('integrated_at');
            $table->timestampTz('branch_deleted_at')->nullable()->after('worktree_cleaned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn([
                'commit_sha',
                'commit_message',
                'integrated_sha',
                'integrated_at',
                'worktree_cleaned_at',
                'branch_deleted_at',
            ]);
        });
    }
};
