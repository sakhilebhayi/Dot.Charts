<?php

namespace Tests\Unit;

use App\Services\AnalyticsServiceClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AnalyticsServiceClientTest extends TestCase
{
    public function test_run_backtest_returns_decoded_json_on_success(): void
    {
        Http::fake([
            '*/backtest' => Http::response(['strategy' => 'ma_crossover', 'metrics' => ['trade_count' => 5]], 200),
        ]);

        $client = new AnalyticsServiceClient('http://analytics.test');
        $result = $client->runBacktest(['symbol' => 'AAPL']);

        $this->assertSame('ma_crossover', $result['strategy']);
    }

    public function test_run_backtest_throws_on_error_response(): void
    {
        Http::fake([
            '*/backtest' => Http::response(['detail' => 'No equity data for symbol X'], 422),
        ]);

        $client = new AnalyticsServiceClient('http://analytics.test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No equity data for symbol X');

        $client->runBacktest(['symbol' => 'X']);
    }
}
