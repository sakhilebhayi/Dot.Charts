<?php

namespace App\Services;

use App\Models\BacktestRun;
use App\Models\KnowledgePack;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ObservationPackGenerator
{
    private const AGGREGATION_FLOOR = 50;

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
     * Builds the observation payload for a strategy class over a period,
     * WITHOUT signing or persisting -- that's owned by generateForPeriod().
     * Returns: ['eligible' => bool, 'account_count' => int, 'payload' => ?array]
     */
    public function buildPayload(string $strategyClass, Carbon $periodStart, Carbon $periodEnd): array
    {
        $runs = BacktestRun::where('strategy', $strategyClass)
            ->where('status', 'complete')
            ->whereNotNull('user_id') // anonymous runs never enter aggregation
            ->whereBetween('created_at', [$periodStart->copy()->startOfDay(), $periodEnd->copy()->endOfDay()])
            ->get();

        $accountCount = $runs->pluck('user_id')->unique()->count();

        if ($accountCount < self::AGGREGATION_FLOOR) {
            return ['eligible' => false, 'account_count' => $accountCount, 'payload' => null];
        }

        $returns = $runs->map(fn ($run) => (float) ($run->results['metrics']['total_return_pct'] ?? 0.0));
        $drawdowns = $runs->map(fn ($run) => (float) ($run->results['metrics']['max_drawdown_pct'] ?? 0.0));
        $losingRuns = $returns->filter(fn ($r) => $r < 0.0);

        $sortedReturns = $returns->sort()->values();
        $sortedDrawdowns = $drawdowns->sort()->values(); // ascending: most negative first

        $payload = [
            'payload_type' => 'observation',
            'strategy_class' => $strategyClass,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'account_count' => $accountCount,
            'run_count' => $runs->count(),
            'mean_return_pct' => round($returns->avg(), 3),
            'median_return_pct' => round($this->median($sortedReturns), 3),
            'win_rate_pct' => round($runs->avg(fn ($run) => (float) ($run->results['metrics']['win_rate_pct'] ?? 0.0)), 3),
            'max_drawdown_p50_pct' => round($this->median($sortedDrawdowns), 3),
            'max_drawdown_worst_pct' => round($sortedDrawdowns->first(), 3),
            'losing_period_count' => $losingRuns->count(),
            'losing_period_pct' => round($losingRuns->count() / $runs->count(), 4),
            'generated_at' => now()->toIso8601String(),
        ];

        return ['eligible' => true, 'account_count' => $accountCount, 'payload' => $payload];
    }

    private function median(Collection $sorted): float
    {
        $count = $sorted->count();
        if ($count === 0) {
            return 0.0;
        }
        $mid = intdiv($count, 2);
        if ($count % 2 === 0) {
            return ($sorted[$mid - 1] + $sorted[$mid]) / 2;
        }
        return $sorted[$mid];
    }

    /**
     * Full generation: builds the payload, checks the floor, signs and
     * persists on success. Idempotent per (strategy_class, period) --
     * re-running an already-generated period returns the existing pack's
     * reason without creating a duplicate row.
     */
    public function generateForPeriod(string $strategyClass, ?string $period = null): array
    {
        $period = $period ?? now()->subMonthNoOverflow()->format('Y-m');
        $periodStart = Carbon::parse($period . '-01')->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $existing = KnowledgePack::where('strategy_class', $strategyClass)
            ->where('payload_type', 'observation')
            ->whereDate('period_start', $periodStart->toDateString())
            ->first();

        if ($existing) {
            return ['generated' => false, 'reason' => 'already_generated', 'account_count' => $existing->account_count, 'pack' => $existing];
        }

        $result = $this->buildPayload($strategyClass, $periodStart, $periodEnd);

        if (! $result['eligible']) {
            return ['generated' => false, 'reason' => 'below_floor', 'account_count' => $result['account_count'], 'pack' => null];
        }

        $payload = $result['payload'];
        $payload['pack_id'] = $this->nextPackId($periodStart);

        $signature = $this->sign($payload);

        $pack = KnowledgePack::create([
            'pack_id' => $payload['pack_id'],
            'payload_type' => 'observation',
            'strategy_class' => $strategyClass,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'account_count' => $result['account_count'],
            'payload' => $payload,
            'signature' => $signature,
            'signing_key_version' => 'v1',
            'created_at' => now(),
        ]);

        return ['generated' => true, 'reason' => null, 'account_count' => $result['account_count'], 'pack' => $pack];
    }

    public function verify(KnowledgePack $pack): bool
    {
        return hash_equals($this->sign($pack->payload), $pack->signature);
    }

    private function sign(array $payload): string
    {
        return hash_hmac('sha256', $this->canonicalize($payload), (string) config('services.dkp.signing_key'));
    }

    private function canonicalize(array $payload): string
    {
        $this->recursiveKsort($payload);

        return json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    private function recursiveKsort(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->recursiveKsort($value);
            }
        }
    }

    private function nextPackId(Carbon $periodStart): string
    {
        $count = KnowledgePack::where('payload_type', 'observation')
            ->whereDate('period_start', $periodStart->toDateString())
            ->count();

        return sprintf('dkp:charts:obs:%s:%04d', $periodStart->toDateString(), $count + 1);
    }
}
