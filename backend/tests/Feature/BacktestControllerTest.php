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

    public function test_store_accepts_commodity_asset_class(): void
    {
        Http::fake([
            '*/backtest' => Http::response([
                'symbol' => 'GC=F',
                'asset_class' => 'commodity',
                'strategy' => 'ma_crossover',
                'params' => ['fast_window' => 20, 'slow_window' => 50],
                'start_date' => '2023-01-01',
                'end_date' => '2026-01-01',
                'metrics' => [
                    'total_return_pct' => 5.0,
                    'win_rate_pct' => 50.0,
                    'max_drawdown_pct' => -3.0,
                    'sharpe_ratio' => 0.9,
                    'trade_count' => 12,
                    'losing_trade_count' => 6,
                ],
                'equity_curve' => [['time' => '2023-01-01T00:00:00', 'equity' => 10000.0]],
                'trades' => [],
            ], 200),
        ]);

        $response = $this->postJson('/api/backtests', [
            'symbol' => 'GC=F',
            'asset_class' => 'commodity',
            'strategy' => 'ma_crossover',
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('result.asset_class', 'commodity');

        $this->assertDatabaseHas('backtest_runs', [
            'symbol' => 'GC=F',
            'asset_class' => 'commodity',
            'status' => 'complete',
        ]);
    }

    public function test_anonymous_backtests_are_capped_at_three_per_hour(): void
    {
        Http::fake(['*/backtest' => Http::response(['metrics' => ['trade_count' => 0]], 200)]);

        $payload = [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'ma_crossover',
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ];

        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/backtests', $payload);
            $this->assertNotEquals(429, $response->status());
        }

        $this->postJson('/api/backtests', $payload)->assertStatus(429);
    }

    public function test_authenticated_backtests_have_a_higher_limit_than_anonymous(): void
    {
        Http::fake(['*/backtest' => Http::response(['metrics' => ['trade_count' => 0]], 200)]);

        $user = \App\Models\User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $payload = [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'ma_crossover',
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ];

        // 4 requests would 429 an anonymous caller (limit is 3/hr) — an
        // authenticated user must still succeed, proving the limits differ.
        for ($i = 0; $i < 4; $i++) {
            $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/backtests', $payload);
            $this->assertNotEquals(429, $response->status());
        }
    }

    public function test_authenticated_backtest_run_is_owned_by_the_user(): void
    {
        Http::fake([
            '*/backtest' => Http::response([
                'symbol' => 'AAPL',
                'asset_class' => 'equity',
                'strategy' => 'ma_crossover',
                'params' => [],
                'start_date' => '2023-01-01',
                'end_date' => '2026-01-01',
                'metrics' => [
                    'total_return_pct' => 1.0, 'win_rate_pct' => 50.0, 'max_drawdown_pct' => -1.0,
                    'sharpe_ratio' => 0.5, 'trade_count' => 12, 'losing_trade_count' => 6,
                ],
                'equity_curve' => [],
                'trades' => [],
            ], 200),
        ]);

        $user = \App\Models\User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/backtests', [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'ma_crossover',
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ])->assertOk();

        $this->assertDatabaseHas('backtest_runs', [
            'symbol' => 'AAPL',
            'user_id' => $user->id,
        ]);
    }
}
