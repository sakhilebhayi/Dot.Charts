<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsServiceClient;
use App\Services\DisclosureFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class OptionsVolController extends Controller
{
    public function __construct(
        private readonly AnalyticsServiceClient $analyticsClient,
        private readonly DisclosureFormatter $disclosureFormatter,
    ) {}

    /**
     * A current-state volatility-regime read (realized-vol rank proxy +
     * put-call skew), not a backtest -- deliberately outside the
     * /backtests family, same category as ChartAnalysisController. See
     * docs/superpowers/specs/2026-08-10-options-vol-strategy-design.md
     * for the scope decision (signal read, not options-position
     * backtesting -- yfinance has no historical options-chain data to
     * backtest against).
     */
    public function show(Request $request, string $symbol): JsonResponse
    {
        $validated = $request->validate([
            'asset_class' => 'nullable|in:equity,crypto,commodity,forex',
        ]);
        $assetClass = $validated['asset_class'] ?? 'equity';

        try {
            $result = $this->analyticsClient->optionsVolSignal($symbol, $assetClass);
        } catch (RuntimeException $e) {
            // Matches BacktestController's precedent: AnalyticsServiceClient
            // doesn't preserve the upstream status code distinctly (a bad
            // symbol and a connection failure both surface as the same
            // RuntimeException), so every failure here maps to one code.
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 503);
        }

        return response()->json([
            'success' => true,
            'result' => $this->disclosureFormatter->formatVolSignal($result),
        ]);
    }
}
