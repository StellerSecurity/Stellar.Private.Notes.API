<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('folders')) {
            Schema::create('folders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->uuid('folder_id');
                $table->string('name');
                $table->unsignedBigInteger('last_modified');
                $table->boolean('deleted')->default(false);
                $table->timestampsTz();

                $table->unique(['user_id', 'folder_id']);
                $table->index(['user_id', 'last_modified']);
                $table->index(['user_id', 'name']);
            });
        }

        Schema::table('notes', function (Blueprint $table) {
            if (!Schema::hasColumn('notes', 'folder_id')) {
                $table->uuid('folder_id')->nullable()->after('favorite');
            }
            if (!Schema::hasColumn('notes', 'folder')) {
                $table->string('folder')->nullable()->after('folder_id');
            }
        });

        if (Schema::hasTable('folders') && Schema::hasTable('notes') && Schema::hasColumn('notes', 'folder')) {
            $legacyFolders = DB::table('notes')
                ->select('user_id', 'folder', DB::raw('MAX(last_modified) as last_modified'))
                ->whereNotNull('folder')
                ->where('folder', '!=', '')
                ->groupBy('user_id', 'folder')
                ->get();

            foreach ($legacyFolders as $legacyFolder) {
                $existing = DB::table('folders')
                    ->where('user_id', $legacyFolder->user_id)
                    ->where('name', $legacyFolder->folder)
                    ->first();

                $folderId = $existing?->folder_id ?? (string) Illuminate\Support\Str::uuid();

                if (!$existing) {
                    DB::table('folders')->insert([
                        'user_id' => $legacyFolder->user_id,
                        'folder_id' => $folderId,
                        'name' => $legacyFolder->folder,
                        'last_modified' => (int) $legacyFolder->last_modified,
                        'deleted' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('notes')
                    ->where('user_id', $legacyFolder->user_id)
                    ->where('folder', $legacyFolder->folder)
                    ->whereNull('folder_id')
                    ->update(['folder_id' => $folderId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $drop = [];
            if (Schema::hasColumn('notes', 'folder')) {
                $drop[] = 'folder';
            }
            if (Schema::hasColumn('notes', 'folder_id')) {
                $drop[] = 'folder_id';
            }
            if ($drop) {
                $table->dropColumn($drop);
            }
        });

        Schema::dropIfExists('folders');
    }
};
