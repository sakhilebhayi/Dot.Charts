<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('symbol')->nullable();
            // nullOnDelete, not cascadeOnDelete: a journal entry is a
            // reflection that should outlive the backtest/strategy it
            // references, not disappear with it.
            $table->foreignId('backtest_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('custom_strategy_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
