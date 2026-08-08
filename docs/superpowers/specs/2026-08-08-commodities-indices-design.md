---
title: Commodities & Indices Asset Class — Design
version: 1.0.0
status: approved
owners: [Charts Platform Lead]
last-review: 2026-08-08
---

# Commodities & Indices Asset Class — Design

## Purpose

Add a third asset class — `commodity` — to the real backtesting engine
([2026-08-08-backtesting-engine.md](2026-08-08-backtesting-engine-design.md)),
covering gold, silver, oil, and the GER30/GER40 (DAX) index. All three
existing strategy presets (MA Crossover, RSI Mean-Reversion, 714 Method)
support it from day one.

**Related documents:**
- [Real Backtesting Engine — Design](2026-08-08-backtesting-engine-design.md) — the slice this extends
- [ChartSense's own wiki.md](../../wiki.md) — platform market list (stocks, crypto, forex, commodities, indices)

## Why this slice, why now

The wiki's stated market list includes commodities and indices, but the
backtesting engine (previous slice) only supports `equity` and `crypto`.
This slice closes that gap for four specific instruments requested
directly, using data infrastructure that already exists — no new fetch
logic, no new backend service.

## Scope for this slice

**In scope:**
- New `asset_class: "commodity"` value, accepted end-to-end (Python schema,
  Laravel validation)
- Four instruments: Gold (`GC=F`), Silver (`SI=F`), Oil/WTI (`CL=F`),
  GER30/GER40 (`^GDAXI` — both retail names alias to the same DAX index)
- All three strategy presets (MA Crossover, RSI Mean-Reversion, 714 Method)
  support commodities
- Frontend: asset-class dropdown gains "Commodity"; symbol field becomes a
  preset dropdown (not free text) when "Commodity" is selected, since these
  tickers (`GC=F` etc.) aren't intuitive to type from memory

**Explicitly out of scope:**
- Any commodity/index beyond the four named instruments
- A general "search any yfinance ticker" UI — the preset dropdown only
  covers these four; free-text symbol entry remains equity/crypto-only
- Forex as an asset class — not requested here, would be its own slice

## Architecture

No new components. `_fetch_equity` in `analytics/data/fetch.py` already
works for any Yahoo Finance ticker (stocks, futures, indices) since it's a
thin wrapper over `yf.download(symbol)` — it is not equity-specific in
practice, only in name. This slice:

1. Renames its role conceptually (not necessarily its function name) to
   "the yfinance-backed fetch path," and routes `commodity` through it
   alongside `equity`.
2. Skips a symbol-mapping layer entirely: the frontend's preset dropdown
   carries the real yfinance ticker as its `<option value>` directly
   (e.g. `value="GC=F"` labeled "Gold"). The backend never sees a friendly
   name — it receives `GC=F` the same way it already receives `AAPL`.

```mermaid
flowchart LR
    UI[Commodity preset dropdown\nvalue=GC=F/SI=F/CL=F/^GDAXI] -->|symbol, asset_class=commodity| L[Laravel API]
    L -->|POST /backtest| PY[Python analytics service]
    PY -->|fetch_ohlcv symbol, "commodity"| YF[yfinance]
```

## Components & data flow

### Python (`analytics/`)

- `schemas.py`: `AssetClass = Literal["equity", "crypto", "commodity"]`
- `data/fetch.py`: `fetch_ohlcv`'s dispatch gains a `commodity` branch that
  calls the same function `_fetch_equity` already uses (equity and
  commodity are both "fetch this yfinance ticker verbatim" — no behavioral
  difference beyond the label).
- No changes to `main.py`'s strategy registry, engines, or interval
  selection — those are keyed by strategy, not asset class, so they apply
  unchanged.

### Laravel (`backend/`)

- `BacktestController::store`'s validation:
  `'asset_class' => 'required|in:equity,crypto,commodity'`
- `DisclosureFormatter` needs no change — its attribution string already
  interpolates `symbol`/`strategy`/`params` generically, independent of
  asset class.

### Frontend (`frontend/`)

- `backtest.html`'s asset-class `<select>` gains an `Commodity` option.
- New commodity symbol `<select>` (hidden unless `asset_class=commodity`):

```html
<option value="GC=F">Gold</option>
<option value="SI=F">Silver</option>
<option value="CL=F">Oil (WTI)</option>
<option value="^GDAXI">GER30 / GER40 (DAX)</option>
```

- `backtest.js`: on asset-class change, toggle between the free-text symbol
  input (equity/crypto) and the commodity preset dropdown; whichever is
  visible supplies `payload.symbol`.

## Error handling

No new error paths — commodity requests flow through the exact same
`DataFetchError` → `422` handling as equity requests, since they share the
same fetch function.

## Testing

- Python: extend `test_fetch.py` with a `commodity` case mirroring the
  existing equity test (mocked `yf.download`, asserts normalized columns
  and tz-aware index) — same fixture pattern, different `asset_class` arg.
- Python: extend `test_backtest_endpoint.py` with one request per
  instrument (`asset_class: "commodity"`, real tickers) to confirm the
  schema accepts the new value and the endpoint doesn't special-case reject
  it.
- Laravel: extend `BacktestControllerTest` with a `commodity` case
  asserting `200`/persistence, matching the existing equity test's shape.
- Frontend: manual verification (matches the existing project convention —
  no JS test framework) — toggle asset class, confirm the symbol field
  swaps, run one real backtest per new instrument.

## Open questions

None blocking. If more commodities/indices are requested later, the
preset-dropdown pattern established here (friendly label → real ticker as
`<option value>`) extends without backend changes.

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 1.0.0 | 2026-08-08 | Charts Platform Lead (brainstorming session) | Initial design: `commodity` asset class covering gold/silver/oil/GER30-40, routed through the existing yfinance fetch path with no new backend components; frontend preset dropdown carries real tickers directly |
