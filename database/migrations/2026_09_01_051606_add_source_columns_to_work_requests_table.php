<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normalize where a WorkRequest came from so manual submissions, GitHub issues, and Notion
     * tasks all upsert into the same durable contract by stable external identity.
     */
    public function up(): void
    {
        Schema::table('work_requests', function (Blueprint $table): void {
            $table->string('source_type')->default('manual')->after('prompt');
            $table->string('source_external_id')->nullable()->after('source_type');
            $table->string('source_url')->nullable()->after('source_external_id');
            $table->json('source_metadata')->nullable()->after('source_url');

            $table->unique(['project_id', 'source_type', 'source_external_id'], 'work_requests_source_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('work_requests', function (Blueprint $table): void {
            $table->dropUnique('work_requests_source_identity_unique');
            $table->dropColumn(['source_type', 'source_external_id', 'source_url', 'source_metadata']);
        });
    }
};
