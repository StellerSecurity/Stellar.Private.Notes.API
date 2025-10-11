<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 0) Add crypto_version if missing (safe no-op if already present)
        if (!Schema::hasColumn('notes', 'crypto_version')) {
            Schema::table('notes', function (Blueprint $table) {
                // place after text if available
                $table->string('crypto_version', 10)->default('v1')->after('text');
            });
        }

        // 1) Ensure last_modified is UNSIGNED BIGINT (epoch ms)
        DB::statement("ALTER TABLE `notes` MODIFY COLUMN `last_modified` BIGINT UNSIGNED NOT NULL;");

        // 2) Ensure booleans have sane defaults (0)
        DB::statement("ALTER TABLE `notes` MODIFY COLUMN `protected`  TINYINT(1) NOT NULL DEFAULT 0;");
        DB::statement("ALTER TABLE `notes` MODIFY COLUMN `auto_wipe`  TINYINT(1) NOT NULL DEFAULT 0;");
        DB::statement("ALTER TABLE `notes` MODIFY COLUMN `deleted`    TINYINT(1) NOT NULL DEFAULT 0;");

        // 3) Ensure large capacity for cipher blobs (idempotent if already LONGTEXT)
        DB::statement("ALTER TABLE `notes` MODIFY COLUMN `title` LONGTEXT NOT NULL;");
        DB::statement("ALTER TABLE `notes` MODIFY COLUMN `text`  LONGTEXT NOT NULL;");

        // 4) Indexes (create only if missing)
        $hasIdx1 = DB::selectOne("
            SELECT 1 AS x FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'notes'
              AND INDEX_NAME = 'notes_user_last_modified_idx'
            LIMIT 1
        ");
        if (!$hasIdx1) {
            DB::statement("CREATE INDEX `notes_user_last_modified_idx` ON `notes` (`user_id`, `last_modified`);");
        }

        $hasIdx2 = DB::selectOne("
            SELECT 1 AS x FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'notes'
              AND INDEX_NAME = 'notes_user_note_idx'
            LIMIT 1
        ");
        if (!$hasIdx2) {
            DB::statement("CREATE INDEX `notes_user_note_idx` ON `notes` (`user_id`, `note_id`);");
        }
    }

    public function down(): void
    {
        // Best-effort rollback of indexes and crypto_version column.
        $hasIdx1 = DB::selectOne("
            SELECT 1 AS x FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'notes'
              AND INDEX_NAME = 'notes_user_last_modified_idx'
            LIMIT 1
        ");
        if ($hasIdx1) {
            DB::statement("DROP INDEX `notes_user_last_modified_idx` ON `notes`;");
        }

        $hasIdx2 = DB::selectOne("
            SELECT 1 AS x FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'notes'
              AND INDEX_NAME = 'notes_user_note_idx'
            LIMIT 1
        ");
        if ($hasIdx2) {
            DB::statement("DROP INDEX `notes_user_note_idx` ON `notes`;");
        }

        if (Schema::hasColumn('notes', 'crypto_version')) {
            Schema::table('notes', function (Blueprint $table) {
                $table->dropColumn('crypto_version');
            });
        }

        // Note: types/defaults are not reverted to avoid data loss.
        // checksum_hmac was never touched.
    }
};
