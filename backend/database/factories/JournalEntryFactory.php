<?php

namespace Database\Factories;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class JournalEntryFactory extends Factory
{
    protected $model = JournalEntry::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => 'Reviewing the EMA crossover backtest',
            'body' => 'Noted the strategy performed better in trending conditions than choppy ones.',
            'symbol' => null,
            'backtest_run_id' => null,
            'custom_strategy_id' => null,
        ];
    }
}
