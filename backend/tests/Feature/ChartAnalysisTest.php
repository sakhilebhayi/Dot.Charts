<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChartAnalysisTest extends TestCase
{
    /**
     * A minimal 1x1 transparent PNG, base64-encoded, used as a stand-in
     * "chart image" — the endpoint under test does not require a real
     * chart, only a decodable image payload.
     */
    private const TINY_PNG_BASE64 =
        'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function test_analyze_chart_returns_labeled_placeholder_result(): void
    {
        $response = $this->postJson('/api/chart/analyze', [
            'image' => self::TINY_PNG_BASE64,
            'market' => 'crypto',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'is_demo' => true,
            'market' => 'crypto',
        ]);
        $response->assertJsonStructure([
            'success',
            'is_demo',
            'disclaimer',
            'analysis' => [
                'signal',
                'confidence',
                'trend',
                'patterns',
                'supports',
                'resistances',
                'summary',
            ],
            'symbol_detected',
            'market',
        ]);

        // The endpoint does not implement real analysis yet (see wiki.md
        // §8 roadmap) — every response must say so rather than silently
        // presenting the fixed payload as a real signal.
        $this->assertNotEmpty($response->json('disclaimer'));
    }

    public function test_analyze_chart_requires_image_and_market(): void
    {
        $response = $this->postJson('/api/chart/analyze', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['image', 'market']);
    }

    public function test_analyze_chart_rejects_unknown_market(): void
    {
        $response = $this->postJson('/api/chart/analyze', [
            'image' => self::TINY_PNG_BASE64,
            'market' => 'commodities',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['market']);
    }

    public function test_chart_analyze_is_rate_limited_at_ten_per_hour(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/chart/analyze', [
                'image' => self::TINY_PNG_BASE64,
                'market' => 'crypto',
            ]);
            $this->assertNotEquals(429, $response->status());
        }

        $response = $this->postJson('/api/chart/analyze', [
            'image' => self::TINY_PNG_BASE64,
            'market' => 'crypto',
        ]);

        $response->assertStatus(429);
    }

    public function test_analyze_chart_with_symbol_override_returns_real_analysis(): void
    {
        Http::fake([
            '*/chart-analysis' => Http::response([
                'signal' => 'Buy',
                'confidence' => 80,
                'trend' => 'Bullish',
                'patterns' => ['Bullish Break of Structure'],
                'supports' => ['148.20', '145.10'],
                'resistances' => ['152.30', '155.00'],
                'summary' => 'Bullish trend with bullish structure on AAPL (1d).',
            ], 200),
        ]);

        $response = $this->postJson('/api/chart/analyze', [
            'image' => self::TINY_PNG_BASE64,
            'market' => 'stocks',
            'symbol' => 'AAPL',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'is_demo' => false,
            'symbol_detected' => 'AAPL',
        ]);
        $response->assertJsonPath('analysis.signal', 'Buy');
        $response->assertJsonPath('analysis.trend', 'Bullish');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/chart-analysis')
                && $request['symbol'] === 'AAPL'
                && $request['asset_class'] === 'equity';
        });
    }

    public function test_analyze_chart_falls_back_to_placeholder_when_analytics_service_fails(): void
    {
        Http::fake([
            '*/chart-analysis' => Http::response(['detail' => 'No equity data for symbol \'BADSYMBOL\''], 422),
        ]);

        $response = $this->postJson('/api/chart/analyze', [
            'image' => self::TINY_PNG_BASE64,
            'market' => 'stocks',
            'symbol' => 'BADSYMBOL',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'is_demo' => true,
        ]);
        $response->assertJsonPath('analysis.summary', 'Placeholder analysis — not computed from the uploaded chart or live market data.');
    }
}
