<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnalyticsServiceClient
{
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = $baseUrl ?? config('services.analytics.url', 'http://localhost:8001');
    }

    /**
     * @param array $payload matches the Python service's BacktestRequest shape
     * @return array the decoded JSON response (BacktestResult shape)
     * @throws RuntimeException on a non-2xx response or connection failure
     */
    public function runBacktest(array $payload): array
    {
        $response = Http::timeout(60)->post("{$this->baseUrl}/backtest", $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                $response->json('detail') ?? "Analytics service returned HTTP {$response->status()}"
            );
        }

        return $response->json();
    }
}
