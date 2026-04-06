<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            if (!Schema::hasColumn('notes', 'pinned')) {
                $table->boolean('pinned')->default(false)->after('deleted');
            }

            if (!Schema::hasColumn('notes', 'favorite')) {
                $table->boolean('favorite')->default(false)->after('pinned');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $dropColumns = [];

            if (Schema::hasColumn('notes', 'favorite')) {
                $dropColumns[] = 'favorite';
            }

            if (Schema::hasColumn('notes', 'pinned')) {
                $dropColumns[] = 'pinned';
            }

            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
