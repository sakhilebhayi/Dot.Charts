<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_strategies', function (Blueprint $table) {
            $table->id();
            // NOT nullable, unlike backtest_runs.user_id -- a saved,
            // named strategy has no meaning without an owner to retrieve
            // it later (every custom_strategies endpoint requires login,
            // no anonymous case).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('rules'); // {"entry": {...}, "exit": {...}} -- F1's schema
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_strategies');
    }
};
