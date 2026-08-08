<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_packs', function (Blueprint $table) {
            $table->id();
            $table->string('pack_id')->unique();
            $table->string('payload_type'); // 'observation' for now
            $table->string('strategy_class');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('account_count');
            $table->json('payload');
            $table->string('signature');
            $table->string('signing_key_version');
            $table->timestamp('created_at')->useCurrent();
            // No updated_at -- packs are immutable once generated.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_packs');
    }
};
