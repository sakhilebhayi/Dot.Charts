<?php
// ...existing namespace and imports...
namespace App\Http\Controllers;

use App\Services\EnhancedMarketDataService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EnhancedMarketDataController extends Controller
{
    // ...existing properties and methods...

    /**
     * Detect symbol from chart image using OCR
     * @param string $base64Image
     * @return string|null
     */
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
