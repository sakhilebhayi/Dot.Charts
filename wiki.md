---
title: Dot.Charts — Platform Wiki
version: 0.2.3
status: draft
owners: [Charts Platform Lead]
platform-id: dot-charts
last-review: 2026-08-10
---

# Dot.Charts

Purpose: this is Dot.Charts's own knowledge home — owned and maintained by the Charts platform team. It describes what this platform actually is, what it stores, and how it connects to the wider Dot Ecosystem. Dot.Brain never edits this file; it only reads what we choose to publish.

> **Related:** [Dot.Brain's ingested view of this platform](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-charts.md)

> **This wiki is architecture and product documentation only.** It describes system design, data flow, and integration surface. It does not contain, and will never contain, trading, investment, or financial advice — actual signal/strategy content lives in the product itself, gated behind the disclosures described in §7.

---

## 1. What Dot.Charts Is

Dot.Charts is the ecosystem's AI-assisted market-analysis platform: chart interpretation, technical/statistical signal generation, strategy backtesting, and a visual strategy builder across Equity, Crypto, Commodity, and Forex. Smart Money Concepts (SMC)/ICT-style institutional analysis (the "714 Method") is implemented as a real strategy, not just a vision item. The long-term product vision is described at the ecosystem level in Dot.Brain's `platforms/dot-charts.md`; this wiki describes what is actually built today and the honest gap toward that vision.

**Status: a working product, not a prototype.** Since the last review (0.1.1, 2026-08-01), the platform grew from a single hardcoded demo endpoint into three real services: a Laravel API, a Python (FastAPI) analytics/backtesting engine, and a Vite frontend with six wired pages. Auth, backtesting (persisted, multi-strategy, multi-asset-class), a visual strategy builder, real (non-placeholder) chart analysis, and a Knowledge Pack publishing + approval + inbound compliance-gate subsystem are all built and covered by tests (147 PHP + 83 Python, all passing as of this review). Sections below are marked **(built)** or **(planned)** so the remaining gap is explicit — and built features are described with their real, honest limits, not their aspirational end-state.

## 2. Architecture (built)

```
ChartSense/
├── backend/                          # Laravel 12 API
│   ├── app/Http/Controllers/
│   │   ├── AuthController.php                # register/login/logout/me (Sanctum)
│   │   ├── BacktestController.php            # POST/GET/GET-one/DELETE /api/backtests
│   │   ├── CustomStrategyController.php      # POST/GET/GET-one/DELETE /api/strategies
│   │   ├── ChartAnalysisController.php       # chart image -> symbol + real-or-placeholder analysis
│   │   ├── KnowledgePackController.php       # operator-gated generate/list/pending/show/approve/reject/ingest-check
│   │   └── EnhancedMarketDataController.php  # multi-source market data endpoints
│   ├── app/Services/
│   │   ├── AnalyticsServiceClient.php        # HTTP client to the Python analytics service
│   │   ├── DisclosureFormatter.php           # confidence band + attribution + risk disclosure on every backtest result
│   │   ├── DkpSigner.php                     # real Ed25519 canonical-JSON sign/verify
│   │   ├── KnowledgePackApprovalService.php  # approve (signs) / reject a pending_approval pack
│   │   ├── ObservationPackGenerator.php      # 'metric' payload: n>=50 aggregation floor, loss-honesty invariant
│   │   ├── InsightPackGenerator.php          # 'insight' payload
│   │   ├── IncidentPackGenerator.php         # 'incident_report' payload
│   │   ├── RecommendationPackGenerator.php   # 'recommendation' payload (loss-honesty structural-invariant proposal)
│   │   ├── InboundMnpiGate.php               # inbound-only content-materiality screen, fail-closed on keyword match
│   │   ├── EnhancedMarketDataService.php / MarketDataService.php       # CoinGecko/Binance/Coinpaprika/CoinCap/exchange-rate aggregation
│   │   ├── EnhancedNewsDataService.php / NewsDataService.php           # news + Reddit sentiment
│   │   ├── EnhancedStatisticalAnalysisService.php / StatisticalAnalysisService.php
│   │   ├── SignalBacktestingService.php      # legacy long-only backtester, superseded for new work by the analytics service
│   │   ├── SignalFeedbackService.php         # user feedback on signal accuracy (JSON-file store)
│   │   └── SignalErrorMetricsService.php
│   ├── app/Models/                   # User, BacktestRun, CustomStrategy, KnowledgePack, DkpGateDecision
│   ├── app/Events/ + app/Listeners/  # StrategyPerformanceCycleCompleted, ComplianceGateRejected — local log listeners only, see §4
│   ├── app/Console/Commands/         # GenerateDkpKey, GenerateKnowledgePacks (scheduled), GenerateInsightPack/IncidentPack/RecommendationPack (manual)
│   ├── config/dkp_instrument_map.php # seed keyword->instrument map for the inbound gate (illustrative, not comprehensive — see note in file)
│   ├── database/migrations/          # users, backtest_runs, custom_strategies, personal_access_tokens, knowledge_packs (+2 reworks), dkp_gate_decisions
│   └── routes/api.php
├── analytics/                        # FastAPI service (Python) — real backtesting/analysis engine, not scaffolding
│   ├── main.py                       # POST /backtest, POST /chart-analysis, POST /validate-rule, GET /health
│   ├── data/fetch.py + data/cache.py # yfinance + ccxt OHLCV fetch, SQLite bar cache with gap-fill/tail-refresh, fail-closed on fetch error
│   ├── engines/                      # vectorbt_engine.py (vectorized strategies), backtrader_engine.py (714 Method)
│   ├── strategies/                   # ma_crossover, rsi_mean_reversion, breakout (Donchian), bollinger_mean_reversion, custom (rule engine), method_714/ (SMC structure, MTF confirmation, session logic, weighted confidence score)
│   ├── analysis/chart_analysis.py    # real swing-pivot/structure analysis, reused by both backtesting and chart-analysis
│   └── tests/                        # 83 tests (pytest)
├── frontend/                         # Vite scaffold, now wired end-to-end
│   ├── index.html, register.html, login.html, backtest.html, history.html, strategy-builder.html
│   └── src/                          # auth.js, backtest.js, history.js, login.js, register.js, results-renderer.js, strategy-builder.js
└── docs/                             # FREE_APIS_DOCUMENTATION.md, migration notes, docs/superpowers/plans/ (design specs + implementation plans per subsystem)
```

Notable implementation details worth flagging honestly:
- `ChartAnalysisController::analyzeChart` now calls the analytics service for a **real** swing-structure analysis when a symbol is known (caller-supplied or OCR-detected via `tesseract`), and only falls back to the old hardcoded placeholder (still clearly labeled `is_demo: true`) when no symbol is known or the analytics call fails. This is a genuine upgrade from 0.1.1's always-placeholder behavior — but it is explicitly *not* a backtested trading signal (see the `disclaimer` field), just structure/trend detection.
- Every backtest response is run through `DisclosureFormatter`, which adds a confidence band (derived from trade count), a strategy/params attribution string, and a fixed risk disclosure — this is the first §7 compliance-posture bullet, now genuinely implemented rather than aspirational.
- The Python analytics service does real backtests: vectorbt for the vectorized strategies (MA crossover, RSI mean-reversion, breakout, Bollinger), backtrader for the 714 Method (which needs bar-by-bar state for its SMC structure/session/confidence logic). Market data comes from `yfinance` (equity/commodity/forex) and `ccxt` (crypto), cached in SQLite with gap-fill and a fail-closed error on fetch failure — no silent fallback to stale or fabricated bars.
- The strategy builder's rule engine (`analytics/strategies/custom_rules.py`) is shared between backtesting (`/backtest` with `strategy: custom`) and validation (`/validate-rule`, called synchronously when a user saves a strategy) — the same evaluator that runs a saved strategy is the one that validated it at save time.
- Knowledge Pack generation, Ed25519 signing, and an operator approval workflow are real and tested — but see §5 for what "publishing" concretely means today versus what it doesn't yet do.
- `SignalFeedbackService` still writes feedback to `storage/signal_feedback.json` on disk, not a database table — unchanged from 0.1.1, still fine for prototyping, still not multi-tenant-safe.
- `SignalBacktestingService` (the original in-Laravel backtester) still exists but is no longer the active path for new backtest requests — `POST /api/backtests` routes through the Python analytics service instead. It has not been removed or migrated to it; flagging this as leftover surface rather than silently dropping it from the map.

## 3. Domain Entities

| Entity | State | Notes |
|---|---|---|
| User | **built** | `App\Models\User`, Sanctum token auth, `is_platform_operator` flag deliberately excluded from mass assignment |
| Backtest run | **built** | `App\Models\BacktestRun` — persisted, owner-scoped, paginated/filterable list (`GET /api/backtests`), detail, delete, re-run |
| Custom strategy | **built** | `App\Models\CustomStrategy` — persisted rule-JSON, owner-scoped CRUD, validated server-side before save |
| Knowledge Pack | **built** | `App\Models\KnowledgePack` — real Ed25519-signed envelope (signed at approval time, not generation time), `status`: `pending_approval` / `approved` / `rejected` |
| DKP gate decision | **built** | `App\Models\DkpGateDecision` — audit row for every inbound compliance-gate screen (pass or reject), see §7 |
| Signal feedback | **built** (file-backed, unchanged since 0.1.1) | symbol, signal, accuracy vote, comment — `storage/signal_feedback.json`, still not a DB table |
| Watchlist instrument | **planned** | instrument identity (symbol + market) — no model yet |
| Trading journal entry | **planned** | not started |
| Position / order | **out of scope, always** | Dot.Charts does not and will not hold user positions or execute trades — no such table exists; compliant by design now that the schema is large enough for the omission to be a deliberate statement rather than an accident |

This maps onto Dot.Brain's ecosystem-level entity model (`entity:asset` for instruments/strategies, `entity:process` for signals, `outcome` for execution results). Today `BacktestRun`, `CustomStrategy`, `KnowledgePack`, and `DkpGateDecision` have real persistence and are candidates for that mapping once graph publishing exists (§5).

## 4. Events Emitted

**Real, but local-only.** Two domain events now exist and are dispatched on real triggers:
- `StrategyPerformanceCycleCompleted` — dispatched by `ObservationPackGenerator` whenever a monthly observation pack is generated (above the n≥50 floor) for a strategy class.
- `ComplianceGateRejected` — dispatched by `InboundMnpiGate` whenever an inbound pack is rejected by the content-materiality screen.

Both currently have exactly one listener each (`LogStrategyPerformanceCycle`, `LogComplianceGateRejection`), and both listeners only log — there is no queue, event bus, or webhook that carries these off this platform today. Dot.Brain's `platforms/dot-charts.md` specifies a target event set (`trading.signal.issued/expired`, `trading.compliance.gate_rejected`, `trading.strategy.performance_cycle`); the two events built here are conceptually aligned with two of those three, but naming doesn't match exactly and there is still no actual transport to Dot.Brain. Flagging this explicitly rather than implying an event bus exists because the class names sound like one.

## 5. Connecting to Dot.Brain

Dot.Charts is registered in Dot.Brain's platform registry as `dot-charts`. `platform.dkp.json` (a real manifest, not a placeholder) declares a real Ed25519 signing key, a publish/response topic pair, and a PR repository — but nothing in this codebase currently publishes to those topics. What's actually built is a **generate → approve → retrieve** pipeline that stops at this platform's own API boundary:

| Payload type (as implemented) | Brain's spec name | Target cadence | Current status |
|---|---|---|---|
| `metric` | `observation` | monthly | **built**: `knowledge-packs:generate` runs monthly via the scheduler per known strategy class, enforces an n≥50 account-aggregation floor, and always includes worst-drawdown + negative-return-rate (the loss-honesty invariant, never omitted) |
| `insight` | `insight` | per finding | **built**, manual trigger only (`dkp:generate-insight`) — not on the scheduler |
| `incident_report` | `incident` | per incident | **built**, manual trigger only (`dkp:generate-incident`) — not on the scheduler |
| `recommendation` | `outcome` | per verified recommendation | **built**, manual trigger only (`dkp:generate-recommendation`); today there is exactly one recommendation content (the loss-honesty structural-invariant proposal), not a general recommendation-authoring flow |

Every generated pack starts as `pending_approval` (unsigned). An operator (`is_platform_operator`, settable only outside any request payload) must call `POST /knowledge-packs/{id}/approve` or `/reject`. Approval now does two things, in order: (1) runs `OutboundMnpiGate` against the pack's own title/summary/payloads — fail-closed, same instrument-map keyword match as the inbound gate, blocking approval outright on a match rather than signing first and flagging after; (2) only if that passes, produces the Ed25519 signature and self-verifies it before persisting. Approved packs are retrievable via `GET /knowledge-packs/{id}` with the full signed envelope; a separate `POST /knowledge-packs/ingest-check` runs the inbound gate (`InboundMnpiGate`) against a pack-shaped payload before Dot.Charts would accept content *from* another platform. `InboundMnpiGate` and `OutboundMnpiGate` share their actual matching logic via a `ScreensMnpiContent` trait — same rule, same `DkpGateDecision` audit trail, distinguished only by a `direction` column — rather than being two independent implementations that could quietly drift apart.

The gate is now genuinely bidirectional, matching Dot.Brain's framing. A blocked approval — whether from the outbound gate or from the pre-existing "pack not pending" state conflict — now returns a clean `422` with an explanatory `error` message (`KnowledgePackController` catches `RuntimeException` from the service, mirroring the existing pattern in `BacktestController::store()`), not the bare `500` this returned as of the last review. Verified against the live dev server as well as the test suite: a real `POST /knowledge-packs/{id}/approve` against a pack whose content matches the instrument map returns `422` with `{"success":false,"error":"...outbound compliance gate rejected it (matched keywords: kolomela)."}`.

What is **not** built yet, honestly:
- No push transport to Brain's `dkp.dot-charts.publish` topic or any other cross-platform delivery mechanism — approved packs sit in this platform's own database and API until something (Brain, or a future job here) pulls them.
- The `payload_type` values actually stored (`metric`/`insight`/`incident_report`/`recommendation`) don't literally match Brain's spec vocabulary (`observation`/`insight`/`outcome`/`incident`) — conceptually paired 1:1 above, but this is a real naming drift worth reconciling, similar in kind to the repo-naming discrepancy in §6.

## 6. Naming Discrepancy (open question)

Dot.Brain's registry and knowledge document refer to this platform as **`dot-charts`**, and this wiki keeps the "Dot.Charts" product name for ecosystem consistency. The actual GitHub repository, however, is named **`ChartSense`** (`github.com/sakhilebhayi/ChartSense`), not `Dot.Charts` or `dot-charts` — unlike sibling platforms whose repo names match their registry IDs. Still unresolved as of this review; see also the `payload_type` naming drift noted in §5.

## 7. Compliance Posture

Per Dot.Brain's framing, Dot.Charts is the ecosystem's only regulated-market platform. Status against the two invariants from the last review:
- **Signal disclosure — now built.** Every backtest response served by `POST /api/backtests` carries a `disclosure` object (confidence band, strategy/params attribution, fixed risk-disclosure text, max drawdown, losing-trade count) via `DisclosureFormatter`. Chart-analysis responses carry an equivalent `disclaimer` field, distinguishing real (`is_demo: false`) from placeholder results. Neither response type presents a number as real when it isn't.
- **User positions/orders — still never persisted, by design.** No schema exists for it. This has now been true across a full backtesting/journal-adjacent feature build-out (backtest runs, custom strategies, Knowledge Packs), not just by omission on a mostly-empty schema — meaningfully stronger evidence of the invariant holding than at 0.1.1, though still not enforced by any automated schema-level guard (e.g. a test that fails CI if a `position`/`order` table appears).

**`/register` and `/login` rate limiting — now built (closed 2026-08-10).** `login` is capped at 5/min keyed by email+IP (not IP alone, so one attacker can't dodge the limit by rotating the target email, and not email alone, so a botnet can't lock a real user out of their own account); `register` is capped at 5/hour per IP. Verified against the live dev server, not just the test suite: 6 rapid real HTTP requests to a running `php artisan serve` returned five real responses then a `429` on the sixth, for both endpoints.

## 8. Roadmap / Open Questions

- [x] ~~Replace `ChartAnalysisController`'s hardcoded analysis with a real technical/SMC analysis pipeline~~ — built (§2), with placeholder fallback retained honestly for the no-symbol case
- [x] ~~Persist signals, strategies, and backtests as real domain tables~~ — built: `BacktestRun`, `CustomStrategy` (§3)
- [x] ~~Build the strategy-builder UI/engine referenced in the ecosystem vision~~ — built: visual builder, rule engine, save/load, validation (§2)
- [ ] Build the trading journal, with the position/order exclusion enforced at the schema level from day one
- [x] ~~Define and implement the four Knowledge Pack payload types and their publishing cadence~~ — built as generate/approve/retrieve (§5); "publishing" still stops at this platform's own API, see the gap list in §5
- [x] ~~Build the outbound half of the compliance/MNPI gate~~ — built (§5): `OutboundMnpiGate` blocks approval on a content-materiality match
- [x] ~~Map `KnowledgePackApprovalService`'s `RuntimeException`s to a proper 4xx JSON response~~ — built (§5): both the not-pending and outbound-gate-rejected cases now return `422` with an `error` message, verified live and in tests
- [ ] Wire an actual transport path to Dot.Brain (push or documented pull) — the signing key and manifest exist, the pipe does not
- [ ] Reconcile `payload_type` naming (`metric`/`incident_report`/`recommendation`) against Brain's spec vocabulary (`observation`/`incident`/`outcome`) (§5)
- [x] ~~Add rate limiting to `/register` and `/login`~~ — built (§7): 5/min by email+IP on login, 5/hour by IP on register
- [ ] Resolve the `ChartSense` vs. `dot-charts` repo-naming discrepancy (§6)
- [ ] Decide on a real market-data SLA (`yfinance`/`ccxt` are free-tier, best-effort, no uptime guarantee — unchanged from 0.1.1)
- [ ] Retire or migrate `SignalBacktestingService`, the pre-analytics-service backtester left in place but no longer on the active `/api/backtests` path (§2)

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 0.1.0 | 2026-08-01 | Charts Platform Lead | Initial wiki: derived from the actual ChartSense codebase (Laravel backend + Vite frontend, market-data aggregation, chart-analysis and backtesting prototypes) and cross-referenced against Dot.Brain's `platforms/dot-charts.md` for ecosystem framing |
| 0.1.1 | 2026-08-01 | Charts Platform Lead | Engineering-quality pass: fixed `routes/api.php` never being registered (missing `api:` entry in `bootstrap/app.php` — the endpoint didn't exist at all in a real request cycle); removed a stray duplicate-class file (`DetectSymbolTrait.php`) that redeclared `EnhancedMarketDataController`; labeled the hardcoded chart-analysis response as a demo (`is_demo`/`disclaimer` fields, matching UI banner) rather than fixing the "hardcoded results" bug by fabricating real analysis; added the first PHPUnit tests in the repo (`tests/Unit`, `tests/Feature` — previously didn't exist despite `phpunit.xml` referencing them); rewrote root and backend README to drop aspirational/fictional claims (Gemini/GPT-4 Vision, `AIAgentService`) that didn't match the code; removed `downloads/` (stale doc referencing a build archive that isn't in the repo); added real logo/favicons; fixed `composer.json`'s leftover `laravel/laravel` template name |
| 0.2.0 | 2026-08-10 | Charts Platform Lead | Full refresh against ~130 commits of real feature work since 0.1.1 that this wiki had not yet caught up to: added the Python analytics/backtesting service (§2) with 6 real strategies including the 714 Method (SMC + MTF + weighted confidence), Sanctum auth, persisted/owner-scoped backtests and custom strategies (§3), a real (non-placeholder) chart-analysis path with disclosure formatting (§2/§7), and the full Knowledge Pack generate/sign/approve/retrieve pipeline with an inbound-only compliance gate (§5). Corrected Laravel version (12, not 11) and the "no strategy builder / no Knowledge Pack / no event bus" framing, all of which are now built. Verified rather than assumed: ran the full backend (147) and analytics (83) test suites (all green) and confirmed the pending-strategies pagination fix live via browser before writing this refresh. Added new, previously-undocumented honest gaps found during the audit: compliance gate is inbound-only despite Brain's "bidirectional" framing (§5), no push transport to Brain exists yet (§5), `payload_type` naming drift vs. Brain's spec vocabulary (§5), and no rate limiting on `/register`/`/login` (§7) |
| 0.2.1 | 2026-08-10 | Charts Platform Lead | Closed the `/register`/`/login` rate-limiting gap found in 0.2.0: `RateLimiter::for('auth-login', ...)` keyed by email+IP (5/min) and `RateLimiter::for('auth-register', ...)` keyed by IP (5/hour), mirroring the existing `backtests`/`chart-analysis` pattern in `AppServiceProvider`. 3 new regression tests (rate-limit trip, email-keying isolation, register cap) plus a live check against the running dev server (real HTTP requests, not just the test suite) confirming the 6th attempt returns 429 in both cases. Full suite: 150 backend tests passing (was 147) |
| 0.2.2 | 2026-08-10 | Charts Platform Lead | Closed the compliance-gate gap found in 0.2.0: built `OutboundMnpiGate`, screening a pack's own content against the instrument map at approval time and blocking approval outright on a match. Extracted the shared matching logic (previously only in `InboundMnpiGate`) into a `ScreensMnpiContent` trait so both directions can't quietly drift apart — refactor verified behavior-preserving by running `InboundMnpiGateTest` unmodified before and after. 10 new tests (outbound gate unit tests mirroring the inbound suite, approval-service blocking + audit-row coverage, one HTTP-level test). Found and honestly documented, not fixed, a further gap while building this: the block surfaces as a bare `500` today (`KnowledgePackApprovalService`'s `RuntimeException`s were never mapped to a clean 4xx anywhere, including the pre-existing "already approved" case) — added to the roadmap rather than silently expanding this change's scope to fix it. Full suite: 160 backend tests passing (was 150) |
| 0.2.3 | 2026-08-10 | Charts Platform Lead | Closed the `KnowledgePackApprovalService` exception-mapping gap found in 0.2.2: `KnowledgePackController::approve()`/`reject()` now catch `RuntimeException` and return `422` with an `error` message, mirroring the existing `BacktestController::store()` pattern rather than inventing new architecture. Caught and corrected my own wrong assumption mid-task: expected a whitespace-only reject reason to reach the service's `trim()` guard via HTTP, but Laravel's global `TrimStrings` middleware already reduces it to an empty string before `required|string` validation runs, so that guard is unreachable from HTTP (still valid defensively for a future non-HTTP caller) — the test was corrected to assert the real behavior instead of the wrong assumption. 3 tests updated/added (outbound-gate-blocked now expects 422, new already-approved-via-HTTP coverage, whitespace-reason coverage), verified against the live dev server as well: a real blocked-approval request now returns `422` with a clear message instead of a bare `500`. Full suite: 162 backend tests passing (was 160) |

## Open Questions

- Repo naming: keep `ChartSense` as the canonical GitHub name with `dot-charts` as the registry alias, or rename the repo to align? (§6)
- Data vendor strategy: stay on free/keyless APIs (`yfinance`/`ccxt`) long-term, or budget for a paid data feed with an actual SLA once real signals are user-facing?
- Where does OCR-based chart symbol detection (`tesseract`) fit long-term — kept as a fallback (its current role now that real analysis exists for known symbols), or replaced once broker/exchange API integrations exist?
- Knowledge Pack transport: should Dot.Charts push to Brain's declared topic, or is Brain expected to pull via the existing operator-gated API? Nothing in this codebase currently assumes either — needs an owner decision before either gets built (§5)
- Should the four `payload_type` values be renamed to match Brain's spec vocabulary, or should Brain's spec be updated to match what shipped? (§5)
