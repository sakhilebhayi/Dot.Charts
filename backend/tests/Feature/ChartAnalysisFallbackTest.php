<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard for the production 500: an upload with no detectable
 * symbol (and no OCR available on the host) must return the LABELED demo
 * fallback, never an exception. The original detectSymbolFromImage
 * unlink()ed a tesseract output file that was never created, so every
 * upload on the tesseract-less production host crashed before reaching
 * the fallback.
 */
class ChartAnalysisFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function tinyPngB64(): string
    {
        // 1x1 transparent PNG
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    }

    public function test_upload_without_symbol_returns_labeled_demo_not_500(): void
    {
        $response = $this->postJson('/api/chart/analyze', [
            'image' => $this->tinyPngB64(),
            'market' => 'stocks',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_demo', true);
    }

    public function test_invalid_base64_image_returns_demo_not_500(): void
    {
        $response = $this->postJson('/api/chart/analyze', [
            'image' => 'data:image/png;base64,@@not-base64@@',
            'market' => 'crypto',
        ]);

        $response->assertOk()->assertJsonPath('is_demo', true);
    }
}
