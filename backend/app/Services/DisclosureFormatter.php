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

        $paramsStr = collect($backtestResult['params'] ?? [])
            ->map(fn ($v, $k) => "{$k}={$v}")
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

        return $attribution;
    }
}
