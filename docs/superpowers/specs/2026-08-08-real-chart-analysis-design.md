# Real Chart Analysis — Design

**Subsystem:** G (from the Dot.Charts gap-closure audit)

## Problem

`POST /api/chart/analyze` (`ChartAnalysisController::analyzeChart`) runs
tesseract OCR against an uploaded chart image to guess a ticker symbol,
but the returned `analysis` payload (`signal`, `confidence`, `trend`,
`patterns`, `supports`, `resistances`, `summary`) is a hardcoded constant
— never computed from the chart, real market data, or any model. The
existing docblock and every response already flag this honestly
(`is_demo: true`, an explicit disclaimer), but the analysis itself is
fake.

## Scope Decision

"Real chart analysis" could mean either (a) computer-vision pattern
recognition on the uploaded image's pixels (candlestick shapes,
trendlines, chart patterns), or (b) using OCR only to identify the
symbol, then computing genuinely real technical analysis from live market
data for that symbol. Option (a) is a substantial ML/CV project — out of
scope for a single slice, and a poor fit for a platform whose actual
strength is the real backtesting/data infrastructure already built.
**This slice implements (b)**: OCR (or a user-supplied override) resolves
a symbol, then real analysis is computed from that symbol's real,
recently-fetched OHLCV data, reusing the SMC swing-pivot engine already
built and tested for 714 Method.

## Architecture

A new lightweight endpoint on the analytics service:

```python
POST /chart-analysis
{"symbol": str, "asset_class": str, "interval": str = "1d"}
```

It fetches ~180 days of real OHLCV via the existing `fetch_ohlcv_cached`,
then computes:

- **Trend**: EMA20 vs EMA50 on close (same comparison `ma_crossover`
  already uses) → `"Bullish"` / `"Bearish"` / `"Neutral"`.
- **Structure & supports/resistances**: reuses
  `strategies/method_714/smc.py`'s `compute_swing_pivots` and
  `compute_structure` unchanged — the two most recent confirmed swing
  lows become `supports`, the two most recent confirmed swing highs
  become `resistances` (formatted as price strings, matching the existing
  response shape), and the latest `structure_dir` (1 bullish / -1 bearish
  / 0 neutral) feeds the signal below.
- **Signal**: `"Buy"` when trend is Bullish *and* `structure_dir == 1`;
  `"Sell"` when trend is Bearish *and* `structure_dir == -1`; `"Hold"`
  otherwise (trend and structure disagree, or structure is neutral).
- **Confidence**: a simple 2-component agreement score — 80 if trend and
  structure agree (both bullish or both bearish), 40 if they disagree,
  20 if structure is neutral. This is explicitly a lightweight snapshot
  score, not 714 Method's full weighted backtested confidence system —
  the response's `summary` text says so, to avoid conflating the two.
- **Patterns**: a short descriptive string derived from the most recent
  structure event — e.g. `"Bullish Break of Structure"`,
  `"Bearish Change of Character"`, or `"No recent structure break"` —
  replacing the old fixed `["Ascending Triangle"]`.

`analytics/main.py` gains this route; no changes to the `/backtest`
endpoint, any strategy, or any engine.

`backend/app/Services/AnalyticsServiceClient.php` gains a new method,
`analyzeChart(array $payload): array`, mirroring `runBacktest`'s
structure (timeout, error normalization) exactly.

`ChartAnalysisController::analyzeChart` keeps its existing OCR call and
validation untouched, and adds the orchestration described below.

## Request/Response Contract

**Request** gains one new optional field:

```php
'symbol' => 'nullable|string|max:20',
```

alongside the existing `image`, `market`, `additional_context`.

**Symbol resolution order**: explicit `symbol` request field → OCR-detected
symbol → neither found.

**`market` → `asset_class` mapping**: `stocks` → `equity`, `crypto` →
`crypto`, `forex` → `forex`. (`market`'s enum has no `commodity` value, so
that case is never reached — not a gap, just not part of `market`'s
domain.)

**Real-analysis path** (a symbol was resolved): the controller calls
`AnalyticsServiceClient::analyzeChart` with the mapped `asset_class` and
resolved `symbol`, and returns:

```php
[
    'success' => true,
    'is_demo' => false,
    'disclaimer' => '<real-analysis disclaimer — still not financial advice, not a backtested signal>',
    'analysis' => [ /* signal, confidence, trend, patterns, supports, resistances, summary — from the analytics response */ ],
    'symbol_detected' => $symbol,
    'market' => $market,
]
```

Same top-level JSON structure the frontend and existing tests already
expect — no frontend rendering changes needed, since `renderChartAnalysis`-
equivalent code (in `main.js`) reads the same field names either way.

**Fallback path** (no symbol resolved — OCR failed and no override was
given): returns exactly today's existing hardcoded placeholder response,
byte-for-byte unchanged. `is_demo: true` stays the signal that separates
this response shape from the real one.

**Data-fetch failure path** (a symbol was resolved, but the analytics
call fails — `DataFetchError` for a bad OCR guess, or any other non-2xx):
also falls back to the same placeholder response rather than a hard
error. A wrong OCR guess must not turn into a 500 for the user.

## Testing

- **`analytics/tests/test_chart_analysis.py`**: unit tests on a new
  `compute_chart_analysis(symbol, asset_class, interval) -> dict`
  function — mocks `fetch_ohlcv_cached` with a fixture whose price action
  has a known trend/structure, asserts the resulting
  trend/signal/supports/resistances/patterns match what that fixture
  implies. Plus one `/chart-analysis` endpoint smoke test through
  `main.py`'s `TestClient`.
- **`backend/tests/Feature/ChartAnalysisTest.php`**: new cases —
  (1) a `symbol` override triggers the real-analysis path (`Http::fake`
  against the analytics service's new endpoint) and returns
  `is_demo: false` with the mocked real values; (2) no symbol at all
  (OCR fails against the existing tiny-PNG fixture, no override given)
  still returns today's exact placeholder — this is the existing
  `test_analyze_chart_returns_labeled_placeholder_result` test, kept
  passing unmodified since it already sends no `symbol`; (3) a `symbol`
  override where the analytics service responds with a failure/error
  falls back to the placeholder. All 4 existing tests keep passing with
  no changes to their bodies.
