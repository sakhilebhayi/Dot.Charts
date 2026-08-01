---
title: Dot.Charts — Platform Wiki
version: 0.1.0
status: draft
owners: [Charts Platform Lead]
platform-id: dot-charts
last-review: 2026-08-01
---

# Dot.Charts

Purpose: this is Dot.Charts's own knowledge home — owned and maintained by the Charts platform team. It describes what this platform actually is, what it stores, and how it connects to the wider Dot Ecosystem. Dot.Brain never edits this file; it only reads what we choose to publish.

> **Related:** [Dot.Brain's ingested view of this platform](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-charts.md)

> **This wiki is architecture and product documentation only.** It describes system design, data flow, and integration surface. It does not contain, and will never contain, trading, investment, or financial advice — actual signal/strategy content lives in the product itself, gated behind the disclosures described in §7.

---

## 1. What Dot.Charts Is

Dot.Charts is the ecosystem's AI-assisted market-analysis platform: chart interpretation, technical/statistical signal generation, and (planned) strategy backtesting and trading journals across Forex, Crypto, Stocks, Commodities, and Indices. The long-term product vision — Smart Money Concepts (SMC) and ICT-style institutional analysis, a visual strategy builder, and full backtesting/journaling workflows — is described at the ecosystem level in Dot.Brain's `platforms/dot-charts.md`. This wiki describes what is actually built today and the path toward that vision.

**Status:** early build, not a blank slate. The repository (GitHub: `ChartSense`) contains a working Laravel backend and a Vite-based frontend scaffold. Market-data aggregation, a chart-image analysis endpoint, and prototype statistical/backtesting services are implemented. Persistence is minimal (a single `User` model; signal feedback is stored as flat JSON, not a database table), and there is no strategy builder, trading journal, or Knowledge Pack publishing yet. Sections below are marked **(built)** or **(planned)** so the gap is explicit.

## 2. Architecture (built)

```
ChartSense/
├── backend/                     # Laravel 11 API
│   ├── app/Http/Controllers/
│   │   ├── ChartAnalysisController.php       # chart image -> symbol + analysis
│   │   └── EnhancedMarketDataController.php  # multi-source market data endpoints
│   ├── app/Services/
│   │   ├── EnhancedMarketDataService.php     # CoinGecko/Binance/Coinpaprika/CoinCap/exchange-rate aggregation
│   │   ├── EnhancedNewsDataService.php       # news + Reddit sentiment
│   │   ├── EnhancedStatisticalAnalysisService.php / StatisticalAnalysisService.php
│   │   ├── SignalBacktestingService.php      # historical price/signal backtest (win rate, drawdown)
│   │   ├── SignalFeedbackService.php         # user feedback on signal accuracy (JSON-file store)
│   │   └── SignalErrorMetricsService.php
│   ├── app/Models/User.php                   # only persisted entity today
│   ├── database/migrations/                  # users, cache, jobs — no domain tables yet
│   └── routes/api.php                        # POST /api/chart/analyze
├── frontend/                     # Vite scaffold (src/main.js, style.css) — minimal UI, not yet wired to backend flows
└── docs/                         # FREE_APIS_DOCUMENTATION.md, migration notes
```

Notable implementation details worth flagging honestly:
- `ChartAnalysisController::analyzeChart` shells out to local `tesseract` OCR to read a symbol off an uploaded chart image, then returns a **hardcoded** placeholder analysis (`signal: Buy`, `confidence: 85`, fixed support/resistance levels) — this is scaffolding for the real analysis pipeline, not a working model yet.
- Market data comes from free, keyless public APIs (CoinGecko, Binance, Coinpaprika, CoinCap, ExchangeRate.host, Frankfurter, Reddit/WallStreetBets sentiment) — no paid data vendor integrated yet, and no data-quality/latency SLA has been defined.
- `SignalBacktestingService::backtest` is a straightforward long-only backtester over a price/signal array (win rate, total return, max drawdown) — functional for single-strategy testing, not yet a general strategy-builder engine.
- `SignalFeedbackService` writes feedback to `storage/signal_feedback.json` on disk, not a database table — fine for prototyping, not multi-tenant-safe.

## 3. Domain Entities

| Entity | State | Notes |
|---|---|---|
| User | **built** | `App\Models\User`, standard Laravel auth scaffold |
| Signal feedback | **built** (file-backed) | symbol, signal, accuracy vote, comment — `storage/signal_feedback.json`, not yet a DB table or graphed entity |
| Watchlist instrument | **planned** | instrument identity (symbol + market: forex/crypto/stocks/commodities/indices) — no model yet |
| Trading signal | **planned** | today a request/response payload only, not persisted or versioned |
| Strategy template | **planned** | no strategy-builder or rule-persistence layer exists yet |
| Backtest run | **planned** | `SignalBacktestingService` computes results in-memory per request; no run history is stored |
| Trading journal entry | **planned** | not started |
| Position / order | **out of scope, always** | Dot.Charts does not and will not hold user positions or execute trades — see §7 |

This maps onto Dot.Brain's ecosystem-level entity model (`entity:asset` for instruments/strategies, `entity:process` for signals, `outcome` for execution results) once the corresponding tables and graph publishing exist. Today only "Signal feedback" has any persistence at all.

## 4. Events Emitted

**None today.** There is no event bus, queue-based side-effect, or Knowledge Pack emission in the current codebase — `routes/api.php` exposes a single synchronous endpoint. Dot.Brain's `platforms/dot-charts.md` specifies the target event set (`trading.signal.issued/expired`, `trading.compliance.gate_rejected`, `trading.strategy.performance_cycle`); those are design targets for this platform to build toward, not current behavior. Flagging this explicitly rather than describing aspirational events as if they exist.

## 5. Connecting to Dot.Brain

Dot.Charts is registered in Dot.Brain's platform registry as `dot-charts` and is described there as the ecosystem's AI-powered trading platform, including a bidirectional compliance gate for market-relevant knowledge (the MNPI boundary — see `platforms/dot-charts.md` §7). None of that integration is wired up in this repository yet: no Knowledge Pack manifest, no publishing pipeline, no subscription to Brain recommendations.

| Payload type | Target cadence (per Dot.Brain spec) | Current status |
|---|---|---|
| `observation` (strategy/signal performance aggregates) | monthly | not implemented |
| `insight` (signal-effectiveness findings) | per finding | not implemented |
| `outcome` (recommendation verifications) | per verified recommendation | not implemented |
| `incident` (compliance events, model failures) | per incident | not implemented |

When this platform begins publishing, it inherits Dot.Brain's non-negotiable rules: platforms own their data, Dot.Brain never edits this file, and any regulated-market content must pass the compliance gate described on the Brain side before any packet crosses the boundary in either direction. Full manifest, entity/event mapping, and the compliance-gate design are maintained on the Brain side at [`platforms/dot-charts.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-charts.md) — that document is Dot.Brain's ingested view and is authoritative for integration mechanics; this wiki is authoritative for what Dot.Charts actually *is*.

## 6. Naming Discrepancy (open question)

Dot.Brain's registry and knowledge document refer to this platform as **`dot-charts`**, and this wiki keeps the "Dot.Charts" product name for ecosystem consistency. The actual GitHub repository, however, is named **`ChartSense`** (`github.com/sakhilebhayi/ChartSense`), not `Dot.Charts` or `dot-charts` — unlike sibling platforms whose repo names match their registry IDs. This is flagged here as an open item (§8) rather than silently resolved; renaming the repo, aliasing it, or updating the registry are all on the table and need an owner decision.

## 7. Compliance Posture (design intent, not yet implemented)

Per Dot.Brain's framing, Dot.Charts is the ecosystem's only regulated-market platform, which means two things this codebase does not yet honor but must before any real signal ships to users:
- Every signal shown to a user needs a confidence band, model attribution, and risk disclosure rendered alongside it — today `ChartAnalysisController` returns a bare hardcoded payload with none of that.
- User positions/orders must never be persisted or graphed, ever, even once a journal feature exists (type-level exclusion, same pattern Dot.Brain applies to HR data). No positions are stored today, which is compliant by omission rather than by design — this needs to become an explicit invariant as journal/portfolio features are built.

## 8. Roadmap / Open Questions

- [ ] Replace `ChartAnalysisController`'s hardcoded analysis with a real technical/SMC analysis pipeline
- [ ] Persist signals, strategies, and backtests as real domain tables (currently in-memory or flat-file)
- [ ] Build the strategy-builder UI/engine referenced in the ecosystem vision
- [ ] Build the trading journal, with the position/order exclusion enforced at the schema level from day one
- [ ] Define and implement the four Knowledge Pack payload types and their publishing cadence
- [ ] Wire the compliance/MNPI gate described in Dot.Brain's `platforms/dot-charts.md` §7 before any live signal or ecosystem-sourced feature ships
- [ ] Resolve the `ChartSense` vs. `dot-charts` repo-naming discrepancy (§6)
- [ ] Decide on a real market-data SLA (current APIs are free-tier, best-effort, no uptime guarantee)

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 0.1.0 | 2026-08-01 | Charts Platform Lead | Initial wiki: derived from the actual ChartSense codebase (Laravel backend + Vite frontend, market-data aggregation, chart-analysis and backtesting prototypes) and cross-referenced against Dot.Brain's `platforms/dot-charts.md` for ecosystem framing |
| 0.1.1 | 2026-08-01 | Charts Platform Lead | Engineering-quality pass: fixed `routes/api.php` never being registered (missing `api:` entry in `bootstrap/app.php` — the endpoint didn't exist at all in a real request cycle); removed a stray duplicate-class file (`DetectSymbolTrait.php`) that redeclared `EnhancedMarketDataController`; labeled the hardcoded chart-analysis response as a demo (`is_demo`/`disclaimer` fields, matching UI banner) rather than fixing the "hardcoded results" bug by fabricating real analysis; added the first PHPUnit tests in the repo (`tests/Unit`, `tests/Feature` — previously didn't exist despite `phpunit.xml` referencing them); rewrote root and backend README to drop aspirational/fictional claims (Gemini/GPT-4 Vision, `AIAgentService`) that didn't match the code; removed `downloads/` (stale doc referencing a build archive that isn't in the repo); added real logo/favicons; fixed `composer.json`'s leftover `laravel/laravel` template name |

## Open Questions

- Repo naming: keep `ChartSense` as the canonical GitHub name with `dot-charts` as the registry alias, or rename the repo to align? (§6)
- Data vendor strategy: stay on free/keyless APIs long-term, or budget for a paid data feed with an actual SLA once real signals are user-facing?
- Where does OCR-based chart symbol detection (`tesseract`) fit long-term — kept as a fallback, or replaced once broker/exchange API integrations exist?
