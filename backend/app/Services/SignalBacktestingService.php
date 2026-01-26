<?php

namespace App\Services;

class SignalBacktestingService
{
    /**
     * Backtest a signal strategy on historical price data
     * @param array $historicalPrices Array of prices (ordered oldest to newest)
     * @param array $signals Array of signals ("buy", "sell", "hold")
     * @return array Backtest results (returns, win rate, drawdown, etc)
     */
    public function backtest(array $historicalPrices, array $signals): array
    {
        $capital = 10000; // Starting capital
        $position = 0; // 0 = no position, 1 = long
        $entryPrice = 0;
        $trades = [];
        $returns = [];
        $peak = $capital;
        $maxDrawdown = 0.0;

        foreach ($signals as $i => $signal) {
            $price = $historicalPrices[$i] ?? null;
            if ($price === null) continue;

            if ($signal === 'buy' && $position === 0) {
                $position = 1;
                $entryPrice = $price;
            } elseif ($signal === 'sell' && $position === 1) {
                $profit = $price - $entryPrice;
                $returns[] = $profit / $entryPrice;
                $capital += $profit;
                $trades[] = [
                    'entry' => $entryPrice,
                    'exit' => $price,
                    'profit' => $profit
                ];
                $position = 0;
                $entryPrice = 0;
            }
            // Track drawdown
            if ($capital > $peak) $peak = $capital;
            $drawdown = ($peak - $capital) / $peak;
            if ($drawdown > $maxDrawdown) $maxDrawdown = $drawdown;
        }

        $winCount = count(array_filter($returns, function($r) { return $r > 0; }));
        $lossCount = count(array_filter($returns, function($r) { return $r <= 0; }));
        $winRate = count($returns) ? $winCount / count($returns) : 0;
        $totalReturn = array_sum($returns);

        return [
            'trades' => $trades,
            'win_rate' => round($winRate * 100, 2),
            'total_return' => round($totalReturn * 100, 2),
            'max_drawdown' => round($maxDrawdown * 100, 2),
            'num_trades' => count($trades)
        ];
    }
}
