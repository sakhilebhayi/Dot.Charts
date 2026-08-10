<?php

namespace App\Services;

class DisclosureFormatter
{
    private const RISK_DISCLOSURE = 'Backtested performance does not guarantee future results. '
        . 'All trading involves risk of loss. This is not financial advice.';

    private const MIN_TRADES_FOR_HIGH_CONFIDENCE = 30;
    private const MIN_TRADES_FOR_MEDIUM_CONFIDENCE = 10;

    private const STRATEGY_LABELS = [
        'ma_crossover' => 'MA Crossover',
        'rsi_mean_reversion' => 'RSI Mean-Reversion',
        'method_714' => '714 Method',
        'breakout' => 'Breakout (Donchian)',
        'bollinger_mean_reversion' => 'Bollinger Mean-Reversion',
        'momentum' => 'Momentum',
        'pairs_trading' => 'Pairs Trading (Stat-Arb)',
        'ml_signal' => 'ML Signal (Explainable)',
        'custom' => 'Custom Strategy',
    ];

    /**
     * @param array $backtestResult the Python service's BacktestResult shape
     * @return array the same array plus a 'disclosure' key
     */
    public function format(array $backtestResult): array
    {
        $tradeCount = $backtestResult['metrics']['trade_count'] ?? 0;

        return array_merge($backtestResult, [
            'disclosure' => [
                'confidence_band' => $this->confidenceBand($tradeCount),
                'attribution' => $this->attribution($backtestResult),
                'risk_disclosure' => self::RISK_DISCLOSURE,
                'max_drawdown_pct' => $backtestResult['metrics']['max_drawdown_pct'] ?? null,
                'losing_trade_count' => $backtestResult['metrics']['losing_trade_count'] ?? null,
            ],
        ]);
    }

    private function confidenceBand(int $tradeCount): string
    {
        if ($tradeCount >= self::MIN_TRADES_FOR_HIGH_CONFIDENCE) {
            return 'high';
        }
        if ($tradeCount >= self::MIN_TRADES_FOR_MEDIUM_CONFIDENCE) {
            return 'medium';
        }
        return 'low';
    }

    private function attribution(array $backtestResult): string
    {
        $strategyKey = $backtestResult['strategy'] ?? 'unknown';
        $label = self::STRATEGY_LABELS[$strategyKey] ?? $strategyKey;

        // Regression: custom strategy params are nested rule objects
        // (entry/exit condition arrays), not scalars -- "{$k}={$v}" string
        // interpolation on an array value fatals with "Array to string
        // conversion". JSON-encode any non-scalar value instead of assuming
        // every strategy's params are flat key=value pairs.
        //
        // model_diagnostics (ml_signal only) is excluded here -- it's an
        // internal explainability annotation the analytics service writes
        // into params as a side channel, not a strategy hyperparameter a
        // caller set, and it gets its own formatted sentence below instead
        // of dumping as a raw JSON blob alongside lookback/threshold params.
        $paramsStr = collect($backtestResult['params'] ?? [])
            ->except('model_diagnostics')
            ->map(fn ($v, $k) => is_scalar($v) ? "{$k}={$v}" : "{$k}=" . json_encode($v))
            ->implode(', ');

        $attribution = sprintf(
            '%s (%s), backtested %s to %s on %s',
            $label,
            $paramsStr ?: 'default params',
            $backtestResult['start_date'] ?? '?',
            $backtestResult['end_date'] ?? '?',
            $backtestResult['symbol'] ?? '?'
        );

        if ($strategyKey === 'method_714') {
            $attribution .= '. Original session-based implementation (Blupin/Infodot ORD Session '
                . 'Strategy) — not a verified reproduction of Mashaya A. Mthethwa\'s proprietary '
                . '714 course material.';
        }

        if ($strategyKey === 'pairs_trading') {
            $symbolB = $backtestResult['params']['symbol_b'] ?? '?';
            $attribution .= sprintf(
                ' vs. %s. Metrics describe the cointegrated spread traded as a single synthetic '
                . 'instrument, not two separately filled and financed legs — this is a backtest of '
                . 'the signal, not broker-accurate two-leg execution accounting.',
                $symbolB
            );
        }

        if ($strategyKey === 'ml_signal') {
            $diagnostics = $backtestResult['params']['model_diagnostics'] ?? null;
            if ($diagnostics) {
                $featureList = collect($diagnostics['top_features'] ?? [])
                    ->map(fn ($f) => $f['feature'])
                    ->implode(', ');
                $attribution .= sprintf(
                    ' Signal from a %s retrained every %s bars on a trailing walk-forward window '
                    . '(%s retrain cycles this run); top features by importance: %s. A prediction, '
                    . 'not a rule — treat it with the same skepticism as any other model output.',
                    $diagnostics['model_type'] ?? 'model',
                    $backtestResult['params']['retrain_every'] ?? '?',
                    $diagnostics['retrain_blocks'] ?? '?',
                    $featureList ?: 'none available'
                );
            }
        }

        return $attribution;
    }
}
