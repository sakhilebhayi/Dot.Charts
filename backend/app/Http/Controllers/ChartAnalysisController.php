<?php
namespace App\Http\Controllers;

use App\Services\EnhancedMarketDataService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ChartAnalysisController extends Controller
{
    protected $marketDataService;

    public function __construct(EnhancedMarketDataService $marketDataService)
    {
        $this->marketDataService = $marketDataService;
    }

    /**
     * Analyze chart and detect symbol
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

        // Detect symbol from image
        $symbol = $this->detectSymbolFromImage($image);

        // ...call analysis logic here...
        $analysis = [
            'signal' => 'Buy',
            'confidence' => 85,
            'trend' => 'Bullish',
            'patterns' => ['Ascending Triangle'],
            'supports' => ['48000', '47500'],
            'resistances' => ['49500', '50000'],
            'summary' => 'Comprehensive analysis...'
        ];

        return response()->json([
            'success' => true,
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
