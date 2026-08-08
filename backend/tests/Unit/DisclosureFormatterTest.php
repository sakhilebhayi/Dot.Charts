<?php

namespace Tests\Unit;

use App\Services\DisclosureFormatter;
use Tests\TestCase;

class DisclosureFormatterTest extends TestCase
{
    private function baseResult(int $tradeCount): array
    {
        return [
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'ma_crossover',
            'params' => ['fast_window' => 20, 'slow_window' => 50],
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
            'metrics' => [
                'total_return_pct' => 12.5,
                'win_rate_pct' => 55.0,
                'max_drawdown_pct' => -8.2,
                'sharpe_ratio' => 1.1,
                'trade_count' => $tradeCount,
                'losing_trade_count' => (int) round($tradeCount * 0.4),
            ],
            'equity_curve' => [],
            'trades' => [],
        ];
    }

    public function test_format_adds_disclosure_block_with_loss_fields_always_present(): void
    {
        $formatted = (new DisclosureFormatter())->format($this->baseResult(40));

        $this->assertArrayHasKey('disclosure', $formatted);
        $this->assertArrayHasKey('max_drawdown_pct', $formatted['disclosure']);
        $this->assertArrayHasKey('losing_trade_count', $formatted['disclosure']);
        $this->assertArrayHasKey('risk_disclosure', $formatted['disclosure']);
        $this->assertNotEmpty($formatted['disclosure']['risk_disclosure']);
    }

    public function test_confidence_band_is_low_for_few_trades(): void
    {
        $formatted = (new DisclosureFormatter())->format($this->baseResult(3));

        $this->assertSame('low', $formatted['disclosure']['confidence_band']);
    }

    public function test_confidence_band_is_medium_for_moderate_trades(): void
    {
        $formatted = (new DisclosureFormatter())->format($this->baseResult(15));

        $this->assertSame('medium', $formatted['disclosure']['confidence_band']);
    }

    public function test_confidence_band_is_high_for_many_trades(): void
    {
        $formatted = (new DisclosureFormatter())->format($this->baseResult(40));

        $this->assertSame('high', $formatted['disclosure']['confidence_band']);
    }

    public function test_attribution_names_strategy_symbol_and_params(): void
    {
        $formatted = (new DisclosureFormatter())->format($this->baseResult(40));

        $attribution = $formatted['disclosure']['attribution'];
        $this->assertStringContainsString('MA Crossover', $attribution);
        $this->assertStringContainsString('AAPL', $attribution);
        $this->assertStringContainsString('2023-01-01', $attribution);
    }

    public function test_method_714_attribution_notes_original_implementation(): void
    {
        $result = $this->baseResult(40);
        $result['strategy'] = 'method_714';
        $result['params'] = [];

        $formatted = (new DisclosureFormatter())->format($result);

        $this->assertStringContainsString('714 Method', $formatted['disclosure']['attribution']);
    }

    public function test_attribution_handles_nested_array_params_without_fataling(): void
    {
        // Regression: custom strategy params are nested rule objects
        // (entry/exit condition arrays), not flat scalars -- attribution()
        // used to fatal with "Array to string conversion" trying to
        // interpolate an array value directly into a string.
        $result = $this->baseResult(40);
        $result['strategy'] = 'custom';
        $result['params'] = [
            'entry' => ['combinator' => 'all', 'conditions' => [['left' => ['indicator' => 'ema', 'length' => 5]]]],
            'exit' => ['combinator' => 'any', 'conditions' => []],
        ];

        $formatted = (new DisclosureFormatter())->format($result);

        $this->assertStringContainsString('Custom Strategy', $formatted['disclosure']['attribution']);
    }
}
