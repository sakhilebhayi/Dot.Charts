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

    private const REQUIRED_METRIC_NAMES = [
        'trading.strategy_mean_return_pct',
        'trading.strategy_win_rate_pct',
        'trading.strategy_max_drawdown_worst_pct',
        'trading.strategy_losing_period_pct',
    ];

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

    private function payloadFor(array $payloads, string $metricName): array
    {
        foreach ($payloads as $payload) {
            if ($payload['body']['metric_name'] === $metricName) {
                return $payload;
            }
        }

        $this->fail("No payload found for metric {$metricName}");
    }

    public function test_below_floor_returns_not_eligible(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 49; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }

        $result = $generator->buildMetricPayloads('ma_crossover', $start, $end);

        $this->assertFalse($result['eligible']);
        $this->assertSame(49, $result['account_count']);
        $this->assertNull($result['payloads']);
    }

    public function test_at_floor_produces_exactly_four_metric_payloads(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }

        $result = $generator->buildMetricPayloads('ma_crossover', $start, $end);

        $this->assertTrue($result['eligible']);
        $this->assertSame(50, $result['account_count']);
        $this->assertSame(50, $result['run_count']);
        $this->assertCount(4, $result['payloads']);
        $names = array_map(fn ($p) => $p['body']['metric_name'], $result['payloads']);
        sort($names);
        $expected = self::REQUIRED_METRIC_NAMES;
        sort($expected);
        $this->assertSame($expected, $names);
    }

    public function test_loss_honesty_metrics_present_and_correct_even_when_all_runs_win(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }

        $payloads = $generator->buildMetricPayloads('ma_crossover', $start, $end)['payloads'];

        $drawdown = $this->payloadFor($payloads, 'trading.strategy_max_drawdown_worst_pct');
        $losing = $this->payloadFor($payloads, 'trading.strategy_losing_period_pct');

        $this->assertEqualsWithDelta(-3.0, $drawdown['body']['observations'][0]['value'], 0.001);
        $this->assertEqualsWithDelta(0.0, $losing['body']['observations'][0]['value'], 0.001);
        $this->assertSame('down', $drawdown['body']['direction_of_good']);
        $this->assertSame('down', $losing['body']['direction_of_good']);
    }

    public function test_loss_honesty_metrics_computed_correctly_with_a_realistic_loss_mix(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 30; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }
        for ($i = 0; $i < 20; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', -4.0, -12.0, $start->copy()->addDays(1));
        }

        $payloads = $generator->buildMetricPayloads('ma_crossover', $start, $end)['payloads'];

        $drawdown = $this->payloadFor($payloads, 'trading.strategy_max_drawdown_worst_pct');
        $losing = $this->payloadFor($payloads, 'trading.strategy_losing_period_pct');

        $this->assertEqualsWithDelta(-12.0, $drawdown['body']['observations'][0]['value'], 0.001);
        $this->assertEqualsWithDelta(0.4, $losing['body']['observations'][0]['value'], 0.001);
    }

    public function test_anonymous_runs_are_excluded_from_both_account_count_and_metrics(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }
        for ($i = 0; $i < 10; $i++) {
            $this->completeRun(null, 'ma_crossover', 500.0, -90.0, $start->copy()->addDays(1));
        }

        $result = $generator->buildMetricPayloads('ma_crossover', $start, $end);

        $this->assertSame(50, $result['account_count']);
        $this->assertSame(50, $result['run_count']);
        $return = $this->payloadFor($result['payloads'], 'trading.strategy_mean_return_pct');
        $this->assertEqualsWithDelta(5.0, $return['body']['observations'][0]['value'], 0.001);
    }

    public function test_each_metric_payload_carries_the_strategy_class_dimension(): void
    {
        $generator = new ObservationPackGenerator();
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create();
            $this->completeRun($user, 'ma_crossover', 5.0, -3.0, $start->copy()->addDays(1));
        }

        $payloads = $generator->buildMetricPayloads('ma_crossover', $start, $end)['payloads'];

        foreach ($payloads as $payload) {
            $this->assertSame('metric', $payload['payload_type']);
            $this->assertSame(['strategy_class'], $payload['body']['dimensions']);
            $this->assertSame('ma_crossover', $payload['body']['observations'][0]['dimensions']['strategy_class']);
            $this->assertSame(50, $payload['body']['observations'][0]['sample_size']);
        }
    }
}
