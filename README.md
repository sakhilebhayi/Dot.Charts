<div align="center">

<img src="frontend/public/images/logo.png" alt="Dot.Charts" width="200" />

# Dot.Charts (repo: ChartSense) 📊

</div>

**AI-assisted chart analysis platform — early-stage build.** Part of the Dot Ecosystem; registered there as `dot-charts` (see [wiki.md](wiki.md) §6 for the repo/registry naming discrepancy).

> This README describes what is actually implemented today. For the full gap analysis and roadmap, see [wiki.md](wiki.md).

---

## What this actually is right now

- A Laravel 12 backend with a **single API endpoint**, `POST /api/chart/analyze`, that accepts a base64-encoded chart image and a market type.
- The endpoint runs OCR (`tesseract`, shelled out to) against the image to *attempt* to detect a ticker symbol, then returns a **fixed placeholder analysis payload** (`signal: Buy`, `confidence: 85`, hardcoded support/resistance levels, etc). It does **not** read live market data, does **not** run any statistical or backtesting service, and is not derived from the uploaded chart at all. Every response is explicitly labeled `is_demo: true` with a `disclaimer` field so consumers can't mistake it for a real signal.
- A minimal Vite frontend (plain HTML/CSS/JS, no framework) that uploads an image, calls the endpoint, and renders the response — including a visible "Demo result — not real analysis" banner.
- Several backend services exist (`EnhancedMarketDataService`, `EnhancedNewsDataService`, `StatisticalAnalysisService`, `EnhancedStatisticalAnalysisService`, `SignalBacktestingService`, `SignalErrorMetricsService`, `SignalFeedbackService`) that implement real logic — free-API market data aggregation, news/sentiment gathering, a long-only backtester, prediction-accuracy metrics — but **none of them are wired into the chart-analysis endpoint yet**. They're usable building blocks, not a connected pipeline.
- Persistence is minimal: only a standard Laravel `User` model/migration exists. Signal feedback (`SignalFeedbackService`) is stored as flat JSON on disk (`storage/signal_feedback.json`), not a database table, and isn't wired to any route today.
- There is **no** strategy builder, trading journal, authentication UI, or event/Knowledge-Pack publishing to Dot.Brain. None of that is started.

Do not treat any output from this app as real market analysis or investment advice — it isn't, yet.

---

## Project structure

```
ChartSense/
├── backend/                     # Laravel 12 API
│   ├── app/Http/Controllers/
│   │   ├── ChartAnalysisController.php       # chart image -> symbol (OCR) + placeholder analysis
│   │   └── EnhancedMarketDataController.php  # multi-source market data endpoints
│   ├── app/Services/                          # market data, news, statistics, backtesting, feedback
│   ├── app/Models/User.php                    # only persisted entity today
│   ├── database/migrations/                   # users, cache, jobs — no domain tables yet
│   ├── routes/api.php                         # POST /api/chart/analyze
│   └── tests/                                 # PHPUnit unit + feature tests
├── frontend/                     # Vite scaffold (index.html, src/main.js, src/style.css)
│   └── public/                   # logo + favicons
└── docs/                         # FREE_APIS_DOCUMENTATION.md, migration notes
```

---

## Running it locally

### Backend (Laravel)
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```
Backend runs at `http://localhost:8000`.

### Frontend (Vite)
```bash
cd frontend
npm install
npm run dev
```
Frontend runs at `http://localhost:3000` and calls the backend directly at `http://localhost:8000/api/chart/analyze` (no dev proxy is configured).

### Tests
```bash
cd backend
php artisan test
```
Covers the placeholder-labeling behavior of the chart-analysis endpoint and the pure-logic services (backtesting, error metrics, feedback storage).

---

## Market data services (implemented, not yet wired to analysis output)

`EnhancedMarketDataController` exposes market-data endpoints backed by free, keyless public APIs — CoinGecko, Binance, Coinpaprika, CoinCap, ExchangeRate.host, Frankfurter, and Reddit/WallStreetBets sentiment. No paid data vendor is integrated, and no data-quality/uptime SLA is defined. See `docs/FREE_APIS_DOCUMENTATION.md` for the endpoint reference.

---

## Known gaps (see wiki.md for the full list)

- `ChartAnalysisController` returns a hardcoded payload instead of real technical/SMC analysis.
- No domain persistence beyond `User` — signals, strategies, and backtests are computed in-memory or file-backed, never stored as queryable history.
- No strategy builder, trading journal, or portfolio/position tracking (and per the ecosystem compliance posture, positions/orders should never be persisted here — see wiki.md §7).
- No event emission or Dot.Brain Knowledge Pack publishing.
- Repo is named `ChartSense`; Dot.Brain's registry calls this platform `dot-charts` — open naming discrepancy, not resolved in this repo.

---

## License

MIT License — see [LICENSE](LICENSE).
