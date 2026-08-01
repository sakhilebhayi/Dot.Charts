<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ChartAnalysisController extends Controller
{
    /**
     * Analyze chart and detect symbol.
     *
     * IMPORTANT: the real technical/SMC analysis pipeline described in the
     * platform roadmap (wiki.md §8) is not built yet. This endpoint currently
     * only (a) runs OCR against the uploaded image to guess a symbol, and
     * (b) returns a fixed, non-computed placeholder analysis payload. It does
     * NOT read live market data, does NOT run any statistical/backtesting
     * service, and must never be presented to a user as a real trading
     * signal. The `is_demo`/`disclaimer` fields below exist so every
     * consumer of this endpoint (including the frontend) is forced to
     * surface that fact rather than quietly rendering fake numbers as if
     * they were real analysis.
     */
    public function analyzeChart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => 'required|string',
            'market' => 'required|in:stocks,crypto,forex',
            'additional_context' => 'nullable|string'
        ]);

        $image = $validated['image'];
        $market = $validated['market'];
        $context = $validated['additional_context'] ?? '';

        // Detect symbol from image (best-effort OCR; frequently null)
        $symbol = $this->detectSymbolFromImage($image);

        // Placeholder analysis. Not derived from the uploaded chart, live
        // market data, or any statistical/backtesting service — see the
        // docblock above. Do not wire this into anything that presents
        // itself as real trading advice without replacing this block first.
        $analysis = [
            'signal' => 'Buy',
            'confidence' => 85,
            'trend' => 'Bullish',
            'patterns' => ['Ascending Triangle'],
            'supports' => ['48000', '47500'],
            'resistances' => ['49500', '50000'],
            'summary' => 'Placeholder analysis — not computed from the uploaded chart or live market data.'
        ];

        return response()->json([
            'success' => true,
            'is_demo' => true,
            'disclaimer' => 'This is a placeholder/demo result for UI development only. It is not generated from your chart, real market data, or any trading model, and must not be used to make trading decisions.',
            'analysis' => $analysis,
            'symbol_detected' => $symbol,
            'market' => $market
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
