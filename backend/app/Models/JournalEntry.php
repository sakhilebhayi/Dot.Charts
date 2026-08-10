<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'body',
        'symbol',
        'backtest_run_id',
        'custom_strategy_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function backtestRun(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class);
    }

    public function customStrategy(): BelongsTo
    {
        return $this->belongsTo(CustomStrategy::class);
    }
}
