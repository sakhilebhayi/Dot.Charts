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

    public function test_run_backtest_handles_array_shaped_validation_detail(): void
    {
        // FastAPI's own validation errors (as opposed to our HTTPException
        // calls) return `detail` as an array of per-field error objects, not
        // a string — this must not crash building the RuntimeException.
        Http::fake([
            '*/backtest' => Http::response([
                'detail' => [
                    ['loc' => ['body', 'params'], 'msg' => 'Input should be a valid dictionary', 'type' => 'dict_type'],
                ],
            ], 422),
        ]);

        $client = new AnalyticsServiceClient('http://analytics.test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Input should be a valid dictionary');

        $client->runBacktest(['symbol' => 'X', 'params' => []]);
    }

    public function test_run_backtest_sends_empty_params_as_json_object_not_array(): void
    {
        Http::fake(['*/backtest' => Http::response(['strategy' => 'ma_crossover'], 200)]);

        $client = new AnalyticsServiceClient('http://analytics.test');
        $client->runBacktest(['symbol' => 'AAPL', 'params' => []]);

        Http::assertSent(function ($request) {
            // An empty PHP array cast to object serializes as `{}` in the
            // raw request body — `[]` would fail the analytics service's
            // dict-typed params field.
            return str_contains($request->body(), '"params":{}');
        });
    }
}
