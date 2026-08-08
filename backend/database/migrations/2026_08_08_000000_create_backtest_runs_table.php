<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_runs', function (Blueprint $table) {
            $table->id();
            // Nullable: the app has no wired authentication yet (see wiki.md);
            // this deliberately does not introduce auth as part of this slice.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol');
            $table->string('asset_class');
            $table->string('strategy');
            $table->json('params');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status')->default('queued'); // queued|complete|failed
            $table->json('results')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_runs');
    }
};
