<?php
namespace App\Http\Controllers;

use App\Services\AnalyticsServiceClient;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ChartAnalysisController extends Controller
{
    private const MARKET_TO_ASSET_CLASS = [
        'stocks' => 'equity',
        'crypto' => 'crypto',
        'forex' => 'forex',
    ];

    public function __construct(
        private readonly AnalyticsServiceClient $analyticsClient,
    ) {
    }

    /**
     * Analyze chart and detect symbol.
     *
     * Real analysis: when a symbol is known — either the caller supplies
     * one directly, or OCR against the uploaded image finds one — this
     * fetches real recent market data for that symbol and computes real
     * trend/structure/support-resistance analysis (see
     * analytics/analysis/chart_analysis.py). When no symbol is known, or
     * the analytics service call fails (e.g. a bad OCR guess that isn't a
     * real ticker), this falls back to a fixed, clearly-labeled placeholder
     * response — it never presents fake numbers as if they were real.
     */
    public function analyzeChart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => 'required|string',
            'market' => 'required|in:stocks,crypto,forex',
            'additional_context' => 'nullable|string',
            'symbol' => 'nullable|string|max:20',
        ]);

        $image = $validated['image'];
        $market = $validated['market'];
        $symbol = $validated['symbol'] ?? $this->detectSymbolFromImage($image);

        if ($symbol !== null) {
            $assetClass = self::MARKET_TO_ASSET_CLASS[$market];

            try {
                $analysis = $this->analyticsClient->analyzeChart([
                    'symbol' => $symbol,
                    'asset_class' => $assetClass,
                    'interval' => '1d',
                ]);

                return response()->json([
                    'success' => true,
                    'is_demo' => false,
                    'disclaimer' => 'Computed from real recent market data for the detected symbol using '
                        . 'swing-structure analysis. This is not a backtested trading strategy signal and '
                        . 'must not be used to make trading decisions.',
                    'analysis' => $analysis,
                    'symbol_detected' => $symbol,
                    'market' => $market,
                ]);
            } catch (RuntimeException) {
                // Falls through to the placeholder below — a bad OCR guess
                // or a transient analytics-service failure must not turn
                // into a hard error for the user.
            }
        }

        return $this->placeholderResponse($symbol, $market);
    }

    private function placeholderResponse(?string $symbol, string $market): JsonResponse
    {
        // Placeholder analysis. Not derived from the uploaded chart, live
        // market data, or any statistical/backtesting service. Do not wire
        // this into anything that presents itself as real trading advice
        // without replacing this block first.
        $analysis = [
            'signal' => 'Buy',
            'confidence' => 85,
            'trend' => 'Bullish',
            'patterns' => ['Ascending Triangle'],
            'supports' => ['48000', '47500'],
            'resistances' => ['49500', '50000'],
            'summary' => 'Placeholder analysis — not computed from the uploaded chart or live market data.',
        ];

        return response()->json([
            'success' => true,
            'is_demo' => true,
            'disclaimer' => 'This is a placeholder/demo result for UI development only. It is not generated from your chart, real market data, or any trading model, and must not be used to make trading decisions.',
            'analysis' => $analysis,
            'symbol_detected' => $symbol,
            'market' => $market,
        ]);
    }

    protected function detectSymbolFromImage($base64Image)
    {
        $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64Image));
        $tmpFile = tempnam(sys_get_temp_dir(), 'chart_');
        file_put_contents($tmpFile, $imageData);
        $outputFile = $tmpFile . '_out';
        $cmd = "tesseract $tmpFile $outputFile -l eng --oem 1 --psm 6";
        exec($cmd);
        $text = @file_get_contents($outputFile . '.txt');
        unlink($tmpFile);
        unlink($outputFile . '.txt');
        if (!$text) return null;
        if (preg_match('/\b([A-Z]{2,5})\b/', $text, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
