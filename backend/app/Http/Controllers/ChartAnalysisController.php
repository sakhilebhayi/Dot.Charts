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
        $assetClass = self::MARKET_TO_ASSET_CLASS[$market];

        // Candidate order: the caller's explicit symbol wins outright; then
        // the analytics service's OCR read of the screenshot (tried against
        // real market data, best guess first); then the legacy local
        // tesseract path for dev machines that have it.
        $candidates = [];
        if (isset($validated['symbol'])) {
            $candidates[] = $validated['symbol'];
        } else {
            try {
                $ocr = $this->analyticsClient->ocrSymbol($image);
                $candidates = array_slice($ocr['candidates'] ?? [], 0, 3);
            } catch (RuntimeException) {
                // OCR is a convenience, never a dependency.
            }
            if ($candidates === []) {
                $local = $this->detectSymbolFromImage($image);
                if ($local !== null) {
                    $candidates[] = $local;
                }
            }
        }

        foreach ($candidates as $candidate) {
            try {
                $analysis = $this->analyticsClient->analyzeChart([
                    'symbol' => $candidate,
                    'asset_class' => $assetClass,
                    'interval' => '1d',
                ]);
                $symbol = $candidate;

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

        return $this->placeholderResponse($candidates[0] ?? null, $market);
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

    /**
     * Best-effort OCR ticker detection. Returns null - never throws - when
     * OCR is unavailable or finds nothing: production hosts without a
     * tesseract binary (or with exec() disabled) must degrade to the
     * labeled placeholder path, not to a 500. The original implementation
     * unlink()ed an output file tesseract never created, which is exactly
     * what took the whole endpoint down on the shared host.
     */
    protected function detectSymbolFromImage($base64Image)
    {
        try {
            $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64Image), true);
            if ($imageData === false || $imageData === '') {
                return null;
            }
            if (! function_exists('exec')) {
                return null;
            }

            $tmpFile = tempnam(sys_get_temp_dir(), 'chart_');
            file_put_contents($tmpFile, $imageData);
            $outputFile = $tmpFile . '_out';
            @exec('tesseract ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($outputFile)
                . ' -l eng --oem 1 --psm 6 2>/dev/null');

            $textPath = $outputFile . '.txt';
            $text = is_file($textPath) ? (string) file_get_contents($textPath) : '';

            @unlink($tmpFile);
            if (is_file($textPath)) {
                @unlink($textPath);
            }

            if ($text !== '' && preg_match('/\b([A-Z]{2,5})\b/', $text, $matches)) {
                return $matches[1];
            }
        } catch (\Throwable) {
            // OCR is a convenience, never a dependency.
        }

        return null;
    }
}
