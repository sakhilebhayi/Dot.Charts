<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dkp_gate_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('direction'); // 'inbound' for this slice
            $table->string('decision'); // 'pass' or 'reject'
            $table->string('reason')->nullable();
            $table->json('matched_keywords')->nullable();
            $table->string('pack_title');
            $table->text('pack_summary');
            $table->timestamp('decided_at')->useCurrent();
            // No updated_at -- append-only, no update/delete routes ever exposed.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dkp_gate_decisions');
    }
};
