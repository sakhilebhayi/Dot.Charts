<?php

namespace Tests\Unit;

use App\Models\BacktestRun;
use App\Models\User;
use App\Services\ObservationPackGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObservationPackGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function completeRun(?User $user, string $strategy, float $totalReturnPct, float $maxDrawdownPct, Carbon $createdAt): BacktestRun
    {
        $run = BacktestRun::create([
            'user_id' => $user?->id,
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => $strategy,
            'params' => [],
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-01',
            'status' => 'complete',
            'results' => [
                'metrics' => [
                    'total_return_pct' => $totalReturnPct,
                    'win_rate_pct' => 55.0,
                    'max_drawdown_pct' => $maxDrawdownPct,
                    'trade_count' => 12,
                    'losing_trade_count' => 5,
                ],
            ],
        ]);
        $run->created_at = $createdAt;
        $run->save();

        return $run;
    }

    public function test_below_floor_returns_not_eligible(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        // 49 distinct users -- one short of the floor.
        for ($i = 0; $i < 49; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }

        $result = $generator->buildPayload('ma_crossover', $start, $end);

        $this->assertFalse($result['eligible']);
        $this->assertSame(49, $result['account_count']);
        $this->assertNull($result['payload']);
    }

    public function test_at_floor_is_eligible_and_computes_aggregates(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        // 50 distinct users, all winners, no losing runs.
        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }

        $result = $generator->buildPayload('ma_crossover', $start, $end);

        $this->assertTrue($result['eligible']);
        $this->assertSame(50, $result['account_count']);
        $this->assertSame(50, $result['payload']['run_count']);
        $this->assertEqualsWithDelta(5.0, $result['payload']['mean_return_pct'], 0.001);
        $this->assertEqualsWithDelta(5.0, $result['payload']['median_return_pct'], 0.001);
    }

    public function test_loss_honesty_fields_always_present_even_when_all_runs_win(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }

        $payload = $generator->buildPayload('ma_crossover', $start, $end)['payload'];

        $this->assertArrayHasKey('max_drawdown_p50_pct', $payload);
        $this->assertArrayHasKey('max_drawdown_worst_pct', $payload);
        $this->assertArrayHasKey('losing_period_count', $payload);
        $this->assertArrayHasKey('losing_period_pct', $payload);
        $this->assertSame(0, $payload['losing_period_count']);
        $this->assertEqualsWithDelta(0.0, $payload['losing_period_pct'], 0.001);
        $this->assertEqualsWithDelta(-3.0, $payload['max_drawdown_p50_pct'], 0.001);
    }

    public function test_loss_honesty_fields_computed_correctly_with_a_realistic_loss_mix(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        // 30 winners, 20 losers -- 40% losing_period_pct.
        for ($i = 0; $i < 30; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }
        for ($i = 0; $i < 20; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', -4.0, -12.0, $start->copy()->addDays(1));
        }

        $payload = $generator->buildPayload('ma_crossover', $start, $end)['payload'];

        $this->assertSame(20, $payload['losing_period_count']);
        $this->assertEqualsWithDelta(0.4, $payload['losing_period_pct'], 0.001);
        $this->assertEqualsWithDelta(-12.0, $payload['max_drawdown_worst_pct'], 0.001);
    }

    public function test_anonymous_runs_are_excluded_from_both_account_count_and_statistics(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }
        // 10 anonymous runs with wildly different numbers -- must not
        // affect the floor check or the aggregates at all.
        for ($i = 0; $i < 10; $i++) {
            $this->completeRun(null, 'ma_crossover', 500.0, -90.0, $start->copy()->addDays(1));
        }

        $result = $generator->buildPayload('ma_crossover', $start, $end);

        $this->assertSame(50, $result['account_count']);
        $this->assertSame(50, $result['payload']['run_count']);
        $this->assertEqualsWithDelta(5.0, $result['payload']['mean_return_pct'], 0.001);
    }

    public function test_custom_strategy_aggregates_across_all_saved_strategy_names_as_one_class(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        // All rows share strategy = 'custom' regardless of which saved
        // strategy produced them (custom_strategies is a separate table --
        // backtest_runs.strategy is just the string 'custom').
        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'custom', 5.0, -3.0, $start->copy()->addDays(1));
        }

        $result = $generator->buildPayload('custom', $start, $end);

        $this->assertTrue($result['eligible']);
        $this->assertSame(50, $result['account_count']);
    }

    public function test_only_complete_runs_within_the_period_count(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }
        // Failed run, and a run outside the period -- neither should count.
        $failedUser = User::factory()->create();
        $failed = $this->completeRun($failedUser, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        $failed->update(['status' => 'failed']);

        $outsideUser = User::factory()->create();
        $this->completeRun($outsideUser, 'ma_crossover', 5.0, -3.0, $start->copy()->subMonth());

        $result = $generator->buildPayload('ma_crossover', $start, $end);

        $this->assertSame(50, $result['account_count']);
    }
}
