# Forex Support — Design

**Subsystem:** H (from the Dot.Charts gap-closure audit)

## Problem

Dot.Charts currently supports three asset classes — equity, crypto, and
commodity — but no forex (currency pairs). The gap audit calls for adding
one.

## Goal

Add `forex` as a fourth asset class, backtestable with every existing
strategy (MA Crossover, RSI Mean-Reversion, Breakout, Bollinger
Mean-Reversion, 714 Method), following the exact same "no new fetch logic
needed" pattern the commodity slice established, plus a curated
preset-symbol dropdown in the UI.

## Architecture

`yfinance` supports forex pairs as tickers with a fixed `=X` suffix (e.g.
`EURUSD=X`, `USDJPY=X`) and returns data through the exact same code path
as equities — same OHLCV shape, same `MultiIndex` column quirk, same
`start`/`end` semantics. This means forex needs **zero new fetch logic**,
identical to how the commodity slice discovered commodities are "any
Yahoo ticker" and reused the equity path outright.

`analytics/data/fetch.py`'s `fetch_ohlcv` branch condition:

```python
if asset_class in ("equity", "commodity"):
```

extends to:

```python
if asset_class in ("equity", "commodity", "forex"):
```

with the existing comment above it (explaining why commodities/indices
share the equity path) updated to also cover forex. No changes to
`engines/vectorbt_engine.py`, `engines/backtrader_engine.py`, or any
strategy — they only ever operate on the already-normalized OHLCV
DataFrame `fetch_ohlcv` returns, with no asset-class-specific behavior.
`method_714`'s intraday interval and session logic work unmodified, since
yfinance serves intraday forex data the same way it serves intraday
equity data.

## Symbol Input

A curated preset dropdown, following the commodity slice's UX exactly
rather than free-text entry — most users backtesting forex want a known
major pair, not to discover yfinance's `=X` suffix convention themselves:

| Label | Ticker |
|---|---|
| EUR/USD | `EURUSD=X` |
| GBP/USD | `GBPUSD=X` |
| USD/JPY | `USDJPY=X` |
| USD/ZAR | `USDZAR=X` |

USD/ZAR is included alongside the three global majors to match the
platform's existing South African context (the commodity preset already
includes GER30/GER40, and 714 Method's default session timezone is
Africa/Johannesburg).

## Integration Points

Every place that already hand-enumerates asset classes or renders the
commodity preset pattern needs the equivalent forex addition:

- **`analytics/schemas.py`**: `AssetClass = Literal["equity", "crypto", "commodity", "forex"]`.
- **`backend/app/Http/Controllers/BacktestController.php`**: the `store`
  validation rule `'asset_class' => 'required|in:equity,crypto,commodity'`
  extends to include `forex`. Without this, a forex request 422s at the
  Laravel layer before reaching the analytics service — the same bug
  class every prior asset-class/strategy addition has had to guard
  against.
- **`frontend/backtest.html`**: the asset-class `<select>` gains
  `<option value="forex">Forex</option>`; a new `symbolForex` preset
  `<select>` (hidden by default via inline `style="display:none"`,
  matching `symbolCommodity`) holds the four pairs above.
- **`frontend/src/backtest.js`**: the `assetClassSelect` `change`
  listener and `currentSymbol()` function both currently branch only on
  `assetClassSelect.value === 'commodity'` to decide which symbol input
  is visible/authoritative — both extend to also handle `'forex'` the
  same way. The re-run prefill logic's
  `if (prefill.asset_class === 'commodity') { ... }` branch gets the
  equivalent `'forex'` branch, so re-running a saved forex backtest
  correctly selects the matching preset option instead of leaving the
  dropdown on its default.
- **`frontend/history.html`**: **correction, found during implementation
  verification** — this spec originally claimed no change was needed
  here, based on a `grep` for `asset_class`/`assetClass` that came up
  empty. That grep was case-sensitive and missed the actual element id,
  `filterAssetClass` (capital A) — the page does have an asset-class
  filter `<select>`, already containing Equity/Crypto/Commodity options
  from the commodity slice. It needed the same `<option
  value="forex">Forex</option>` addition as every other hand-enumerated
  asset-class list. Caught by manually opening the page during
  verification rather than trusting the earlier grep-based claim.

## Testing

- **`analytics/tests/test_fetch.py`**: one new test mirroring
  `test_fetch_ohlcv_commodity_reuses_the_yfinance_path` — mocks
  `yf.download`, asserts a forex symbol (`EURUSD=X`) returns the same
  normalized `open/high/low/close/volume` columns via the same code path.
- **`analytics/tests/test_backtest_endpoint.py`**: one smoke test through
  `/backtest` with `asset_class: "forex"`, confirming a valid
  `BacktestResult` shape (mirrors the existing
  `test_backtest_commodity_returns_metrics_and_trades` test).
- **`backend/tests/Feature/BacktestControllerTest.php`**: one new test,
  `test_store_accepts_forex_asset_class`, mirroring
  `test_store_accepts_commodity_asset_class` exactly.
