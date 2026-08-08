<?php

namespace Database\Factories;

use App\Models\BacktestRun;
use Illuminate\Database\Eloquent\Factories\Factory;

class BacktestRunFactory extends Factory
{
    protected $model = BacktestRun::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'ma_crossover',
            'params' => ['fast_window' => 20, 'slow_window' => 50],
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
            'status' => 'complete',
            'results' => null,
            'error' => null,
        ];
    }
}
