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

    public function test_index_returns_only_the_authenticated_users_runs(): void
    {
        $user = \App\Models\User::factory()->create();
        $otherUser = \App\Models\User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        \App\Models\BacktestRun::factory()->create(['user_id' => $user->id, 'symbol' => 'AAPL']);
        \App\Models\BacktestRun::factory()->create(['user_id' => $otherUser->id, 'symbol' => 'MSFT']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/backtests');

        $response->assertOk();
        $symbols = collect($response->json('data'))->pluck('symbol');
        $this->assertTrue($symbols->contains('AAPL'));
        $this->assertFalse($symbols->contains('MSFT'));
    }

    public function test_index_filters_by_strategy_asset_class_and_status(): void
    {
        $user = \App\Models\User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        \App\Models\BacktestRun::factory()->create([
            'user_id' => $user->id, 'strategy' => 'ma_crossover', 'asset_class' => 'equity', 'status' => 'complete',
        ]);
        \App\Models\BacktestRun::factory()->create([
            'user_id' => $user->id, 'strategy' => 'method_714', 'asset_class' => 'crypto', 'status' => 'failed',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/backtests?strategy=ma_crossover&asset_class=equity&status=complete');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('ma_crossover', $response->json('data.0.strategy'));
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/backtests');

        $response->assertStatus(401);
    }

    public function test_show_returns_full_detail_for_an_owned_run(): void
    {
        $user = \App\Models\User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $run = \App\Models\BacktestRun::factory()->create(['user_id' => $user->id, 'symbol' => 'AAPL']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/backtests/{$run->id}");

        $response->assertOk();
        $response->assertJsonPath('symbol', 'AAPL');
    }

    public function test_show_returns_404_for_another_users_run(): void
    {
        $user = \App\Models\User::factory()->create();
        $otherUser = \App\Models\User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $run = \App\Models\BacktestRun::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/backtests/{$run->id}");

        $response->assertStatus(404);
    }

    public function test_show_returns_404_for_a_nonexistent_run(): void
    {
        $user = \App\Models\User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/backtests/999999');

        $response->assertStatus(404);
    }

    public function test_show_requires_authentication(): void
    {
        $run = \App\Models\BacktestRun::factory()->create();

        $response = $this->getJson("/api/backtests/{$run->id}");

        $response->assertStatus(401);
    }

    public function test_destroy_removes_an_owned_run(): void
    {
        $user = \App\Models\User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $run = \App\Models\BacktestRun::factory()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->deleteJson("/api/backtests/{$run->id}");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseMissing('backtest_runs', ['id' => $run->id]);
    }

    public function test_destroy_returns_404_for_another_users_run_and_does_not_delete_it(): void
    {
        $user = \App\Models\User::factory()->create();
        $otherUser = \App\Models\User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $run = \App\Models\BacktestRun::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->deleteJson("/api/backtests/{$run->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('backtest_runs', ['id' => $run->id]);
    }

    public function test_destroy_requires_authentication(): void
    {
        $run = \App\Models\BacktestRun::factory()->create();

        $response = $this->deleteJson("/api/backtests/{$run->id}");

        $response->assertStatus(401);
    }
}
