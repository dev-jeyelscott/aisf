<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow new tree-bound QA reviews to leave the legacy candidate SHA unset.
     */
    public function up(): void
    {
        Schema::table('candidate_reviews', function (Blueprint $table): void {
            $table->string('candidate_sha')->nullable()->change();
        });
    }

    /**
     * Restore the original legacy candidate SHA constraint when rollback data permits it.
     */
    public function down(): void
    {
        Schema::table('candidate_reviews', function (Blueprint $table): void {
            $table->string('candidate_sha')->nullable(false)->change();
        });
    }
};
