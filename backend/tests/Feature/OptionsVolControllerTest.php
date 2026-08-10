<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OptionsVolControllerTest extends TestCase
{
    private function fakeAnalyticsResponse(): array
    {
        return [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'spot' => 150.0,
            'expiry_used' => '2026-09-11',
            'realized_vol' => ['current_annualized_pct' => 30.0, 'rank_pct' => 70.0, 'window_days' => 20],
            'skew' => ['call_strike' => 150.0, 'call_iv' => 0.25, 'put_strike' => 150.0, 'put_iv' => 0.28, 'skew' => 0.03],
            'vol_regime' => 'normal',
            'skew_regime' => 'balanced',
            'as_of' => '2026-08-10T00:00:00+00:00',
        ];
    }

    public function test_show_returns_vol_signal_with_disclosure(): void
    {
        Http::fake([
            '*/options/vol-signal/*' => Http::response($this->fakeAnalyticsResponse(), 200),
        ]);

        $response = $this->getJson('/api/options/vol-signal/AAPL');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('result.symbol', 'AAPL');
        $response->assertJsonPath('result.vol_regime', 'normal');
        $response->assertJsonStructure([
            'success',
            'result' => [
                'symbol', 'asset_class', 'spot', 'expiry_used', 'realized_vol', 'skew',
                'vol_regime', 'skew_regime', 'as_of',
                'disclosure' => ['attribution', 'risk_disclosure'],
            ],
        ]);
        $response->assertJsonPath('result.disclosure.attribution', function ($attribution) {
            return str_contains($attribution, 'proxy for true IV rank');
        });
    }

    public function test_show_defaults_asset_class_to_equity(): void
    {
        Http::fake([
            '*/options/vol-signal/*' => Http::response($this->fakeAnalyticsResponse(), 200),
        ]);

        $this->getJson('/api/options/vol-signal/AAPL')->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/options/vol-signal/AAPL')
                && $request['asset_class'] === 'equity';
        });
    }

    public function test_show_rejects_invalid_asset_class(): void
    {
        $response = $this->getJson('/api/options/vol-signal/AAPL?asset_class=not-a-real-class');

        $response->assertStatus(422);
    }

    public function test_show_returns_503_when_analytics_service_fails(): void
    {
        Http::fake([
            '*/options/vol-signal/*' => Http::response(['detail' => "No options chain available for symbol 'BADSYMBOL'"], 422),
        ]);

        $response = $this->getJson('/api/options/vol-signal/BADSYMBOL');

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error', function ($error) {
            return str_contains($error, 'BADSYMBOL');
        });
    }
}
