<?php

namespace App\Http\Controllers;

use App\Models\BacktestRun;
use App\Services\AnalyticsServiceClient;
use App\Services\DisclosureFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class BacktestController extends Controller
{
    public function __construct(
        private readonly AnalyticsServiceClient $analyticsClient,
        private readonly DisclosureFormatter $disclosureFormatter,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => 'required|string|max:20',
            'asset_class' => 'required|in:equity,crypto',
            'strategy' => 'required|in:ma_crossover,rsi_mean_reversion,method_714',
            'params' => 'nullable|array',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ]);

        $run = BacktestRun::create([
            // No auth is wired yet (see wiki.md) — user_id stays null for
            // unauthenticated requests rather than forcing a login here.
            'user_id' => $request->user()?->id,
            'symbol' => $validated['symbol'],
            'asset_class' => $validated['asset_class'],
            'strategy' => $validated['strategy'],
            'params' => $validated['params'] ?? [],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'status' => 'queued',
        ]);

        try {
            $result = $this->analyticsClient->runBacktest([
                'symbol' => $validated['symbol'],
                'asset_class' => $validated['asset_class'],
                'strategy' => $validated['strategy'],
                'params' => $validated['params'] ?? [],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ]);
        } catch (RuntimeException $e) {
            $run->update(['status' => 'failed', 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 503);
        }

        $formatted = $this->disclosureFormatter->format($result);

        $run->update(['status' => 'complete', 'results' => $formatted]);

        return response()->json([
            'success' => true,
            'backtest_run_id' => $run->id,
            'result' => $formatted,
        ]);
    }
}
