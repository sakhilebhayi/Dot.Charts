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
        // PHP's [] is ambiguous between a JSON array and object; an empty
        // 'params' array serializes to `[]`, but the analytics service's
        // Pydantic schema requires an object (`{}`) for that field and
        // rejects `[]` with a validation error. Force object encoding so an
        // empty params payload round-trips correctly.
        if (isset($payload['params']) && is_array($payload['params'])) {
            $payload['params'] = (object) $payload['params'];
        }

        $response = Http::timeout(60)->post("{$this->baseUrl}/backtest", $payload);

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response->json();
    }

    /**
     * @param array $payload matches the Python service's ChartAnalysisRequest shape
     * @return array the decoded JSON response (signal/confidence/trend/patterns/supports/resistances/summary)
     * @throws RuntimeException on a non-2xx response or connection failure
     */
    public function analyzeChart(array $payload): array
    {
        $response = Http::timeout(30)->post("{$this->baseUrl}/chart-analysis", $payload);

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response->json();
    }

    /**
     * @return array the decoded JSON response (OptionsVolSignalResponse shape:
     *   symbol, asset_class, spot, expiry_used, realized_vol, skew, vol_regime,
     *   skew_regime, as_of)
     * @throws RuntimeException on a non-2xx response or connection failure
     */
    public function optionsVolSignal(string $symbol, string $assetClass): array
    {
        $response = Http::timeout(30)->get(
            "{$this->baseUrl}/options/vol-signal/".rawurlencode($symbol),
            ['asset_class' => $assetClass],
        );

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response->json();
    }

    /**
     * @param array $rules matches the Python service's {"entry": {...}, "exit": {...}} rule shape
     * @return array {"valid": bool, "error"?: string} -- always 200 from the analytics service,
     *   since "the rule is invalid" is itself a successfully-answered question, not a service error
     * @throws RuntimeException on a non-2xx response or connection failure (an actual infrastructure problem)
     */
    /**
     * OCR a chart screenshot into ordered ticker candidates. Short timeout:
     * OCR is a convenience on the upload path, never worth a long hang.
     */
    public function ocrSymbol(string $imageB64): array
    {
        $response = Http::timeout(25)
            ->post("{$this->baseUrl}/ocr-symbol", ['image_b64' => $imageB64]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Analytics OCR unavailable: HTTP '.$response->status());
        }

        return $response->json() ?? [];
    }

    public function validateRule(array $rules): array
    {
        $response = Http::timeout(15)->post("{$this->baseUrl}/validate-rule", ['rules' => $rules]);

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response->json();
    }

    /**
     * FastAPI's own validation errors (as opposed to our HTTPException calls,
     * which always set a string detail) return `detail` as an array of
     * per-field error objects, not a string — e.g. {"detail": [{"loc": [...],
     * "msg": "...", ...}]}. Building a RuntimeException straight from that
     * array crashes (Exception::__construct requires a string), so this
     * normalizes either shape into one message.
     */
    private function errorMessage($response): string
    {
        $detail = $response->json('detail');

        if (is_string($detail)) {
            return $detail;
        }

        if (is_array($detail)) {
            $messages = collect($detail)
                ->map(fn ($item) => is_array($item) ? ($item['msg'] ?? json_encode($item)) : $item)
                ->implode('; ');

            if ($messages !== '') {
                return $messages;
            }
        }

        return "Analytics service returned HTTP {$response->status()}";
    }
}
