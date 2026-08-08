<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Split into separate Schema::table() calls -- mixing drop and add
        // operations for multiple columns in one SQLite blueprint call was
        // observed to fail partway through (SQLite's ALTER TABLE support
        // for compound changes recreates the table internally; smaller,
        // separate statements are more reliable).
        Schema::table('knowledge_packs', function (Blueprint $table) {
            $table->dropColumn(['payload', 'signature', 'signing_key_version', 'period_start', 'period_end']);
        });

        Schema::table('knowledge_packs', function (Blueprint $table) {
            $table->string('pack_version')->default('1.0.0')->after('payload_type');
            $table->string('title')->after('pack_version');
            $table->text('summary')->after('title');
            $table->string('period')->after('summary'); // e.g. "2026-08" -- internal idempotency key, not part of the real envelope
            $table->json('envelope')->after('period');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_packs', function (Blueprint $table) {
            $table->dropColumn(['pack_version', 'title', 'summary', 'period', 'envelope']);
            $table->json('payload')->nullable();
            $table->string('signature')->nullable();
            $table->string('signing_key_version')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
        });
    }
};
