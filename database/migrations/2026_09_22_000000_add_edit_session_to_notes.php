<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('notes', function (Blueprint $table) { $table->string('edit_session', 64)->nullable(); }); }
    public function down(): void { Schema::table('notes', function (Blueprint $table) { $table->dropColumn('edit_session'); }); }
};
