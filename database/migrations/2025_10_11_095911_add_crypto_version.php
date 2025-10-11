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
        // Add crypto_version if it doesn't exist
        if (!Schema::hasColumn('notes', 'crypto_version')) {
            Schema::table('notes', function (Blueprint $table) {
                // Place it after 'text' if that column exists; otherwise just add it
                try {
                    $table->string('crypto_version', 10)
                        ->default('v1')
                        ->after('text');
                } catch (\Throwable $e) {
                    // Fallback if 'text' column doesn't exist or 'after' fails on your DB
                    $table->string('crypto_version', 10)->default('v1');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
