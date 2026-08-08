<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BacktestControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_persists_and_returns_disclosed_result(): void
    {
        Http::fake([
            '*/backtest' => Http::response([
                'symbol' => 'AAPL',
                'asset_class' => 'equity',
                'strategy' => 'ma_crossover',
                'params' => ['fast_window' => 20, 'slow_window' => 50],
                'start_date' => '2023-01-01',
                'end_date' => '2026-01-01',
                'metrics' => [
                    'total_return_pct' => 12.5,
                    'win_rate_pct' => 55.0,
                    'max_drawdown_pct' => -8.2,
                    'sharpe_ratio' => 1.1,
                    'trade_count' => 40,
                    'losing_trade_count' => 18,
                ],
                'equity_curve' => [['time' => '2023-01-01T00:00:00', 'equity' => 10000.0]],
                'trades' => [],
            ], 200),
        ]);

        $response = $this->postJson('/api/backtests', [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'ma_crossover',
            'params' => ['fast_window' => 20, 'slow_window' => 50],
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('result.disclosure.confidence_band', 'high');
        $response->assertJsonStructure([
            'backtest_run_id',
            'result' => [
                'disclosure' => ['confidence_band', 'attribution', 'risk_disclosure', 'max_drawdown_pct', 'losing_trade_count'],
            ],
        ]);

        $this->assertDatabaseHas('backtest_runs', [
            'symbol' => 'AAPL',
            'status' => 'complete',
        ]);
    }

    public function test_store_marks_run_failed_when_analytics_service_errors(): void
    {
        Http::fake([
            '*/backtest' => Http::response(['detail' => "No equity data for symbol 'BADSYMBOL'"], 422),
        ]);

        $response = $this->postJson('/api/backtests', [
            'symbol' => 'BADSYMBOL',
            'asset_class' => 'equity',
            'strategy' => 'ma_crossover',
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ]);

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);

        $this->assertDatabaseHas('backtest_runs', [
            'symbol' => 'BADSYMBOL',
            'status' => 'failed',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->postJson('/api/backtests', [
            'symbol' => 'AAPL',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_rejects_unknown_strategy(): void
    {
        $response = $this->postJson('/api/backtests', [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'not_a_real_strategy',
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ]);

        $response->assertStatus(422);
    }
}
