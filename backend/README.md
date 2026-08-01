# ChartSense Laravel Backend

## Setup Instructions

1. Install dependencies:
   ```bash
   composer install
   ```
2. Copy environment file:
   ```bash
   cp .env.example .env
   ```
3. Generate application key:
   ```bash
   php artisan key:generate
   ```
4. Run migrations (if needed):
   ```bash
   php artisan migrate
   ```
5. Start the server:
   ```bash
   php artisan serve
   ```

The backend will be available at http://localhost:8000

## Folder Structure
- `app/Http/Controllers`: Controllers
- `app/Models`: Models
- `app/Services`: Services
- `routes/api.php`: API routes
- `routes/web.php`: Web routes
- `public/`: Entry point
- `config/`, `database/`, `resources/`, `bootstrap/`: Standard Laravel folders
- `storage/`, `vendor/`: Generated after install
- `frontend/`: Separate frontend app
- `tests/Unit`, `tests/Feature`: PHPUnit tests

---

## What this backend actually does today

There is exactly **one** API endpoint: `POST /api/chart/analyze` (see `routes/api.php`, registered via the `api:` routing entry in `bootstrap/app.php`).

`ChartAnalysisController::analyzeChart`:
1. Validates a base64 `image` and a `market` (`stocks`, `crypto`, or `forex`).
2. Best-effort OCR's the image with the `tesseract` CLI to guess a ticker symbol (frequently returns `null` — no chart is required to "work", it just won't detect anything).
3. Returns a **fixed placeholder analysis payload** — not computed from the image, not derived from live market data, and not backed by any AI vision model. The response always includes `is_demo: true` and a `disclaimer` string so callers can't mistake it for a real signal.

No `.env` API keys are required for this endpoint — it doesn't call any external AI or paid data service. Ignore any documentation you find elsewhere in this repo (or in git history) claiming Gemini/OpenAI vision integration, `AIAgentService`, or multi-agent consensus — those describe a target architecture that was never built; no such service exists in `app/Services`.

### Services that exist but are not wired into `/api/chart/analyze`

- `EnhancedMarketDataService` / `MarketDataService` — free-API market data aggregation (CoinGecko, Binance, Coinpaprika, CoinCap, exchange rates). Used by `EnhancedMarketDataController`, not by chart analysis.
- `EnhancedNewsDataService` / `NewsDataService` — news + Reddit sentiment gathering.
- `StatisticalAnalysisService` / `EnhancedStatisticalAnalysisService` — technical/statistical computations (volatility, momentum, correlations, etc), designed to run against `EnhancedMarketDataService` output.
- `SignalBacktestingService` — a long-only backtester over a price/signal array (win rate, return, max drawdown). Pure, stateless, unit-tested.
- `SignalErrorMetricsService` — precision/recall/F1/confusion-matrix metrics for predicted-vs-actual signal sequences. Pure, stateless, unit-tested.
- `SignalFeedbackService` — writes user feedback (`accurate`/`inaccurate`/`neutral` votes on a signal) to `storage/signal_feedback.json`. Not wired to any route today.

Wiring these into a real analysis pipeline (fetch market data for the detected symbol → run statistical analysis → produce a real signal) is tracked in `wiki.md` §8 and was intentionally left out of this pass — it's a real feature, not a bug fix.

## Testing

```bash
php artisan test
```

Covers: the chart-analysis endpoint's validation and placeholder-labeling behavior, `SignalBacktestingService`, `SignalErrorMetricsService`, and `SignalFeedbackService`.

## License

MIT License - See LICENSE file for details.
