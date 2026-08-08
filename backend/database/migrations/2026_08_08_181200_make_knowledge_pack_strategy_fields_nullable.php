<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_packs', function (Blueprint $table) {
            $table->dropColumn(['strategy_class', 'account_count']);
        });

        Schema::table('knowledge_packs', function (Blueprint $table) {
            $table->string('strategy_class')->nullable()->after('payload_type');
            $table->unsignedInteger('account_count')->nullable()->after('strategy_class');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_packs', function (Blueprint $table) {
            $table->dropColumn(['strategy_class', 'account_count']);
        });

        Schema::table('knowledge_packs', function (Blueprint $table) {
            $table->string('strategy_class')->after('payload_type');
            $table->unsignedInteger('account_count')->after('strategy_class');
        });
    }
};
