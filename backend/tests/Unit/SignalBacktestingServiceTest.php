<?php

namespace Tests\Unit;

use App\Services\SignalBacktestingService;
use PHPUnit\Framework\TestCase;

class SignalBacktestingServiceTest extends TestCase
{
    private SignalBacktestingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SignalBacktestingService();
    }

    public function test_single_winning_trade_reports_full_win_rate(): void
    {
        // Buy at 100, sell at 110 -> one winning trade.
        $prices = [100, 105, 110];
        $signals = ['buy', 'hold', 'sell'];

        $result = $this->service->backtest($prices, $signals);

        $this->assertSame(1, $result['num_trades']);
        $this->assertSame(100.0, $result['win_rate']);
        $this->assertEquals(10.0, $result['total_return']);
        $this->assertCount(1, $result['trades']);
        $this->assertSame(100, $result['trades'][0]['entry']);
        $this->assertSame(110, $result['trades'][0]['exit']);
    }

    public function test_losing_trade_reports_zero_win_rate_and_drawdown(): void
    {
        // Buy at 100, sell at 90 -> one losing trade, capital dips below its peak.
        $prices = [100, 95, 90];
        $signals = ['buy', 'hold', 'sell'];

        $result = $this->service->backtest($prices, $signals);

        $this->assertSame(1, $result['num_trades']);
        $this->assertSame(0.0, $result['win_rate']);
        $this->assertLessThan(0, $result['total_return']);
        $this->assertGreaterThan(0, $result['max_drawdown']);
    }

    public function test_no_completed_trades_returns_zeroed_summary(): void
    {
        // A buy signal with no matching sell never closes a position.
        $prices = [100, 101, 102];
        $signals = ['buy', 'hold', 'hold'];

        $result = $this->service->backtest($prices, $signals);

        $this->assertSame(0, $result['num_trades']);
        $this->assertSame(0.0, $result['win_rate']);
        $this->assertSame(0.0, $result['total_return']);
        $this->assertSame([], $result['trades']);
    }

    public function test_empty_input_does_not_error(): void
    {
        $result = $this->service->backtest([], []);

        $this->assertSame(0, $result['num_trades']);
        $this->assertSame([], $result['trades']);
    }
}
