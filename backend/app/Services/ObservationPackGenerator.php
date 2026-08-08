<?php

namespace App\Services;

use App\Events\StrategyPerformanceCycleCompleted;
use App\Models\BacktestRun;
use App\Models\KnowledgePack;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class ObservationPackGenerator
{
    private const AGGREGATION_FLOOR = 50;
    private const KEY_ID = 'dot-charts-dkp-v1';

    public function __construct(private readonly DkpSigner $signer = new DkpSigner())
    {
    }

    public static function knownStrategyClasses(): array
    {
        return [
            'ma_crossover',
            'rsi_mean_reversion',
            'method_714',
            'breakout',
            'bollinger_mean_reversion',
            'custom',
        ];
    }

    /**
     * Builds the 4 loss-honesty metric payloads for a strategy class over a
     * period, WITHOUT signing or persisting. Returns:
     *   ['eligible' => bool, 'account_count' => int, 'run_count' => ?int, 'payloads' => ?array]
     * `payloads` is always exactly 4 entries when eligible -- no code path
     * omits the drawdown/losing-period metrics.
     */
    public function buildMetricPayloads(string $strategyClass, Carbon $periodStart, Carbon $periodEnd): array
    {
        $runs = BacktestRun::where('strategy', $strategyClass)
            ->where('status', 'complete')
            ->whereNotNull('user_id')
            ->whereBetween('created_at', [$periodStart->copy()->startOfDay(), $periodEnd->copy()->endOfDay()])
            ->get();

        $accountCount = $runs->pluck('user_id')->unique()->count();

        if ($accountCount < self::AGGREGATION_FLOOR) {
            return ['eligible' => false, 'account_count' => $accountCount, 'run_count' => null, 'payloads' => null];
        }

        $runCount = $runs->count();
        $returns = $runs->map(fn ($run) => (float) ($run->results['metrics']['total_return_pct'] ?? 0.0));
        $winRates = $runs->map(fn ($run) => (float) ($run->results['metrics']['win_rate_pct'] ?? 0.0));
        $drawdowns = $runs->map(fn ($run) => (float) ($run->results['metrics']['max_drawdown_pct'] ?? 0.0));
        $losingCount = $returns->filter(fn ($r) => $r < 0.0)->count();

        $observedAt = $periodEnd->copy()->endOfDay()->toIso8601String();

        $payloads = [
            $this->metricPayload(
                'trading.strategy_mean_return_pct',
                'Mean total_return_pct across all complete backtest runs for this strategy class and period, among accounts meeting the n>=50 aggregation floor',
                'percent',
                'up',
                $strategyClass,
                round($returns->avg(), 3),
                $runCount,
                $observedAt,
            ),
            $this->metricPayload(
                'trading.strategy_win_rate_pct',
                'Mean win_rate_pct across all complete backtest runs for this strategy class and period, among accounts meeting the n>=50 aggregation floor',
                'percent',
                'up',
                $strategyClass,
                round($winRates->avg(), 3),
                $runCount,
                $observedAt,
            ),
            $this->metricPayload(
                'trading.strategy_max_drawdown_worst_pct',
                'Worst single-run max_drawdown_pct across all complete backtest runs for this strategy class and period, among accounts meeting the n>=50 aggregation floor -- always published, never omitted (loss-honesty rule)',
                'percent',
                'down',
                $strategyClass,
                round($drawdowns->min(), 3),
                $runCount,
                $observedAt,
            ),
            $this->metricPayload(
                'trading.strategy_losing_period_pct',
                'Fraction of complete backtest runs with a negative total_return_pct for this strategy class and period, among accounts meeting the n>=50 aggregation floor -- always published, never omitted (loss-honesty rule)',
                'ratio',
                'down',
                $strategyClass,
                round($losingCount / $runCount, 4),
                $runCount,
                $observedAt,
            ),
        ];

        return ['eligible' => true, 'account_count' => $accountCount, 'run_count' => $runCount, 'payloads' => $payloads];
    }

    /**
     * Full generation: builds the 4 metric payloads, checks the floor,
     * assembles the signed envelope, self-verifies, and persists on
     * success. Idempotent per (strategy_class, period).
     */
    public function generateForPeriod(string $strategyClass, ?string $period = null): array
    {
        $period = $period ?? now()->subMonthNoOverflow()->format('Y-m');
        $periodStart = Carbon::parse($period . '-01')->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $existing = KnowledgePack::where('strategy_class', $strategyClass)
            ->where('payload_type', 'metric')
            ->where('period', $period)
            ->first();

        if ($existing) {
            return ['generated' => false, 'reason' => 'already_generated', 'account_count' => $existing->account_count, 'pack' => $existing];
        }

        $result = $this->buildMetricPayloads($strategyClass, $periodStart, $periodEnd);

        if (! $result['eligible']) {
            return ['generated' => false, 'reason' => 'below_floor', 'account_count' => $result['account_count'], 'pack' => null];
        }

        $packId = 'dkp:dot-charts:' . (string) Str::uuid();
        $createdAt = now();
        $confidence = min(0.9, 0.5 + max(0, $result['run_count'] - 50) * 0.001);

        $title = "Strategy performance metrics: {$strategyClass}, {$period}";
        $summary = "Aggregate return, win-rate, and loss-honesty metrics for the {$strategyClass} strategy class across {$result['account_count']} accounts in {$period}.";

        $envelope = [
            'dkp_version' => '1.0.0',
            'pack_id' => $packId,
            'pack_version' => '1.0.0',
            'platform' => 'dot-charts',
            'title' => $title,
            'summary' => $summary,
            'created_at' => $createdAt->toIso8601String(),
            'contributors' => [[
                'id' => 'chartsense-knowledge-pack-generator',
                'kind' => 'ai',
                'display_name' => 'ChartSense Knowledge Pack Generator',
                'key_id' => self::KEY_ID,
            ]],
            'payloads' => $result['payloads'],
            'provenance' => [
                'sources' => [[
                    'kind' => 'system',
                    'uri' => 'chartsense://backtest_runs',
                    'observed_at' => $periodEnd->copy()->endOfDay()->toIso8601String(),
                ]],
                'transformations' => [[
                    'step' => 'aggregate_and_sign',
                    'tool' => 'ObservationPackGenerator',
                    'tool_version' => '2.0.0',
                    'actor' => 'system',
                ]],
                'published_by' => 'dot-charts',
            ],
            'confidence' => round($confidence, 3),
            'signatures' => [],
        ];

        $envelope['signatures'] = $this->signer->sign($envelope);

        if (! $this->signer->verify($envelope)) {
            throw new RuntimeException('Generated Knowledge Pack failed self-verification -- refusing to persist an unverifiable artifact.');
        }

        $pack = KnowledgePack::create([
            'pack_id' => $packId,
            'payload_type' => 'metric',
            'strategy_class' => $strategyClass,
            'account_count' => $result['account_count'],
            'pack_version' => '1.0.0',
            'title' => $title,
            'summary' => $summary,
            'period' => $period,
            'envelope' => $envelope,
            'created_at' => $createdAt,
        ]);

        StrategyPerformanceCycleCompleted::dispatch($pack->pack_id, $strategyClass, $result['account_count']);

        return ['generated' => true, 'reason' => null, 'account_count' => $result['account_count'], 'pack' => $pack];
    }

    private function metricPayload(
        string $metricName,
        string $definition,
        string $unit,
        string $direction,
        string $strategyClass,
        float $value,
        int $sampleSize,
        string $timestamp,
    ): array {
        return [
            'payload_type' => 'metric',
            'body' => [
                'metric_name' => $metricName,
                'domain' => 'trading',
                'definition' => $definition,
                'unit' => $unit,
                'direction_of_good' => $direction,
                'dimensions' => ['strategy_class'],
                'observations' => [[
                    'timestamp' => $timestamp,
                    'value' => $value,
                    'dimensions' => ['strategy_class' => $strategyClass],
                    'sample_size' => $sampleSize,
                ]],
            ],
        ];
    }
}
