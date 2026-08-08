<?php

namespace Tests\Unit;

use App\Models\BacktestRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacktestRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_a_backtest_run_with_json_params_and_results(): void
    {
        $run = BacktestRun::create([
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'ma_crossover',
            'params' => ['fast_window' => 20, 'slow_window' => 50],
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
            'status' => 'queued',
        ]);

        $fresh = BacktestRun::find($run->id);

        $this->assertSame('AAPL', $fresh->symbol);
        $this->assertIsArray($fresh->params);
        $this->assertSame(20, $fresh->params['fast_window']);
        $this->assertSame('queued', $fresh->status);
        $this->assertNull($fresh->results);
    }

    public function test_user_id_is_nullable(): void
    {
        $run = BacktestRun::create([
            'symbol' => 'BTC/USDT',
            'asset_class' => 'crypto',
            'strategy' => 'method_714',
            'params' => [],
            'start_date' => '2023-01-01',
            'end_date' => '2023-06-01',
            'status' => 'queued',
        ]);

        $this->assertNull($run->user_id);
    }
}
