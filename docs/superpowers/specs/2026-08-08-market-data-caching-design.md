# Market-Data Caching — Design

**Subsystem:** B (from the Dot.Charts gap-closure audit)

## Problem

Every `/backtest` request in the analytics service fetches OHLCV data live —
`yfinance` for equity/commodity, `ccxt`/Binance for crypto — with no caching
layer at all. `method_714` backtests make this worse by issuing a *second*
live fetch for the higher-timeframe (MTF) confirmation series. Repeated
backtests on the same symbol/date range (e.g. iterating on strategy params,
or a user re-running a saved config from History) re-fetch identical data
every time, risking Binance rate limits (429s) and slowing iteration.

## Goal

Avoid hitting exchange/data-provider rate limits by caching fetched OHLCV
bars and only live-fetching what isn't already cached, while never silently
serving stale or incomplete data.

## Architecture

A new module, `analytics/data/cache.py`, wraps the existing `fetch_ohlcv()`
(in `analytics/data/fetch.py`, unchanged) with a caching layer backed by
SQLite via the Python stdlib `sqlite3` module — no new dependency. It
exposes one new function:

```python
def fetch_ohlcv_cached(
    symbol: str, asset_class: str, start_date: str, end_date: str,
    interval: str = "1d",
) -> pd.DataFrame:
```

Same signature and return contract as `fetch_ohlcv` (a tz-aware,
UTC-indexed DataFrame with `open/high/low/close/volume` columns), so every
caller can swap directly.

Two call sites switch to it:
- `analytics/main.py` — the `/backtest` endpoint's data fetch, for every
  strategy.
- `analytics/strategies/method_714/mtf.py` — `compute_htf_trend`'s second
  fetch for the MTF confirmation series. This is the bigger win, since
  every `method_714` backtest previously made 2 live calls.

The raw `fetch_ohlcv` stays exactly as-is and remains the "live fetch"
primitive the cache layer calls internally (and what existing tests that
don't care about caching continue to mock directly).

The SQLite file lives at `analytics/data/ohlcv_cache.db`, is added to
`.gitignore`, and is created on first use if it doesn't exist.

## Data Model

One table:

```sql
CREATE TABLE IF NOT EXISTS ohlcv_bars (
    symbol      TEXT NOT NULL,
    asset_class TEXT NOT NULL,
    interval    TEXT NOT NULL,
    ts          INTEGER NOT NULL,  -- unix ms, UTC
    open        REAL NOT NULL,
    high        REAL NOT NULL,
    low         REAL NOT NULL,
    close       REAL NOT NULL,
    volume      REAL NOT NULL,
    PRIMARY KEY (symbol, asset_class, interval, ts)
);
```

Coverage for a given `(symbol, asset_class, interval)` key is tracked
*implicitly* via `MIN(ts)`/`MAX(ts)` over that key's rows — no separate
metadata table. This is only trustworthy because the cache layer maintains
a **contiguous-range invariant**: it only ever writes bars that extend the
covered range from its current edge (steps 2/3 below), never an arbitrary
disconnected sub-range. As long as that invariant holds, `MIN`/`MAX`
fully describes what's covered, with no gaps to track separately.

## Gap-Fill Algorithm

For a request `fetch_ohlcv_cached(symbol, asset_class, start, end, interval)`:

1. **No cached rows for this key** → live-fetch `[start, end]` in full via
   `fetch_ohlcv`, insert all rows, return them.
2. **`start < cached_min`** (request needs data older than what's cached)
   → live-fetch `[start, cached_min)`, `INSERT OR REPLACE` into the table,
   extending the covered range backward.
3. **`end > cached_max`** (request needs data newer than what's cached)
   → live-fetch `(cached_max, end]`, `INSERT OR REPLACE`, extending the
   covered range forward.
4. **Always, independent of 2/3:** if the pre-update `cached_max` bar falls
   within `[start, end]`, re-fetch and overwrite just that single bar. This
   is the "the most recent bar may still have been in progress when it was
   cached" guard — applied uniformly on every request that touches the
   existing tail, rather than conditioned on comparing to wall-clock "now".
   Every other cached bar is treated as immutable and is never re-fetched.
5. Serve `[start, end]` by querying the now-updated table for this key.

**Failure handling:** if any live fetch in steps 1-4 raises
`DataFetchError`, the whole request fails closed — nothing is written to
the cache for that call, and the exception propagates to the caller
unchanged. A request is never partially served from a mix of "some data we
have" and "silently missing the rest" — `main.py`'s existing
`DataFetchError` → HTTP 422 handling requires no changes.

## Connection Lifecycle

`cache.py` opens a short-lived `sqlite3.connect(DB_PATH)` per call to
`fetch_ohlcv_cached` — no connection pool. `PRAGMA journal_mode=WAL` is set
once at connect time so concurrent uvicorn workers reading/writing don't
lock each other out. This is adequate for the service's request volume;
revisit only if profiling shows contention.

## Testing

Tests use a temporary SQLite file per test (`tmp_path` fixture) and mock
`cache.fetch_ohlcv` — the live-fetch primitive — with `pytest-mock`,
asserting the mock is called with exactly the expected gap sub-range(s).
Asserting on the *exact ranges requested* (not just "was a live call made")
is what proves the gap-fill logic is correct, not merely that caching
happens at all. Covered cases:

- Cold cache: no prior rows → one live fetch covering the full range.
- Fully-covered repeat request → no live fetch for historical bars, but the
  tail-bar refresh (step 4) still fires exactly once.
- Backward extension (`start < cached_min`) → live fetch covers only the
  missing leading gap.
- Forward extension (`end > cached_max`) → live fetch covers only the
  missing trailing gap.
- Live-fetch failure during a gap-fill → `DataFetchError` propagates, and
  the cache table is left unchanged (nothing partially written).

## Out of Scope

- Cache eviction/expiry policy (bars are either immutable or covered by the
  tail-bar refresh; no TTL-based cleanup).
- Multi-instance cache sharing (SQLite file is local to the container;
  acceptable since the service currently runs as a single process).
- Warming/pre-fetching popular symbols.
