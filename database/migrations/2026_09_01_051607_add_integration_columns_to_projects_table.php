<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-Project GitHub/Notion ingestion configuration. Credentials are stored per Project
     * (encrypted at rest) rather than globally, since each Project owns its own repository/board.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('github_repository')->nullable();
            $table->text('github_webhook_secret')->nullable();
            $table->string('github_ready_label')->default('ai-ready');
            $table->string('notion_database_id')->nullable();
            $table->text('notion_integration_token')->nullable();
            $table->string('notion_ready_status')->default('Ready for AI');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn([
                'github_repository',
                'github_webhook_secret',
                'github_ready_label',
                'notion_database_id',
                'notion_integration_token',
                'notion_ready_status',
            ]);
        });
    }
};
