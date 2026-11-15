<?php

namespace App\Http\Controllers;

use App\Models\BacktestRun;
use App\Models\CustomStrategy;
use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/search?q=  the header search: the signed-in account's own backtests, journal entries and saved strategies.
 *
 * Every query is scoped to the token's user (`user_id = ?`), parameterised (LIKE with bound values, the user's own % _ and
 * the escape character are escaped), limited to LIMIT rows per group, and selects only the columns a result row needs
 * (never `results`, which can hold thousands of equity points, and never the journal body). There is no anonymous variant:
 * the route sits inside auth:sanctum, so a visitor with no token gets 401 and the page falls back to its page list.
 */
class SearchController extends Controller
{
    public const LIMIT = 5;

    /** The same names the Backtest page's own <select> uses, so "crossover" finds the ma_crossover runs. */
    private const STRATEGY_NAMES = [
        'ma_crossover' => 'MA Crossover',
        'rsi_mean_reversion' => 'RSI Mean-Reversion',
        'breakout' => 'Breakout (Donchian)',
        'bollinger_mean_reversion' => 'Bollinger Mean-Reversion',
        'momentum' => 'Momentum',
        'pairs_trading' => 'Pairs Trading (Stat-Arb)',
        'ml_signal' => 'ML Signal (Explainable)',
        'method_714' => '714 Method',
        'custom' => 'Custom Strategy',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:80'],
        ]);

        $q = (string) $validated['q'];
        $userId = $request->user('sanctum')->id;
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';

        $strategyKeys = [];
        foreach (self::STRATEGY_NAMES as $key => $name) {
            if (stripos($name, $q) !== false || stripos($key, $q) !== false) {
                $strategyKeys[] = $key;
            }
        }

        $backtests = BacktestRun::query()
            ->where('user_id', $userId)
            ->where(function ($w) use ($like, $strategyKeys) {
                $w->whereRaw("symbol LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("asset_class LIKE ? ESCAPE '!'", [$like]);
                if ($strategyKeys !== []) {
                    $w->orWhereIn('strategy', $strategyKeys);
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'symbol', 'asset_class', 'strategy', 'status', 'start_date', 'end_date', 'created_at']);

        $journal = JournalEntry::query()
            ->where('user_id', $userId)
            ->where(function ($w) use ($like) {
                $w->whereRaw("title LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("body LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("symbol LIKE ? ESCAPE '!'", [$like]);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'title', 'symbol', 'created_at']);

        $strategies = CustomStrategy::query()
            ->where('user_id', $userId)
            ->where(function ($w) use ($like) {
                $w->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("description LIKE ? ESCAPE '!'", [$like]);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'description', 'created_at']);

        return response()->json([
            'q' => $q,
            'limit' => self::LIMIT,
            'backtests' => $backtests,
            'journal' => $journal,
            'strategies' => $strategies,
        ]);
    }
}
