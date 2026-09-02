<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add operator-controlled verification profiles to each Project.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->json('verification_profiles')->nullable();
        });
    }

    /**
     * Remove Project verification profile configuration.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('verification_profiles');
        });
    }
};
