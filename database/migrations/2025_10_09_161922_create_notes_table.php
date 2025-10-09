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
        Schema::create('notes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->uuid('note_id');                 // from "id"
            $t->string('title');
            $t->longText('text');                // swap to ciphertext later if E2EE
            $t->unsignedBigInteger('last_modified'); // epoch ms from client
            $t->boolean('protected')->default(false);
            $t->boolean('auto_wipe')->default(false);
            $t->boolean('deleted')->default(false);  // tombstone for deletes
            $t->string('checksum_hmac', 128)->nullable(); // optional
            $t->timestampsTz();

            $t->unique(['user_id','note_id']);
            $t->index(['user_id','last_modified']);
        });

        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('key', 100)->unique();
            $t->timestampTz('created_at')->useCurrent();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

    }
};
