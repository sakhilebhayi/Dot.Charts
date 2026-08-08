# Market-Data Caching Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a SQLite-backed cache in front of `fetch_ohlcv` so repeated backtests on the same symbol/range don't re-hit Binance/Yahoo, cutting rate-limit risk, without ever silently serving a stale or incomplete range.

**Architecture:** A new `analytics/data/cache.py` module owns a single SQLite table (`ohlcv_bars`) and exposes `fetch_ohlcv_cached(...)`, matching `fetch_ohlcv`'s signature and return contract. It fills gaps against the existing cached `[MIN(ts), MAX(ts)]` range for a `(symbol, asset_class, interval)` key, always calling the unmodified `fetch_ohlcv` for whatever isn't covered, then serves the merged result from SQLite. `main.py` and `mtf.py` switch their fetch call to the cached version.

**Tech Stack:** Python stdlib `sqlite3` (no new dependency), `pandas`, existing `pytest`/`pytest-mock`.

## Global Constraints

- No new pip dependency — caching uses stdlib `sqlite3` only (per spec's Architecture section).
- `fetch_ohlcv` in `analytics/data/fetch.py` stays completely unmodified — it remains the "live fetch" primitive (per spec's Architecture section).
- Any live-fetch failure during a cached request must fail closed: no partial cache write, exception propagates unchanged so `main.py`'s existing `DataFetchError` → HTTP 422 handling needs no changes (per spec's Gap-Fill Algorithm and Failure Handling sections).
- Coverage per `(symbol, asset_class, interval)` key must remain a contiguous `[MIN(ts), MAX(ts)]` range — every write must extend from the current edge, never write a disconnected sub-range (per spec's Data Model section).
- Tests assert on the *exact sub-range* passed to the mocked live-fetch call, not just "was it called" (per spec's Testing section).

---

### Task 1: Cache module scaffolding — schema, connection, low-level helpers

**Files:**
- Create: `analytics/data/cache.py`
- Test: `analytics/tests/test_cache.py`

**Interfaces:**
- Consumes: `fetch_ohlcv(symbol, asset_class, start_date, end_date, interval) -> pd.DataFrame` from `analytics/data/fetch.py` (existing, unmodified).
- Produces (for later tasks in this plan): `_connect(db_path: Path) -> sqlite3.Connection`, `_get_coverage(conn, symbol, asset_class, interval) -> tuple[int, int] | None` (min/max unix-ms, or `None` if uncached), `_insert_bars(conn, symbol, asset_class, interval, df: pd.DataFrame) -> None`, `_query_range(conn, symbol, asset_class, interval, start_ms: int, end_ms: int) -> pd.DataFrame`.

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_cache.py
import pandas as pd
import pytest
from data import cache


def _sample_df():
    idx = pd.date_range("2023-01-01", periods=3, freq="D", tz="UTC")
    return pd.DataFrame(
        {
            "open": [100.0, 101.0, 102.0],
            "high": [101.0, 102.0, 103.0],
            "low": [99.0, 100.0, 101.0],
            "close": [100.5, 101.5, 102.5],
            "volume": [1000.0, 1100.0, 1200.0],
        },
        index=idx,
    )


def test_insert_then_query_round_trips_bars(tmp_path):
    db_path = tmp_path / "test_cache.db"
    conn = cache._connect(db_path)

    cache._insert_bars(conn, "AAPL", "equity", "1d", _sample_df())
    result = cache._query_range(
        conn, "AAPL", "equity", "1d",
        start_ms=int(pd.Timestamp("2023-01-01", tz="UTC").value // 1_000_000),
        end_ms=int(pd.Timestamp("2023-01-03", tz="UTC").value // 1_000_000),
    )

    assert list(result.columns) == ["open", "high", "low", "close", "volume"]
    assert len(result) == 3
    assert result.index.tz is not None
    assert result["close"].tolist() == [100.5, 101.5, 102.5]
    conn.close()


def test_get_coverage_returns_none_when_nothing_cached(tmp_path):
    db_path = tmp_path / "test_cache.db"
    conn = cache._connect(db_path)

    assert cache._get_coverage(conn, "AAPL", "equity", "1d") is None
    conn.close()


def test_get_coverage_returns_min_and_max_ts_after_insert(tmp_path):
    db_path = tmp_path / "test_cache.db"
    conn = cache._connect(db_path)
    cache._insert_bars(conn, "AAPL", "equity", "1d", _sample_df())

    coverage = cache._get_coverage(conn, "AAPL", "equity", "1d")

    expected_min = int(pd.Timestamp("2023-01-01", tz="UTC").value // 1_000_000)
    expected_max = int(pd.Timestamp("2023-01-03", tz="UTC").value // 1_000_000)
    assert coverage == (expected_min, expected_max)
    conn.close()


def test_insert_bars_is_idempotent_via_primary_key(tmp_path):
    # INSERT OR REPLACE on the same (symbol, asset_class, interval, ts) key
    # must overwrite, not duplicate -- this is what makes overlapping
    # gap-fill fetches safe in later tasks.
    db_path = tmp_path / "test_cache.db"
    conn = cache._connect(db_path)
    df = _sample_df()

    cache._insert_bars(conn, "AAPL", "equity", "1d", df)
    cache._insert_bars(conn, "AAPL", "equity", "1d", df)  # same rows again

    result = cache._query_range(
        conn, "AAPL", "equity", "1d",
        start_ms=int(pd.Timestamp("2023-01-01", tz="UTC").value // 1_000_000),
        end_ms=int(pd.Timestamp("2023-01-03", tz="UTC").value // 1_000_000),
    )
    assert len(result) == 3
    conn.close()
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_cache.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'data.cache'` (or `AttributeError`).

- [ ] **Step 3: Write the implementation**

```python
# analytics/data/cache.py
import sqlite3
from pathlib import Path
from typing import Optional

import pandas as pd

from .fetch import fetch_ohlcv, DataFetchError

DB_PATH = Path(__file__).parent / "ohlcv_cache.db"

_OHLCV_COLUMNS = ["open", "high", "low", "close", "volume"]

_SCHEMA = """
CREATE TABLE IF NOT EXISTS ohlcv_bars (
    symbol TEXT NOT NULL,
    asset_class TEXT NOT NULL,
    interval TEXT NOT NULL,
    ts INTEGER NOT NULL,
    open REAL NOT NULL,
    high REAL NOT NULL,
    low REAL NOT NULL,
    close REAL NOT NULL,
    volume REAL NOT NULL,
    PRIMARY KEY (symbol, asset_class, interval, ts)
)
"""


def _connect(db_path: Path = DB_PATH) -> sqlite3.Connection:
    conn = sqlite3.connect(db_path)
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute(_SCHEMA)
    conn.commit()
    return conn


def _get_coverage(
    conn: sqlite3.Connection, symbol: str, asset_class: str, interval: str
) -> Optional[tuple[int, int]]:
    row = conn.execute(
        "SELECT MIN(ts), MAX(ts) FROM ohlcv_bars "
        "WHERE symbol = ? AND asset_class = ? AND interval = ?",
        (symbol, asset_class, interval),
    ).fetchone()
    if row is None or row[0] is None:
        return None
    return (int(row[0]), int(row[1]))


def _insert_bars(
    conn: sqlite3.Connection, symbol: str, asset_class: str, interval: str, df: pd.DataFrame
) -> None:
    rows = [
        (
            symbol,
            asset_class,
            interval,
            int(ts.value // 1_000_000),
            float(row["open"]),
            float(row["high"]),
            float(row["low"]),
            float(row["close"]),
            float(row["volume"]),
        )
        for ts, row in df.iterrows()
    ]
    conn.executemany(
        """
        INSERT OR REPLACE INTO ohlcv_bars
        (symbol, asset_class, interval, ts, open, high, low, close, volume)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        """,
        rows,
    )
    conn.commit()


def _query_range(
    conn: sqlite3.Connection,
    symbol: str,
    asset_class: str,
    interval: str,
    start_ms: int,
    end_ms: int,
) -> pd.DataFrame:
    rows = conn.execute(
        """
        SELECT ts, open, high, low, close, volume FROM ohlcv_bars
        WHERE symbol = ? AND asset_class = ? AND interval = ?
          AND ts BETWEEN ? AND ?
        ORDER BY ts ASC
        """,
        (symbol, asset_class, interval, start_ms, end_ms),
    ).fetchall()

    if not rows:
        empty_index = pd.DatetimeIndex([], tz="UTC")
        return pd.DataFrame(columns=_OHLCV_COLUMNS, index=empty_index)

    idx = pd.to_datetime([r[0] for r in rows], unit="ms", utc=True)
    data = {col: [r[i + 1] for r in rows] for i, col in enumerate(_OHLCV_COLUMNS)}
    return pd.DataFrame(data, index=idx)
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_cache.py -v`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add analytics/data/cache.py analytics/tests/test_cache.py
git commit -m "feat(cache): add SQLite schema and low-level bar storage helpers"
```

---

### Task 2: `fetch_ohlcv_cached` — cold-cache path

**Files:**
- Modify: `analytics/data/cache.py`
- Test: `analytics/tests/test_cache.py`

**Interfaces:**
- Consumes: `_connect`, `_get_coverage`, `_insert_bars`, `_query_range` from Task 1; `fetch_ohlcv` from `analytics/data/fetch.py`.
- Produces: `fetch_ohlcv_cached(symbol: str, asset_class: str, start_date: str, end_date: str, interval: str = "1d", db_path: Path = DB_PATH) -> pd.DataFrame` — later tasks extend its body but this signature is final.

- [ ] **Step 1: Write the failing test**

```python
def test_fetch_ohlcv_cached_cold_cache_fetches_full_range_once(tmp_path, mocker):
    db_path = tmp_path / "test_cache.db"
    live_df = _sample_df()  # 2023-01-01..03, see Task 1's helper
    mock_fetch = mocker.patch("data.cache.fetch_ohlcv", return_value=live_df)

    result = cache.fetch_ohlcv_cached(
        "AAPL", "equity", "2023-01-01", "2023-01-03", interval="1d", db_path=db_path,
    )

    mock_fetch.assert_called_once_with("AAPL", "equity", "2023-01-01", "2023-01-03", "1d")
    assert len(result) == 3
    assert list(result.columns) == ["open", "high", "low", "close", "volume"]
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_cache.py::test_fetch_ohlcv_cached_cold_cache_fetches_full_range_once -v`
Expected: FAIL with `AttributeError: module 'data.cache' has no attribute 'fetch_ohlcv_cached'`

- [ ] **Step 3: Write the implementation**

Append to `analytics/data/cache.py`:

```python
def fetch_ohlcv_cached(
    symbol: str,
    asset_class: str,
    start_date: str,
    end_date: str,
    interval: str = "1d",
    db_path: Path = DB_PATH,
) -> pd.DataFrame:
    conn = _connect(db_path)
    try:
        start_ms = int(pd.Timestamp(start_date, tz="UTC").value // 1_000_000)
        end_ms = int(pd.Timestamp(end_date, tz="UTC").value // 1_000_000)

        coverage = _get_coverage(conn, symbol, asset_class, interval)

        if coverage is None:
            live_df = fetch_ohlcv(symbol, asset_class, start_date, end_date, interval)
            _insert_bars(conn, symbol, asset_class, interval, live_df)
            return _query_range(conn, symbol, asset_class, interval, start_ms, end_ms)

        # Later tasks add backward/forward gap-fill and tail-bar refresh here.
        return _query_range(conn, symbol, asset_class, interval, start_ms, end_ms)
    finally:
        conn.close()
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_cache.py -v`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add analytics/data/cache.py analytics/tests/test_cache.py
git commit -m "feat(cache): fetch_ohlcv_cached cold-cache path"
```

---

### Task 3: Fully-covered repeat request + tail-bar refresh

**Files:**
- Modify: `analytics/data/cache.py`
- Test: `analytics/tests/test_cache.py`

**Interfaces:**
- Consumes: `fetch_ohlcv_cached` from Task 2 (extends its body).
- Produces: same signature; no new public interface.

- [ ] **Step 1: Write the failing test**

```python
def test_fetch_ohlcv_cached_fully_covered_request_only_refreshes_tail_bar(tmp_path, mocker):
    db_path = tmp_path / "test_cache.db"
    conn = cache._connect(db_path)
    cache._insert_bars(conn, "AAPL", "equity", "1d", _sample_df())  # covers 01-01..03
    conn.close()

    # The tail refresh re-fetches from the old cached_max's date onward.
    tail_df = _sample_df().iloc[[-1]]  # just the 01-03 bar, freshly "live"
    mock_fetch = mocker.patch("data.cache.fetch_ohlcv", return_value=tail_df)

    result = cache.fetch_ohlcv_cached(
        "AAPL", "equity", "2023-01-01", "2023-01-03", interval="1d", db_path=db_path,
    )

    # No fetch for the already-covered historical range -- only the tail refresh.
    mock_fetch.assert_called_once_with("AAPL", "equity", "2023-01-03", "2023-01-03", "1d")
    assert len(result) == 3
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_cache.py::test_fetch_ohlcv_cached_fully_covered_request_only_refreshes_tail_bar -v`
Expected: FAIL — `mock_fetch` was never called (assert_called_once_with raises `AssertionError`), since Task 2's implementation returns straight from `_query_range` without any tail refresh.

- [ ] **Step 3: Write the implementation**

Replace the `# Later tasks add ...` comment line in `fetch_ohlcv_cached` with:

```python
        cached_min, cached_max = coverage

        if start_ms <= cached_max <= end_ms:
            tail_date = pd.Timestamp(cached_max, unit="ms", tz="UTC").strftime("%Y-%m-%d")
            tail_df = fetch_ohlcv(symbol, asset_class, tail_date, end_date, interval)
            _insert_bars(conn, symbol, asset_class, interval, tail_df)

        return _query_range(conn, symbol, asset_class, interval, start_ms, end_ms)
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_cache.py -v`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add analytics/data/cache.py analytics/tests/test_cache.py
git commit -m "feat(cache): always refresh the tail bar on a fully-covered request"
```

---

### Task 4: Backward extension gap-fill

**Files:**
- Modify: `analytics/data/cache.py`
- Test: `analytics/tests/test_cache.py`

**Interfaces:**
- Consumes: `fetch_ohlcv_cached` from Task 3 (extends its body).
- Produces: same signature; no new public interface.

- [ ] **Step 1: Write the failing test**

```python
def test_fetch_ohlcv_cached_extends_backward_when_request_starts_earlier(tmp_path, mocker):
    db_path = tmp_path / "test_cache.db"
    conn = cache._connect(db_path)
    cache._insert_bars(conn, "AAPL", "equity", "1d", _sample_df())  # covers 01-01..03
    conn.close()

    idx = pd.date_range("2022-12-30", periods=2, freq="D", tz="UTC")
    backward_df = pd.DataFrame(
        {"open": [98.0, 99.0], "high": [99.0, 100.0], "low": [97.0, 98.0],
         "close": [98.5, 99.5], "volume": [900.0, 950.0]},
        index=idx,
    )
    tail_df = _sample_df().iloc[[-1]]

    def fake_fetch(symbol, asset_class, start_date, end_date, interval):
        if start_date == "2022-12-30":
            return backward_df
        return tail_df  # the tail refresh call

    mock_fetch = mocker.patch("data.cache.fetch_ohlcv", side_effect=fake_fetch)

    result = cache.fetch_ohlcv_cached(
        "AAPL", "equity", "2022-12-30", "2023-01-03", interval="1d", db_path=db_path,
    )

    assert mock_fetch.call_args_list[0] == mocker.call(
        "AAPL", "equity", "2022-12-30", "2023-01-01", "1d",
    )
    assert len(result) == 5
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_cache.py::test_fetch_ohlcv_cached_extends_backward_when_request_starts_earlier -v`
Expected: FAIL — `result` has only 3 rows (the pre-existing cache), since nothing yet fetches the backward gap; `call_args_list[0]` is the tail-refresh call, not the expected backward-gap call.

- [ ] **Step 3: Write the implementation**

Add this block in `fetch_ohlcv_cached`, immediately after `cached_min, cached_max = coverage` and before the tail-refresh block from Task 3:

```python
        if start_ms < cached_min:
            gap_end_date = pd.Timestamp(cached_min, unit="ms", tz="UTC").strftime("%Y-%m-%d")
            backward_df = fetch_ohlcv(symbol, asset_class, start_date, gap_end_date, interval)
            _insert_bars(conn, symbol, asset_class, interval, backward_df)
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_cache.py -v`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
git add analytics/data/cache.py analytics/tests/test_cache.py
git commit -m "feat(cache): backward gap-fill when request starts earlier than cache"
```

---

### Task 5: Forward extension gap-fill

**Files:**
- Modify: `analytics/data/cache.py`
- Test: `analytics/tests/test_cache.py`

**Interfaces:**
- Consumes: `fetch_ohlcv_cached` from Task 4 (extends its body).
- Produces: same signature; no new public interface.

- [ ] **Step 1: Write the failing test**

```python
def test_fetch_ohlcv_cached_extends_forward_when_request_ends_later(tmp_path, mocker):
    db_path = tmp_path / "test_cache.db"
    conn = cache._connect(db_path)
    cache._insert_bars(conn, "AAPL", "equity", "1d", _sample_df())  # covers 01-01..03
    conn.close()

    idx = pd.date_range("2023-01-04", periods=2, freq="D", tz="UTC")
    forward_df = pd.DataFrame(
        {"open": [103.0, 104.0], "high": [104.0, 105.0], "low": [102.0, 103.0],
         "close": [103.5, 104.5], "volume": [1300.0, 1400.0]},
        index=idx,
    )

    mock_fetch = mocker.patch("data.cache.fetch_ohlcv", return_value=forward_df)

    result = cache.fetch_ohlcv_cached(
        "AAPL", "equity", "2023-01-01", "2023-01-05", interval="1d", db_path=db_path,
    )

    # Both the forward-gap fetch and the tail-bar refresh target the same
    # (cached_max, end_date) window here since the old tail (01-03) now
    # falls inside the newly-extended range -- two calls, same args.
    assert mock_fetch.call_count == 2
    for call in mock_fetch.call_args_list:
        assert call == mocker.call("AAPL", "equity", "2023-01-03", "2023-01-05", "1d")
    assert len(result) == 5
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_cache.py::test_fetch_ohlcv_cached_extends_forward_when_request_ends_later -v`
Expected: FAIL — `result` has only 3 rows, `mock_fetch.call_count == 1` (only the tail refresh runs; nothing fetches 01-04..05 yet).

- [ ] **Step 3: Write the implementation**

Add this block in `fetch_ohlcv_cached`, right after the backward-extension block from Task 4 and before the tail-refresh block:

```python
        if end_ms > cached_max:
            gap_start_date = pd.Timestamp(cached_max, unit="ms", tz="UTC").strftime("%Y-%m-%d")
            forward_df = fetch_ohlcv(symbol, asset_class, gap_start_date, end_date, interval)
            _insert_bars(conn, symbol, asset_class, interval, forward_df)
```

Note: this reads `cached_max` from before any writes in this call, matching the backward block's use of `cached_min` — both gap checks use the coverage snapshot taken at the top of the function, not a value mutated mid-call.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_cache.py -v`
Expected: PASS (8 tests)

- [ ] **Step 5: Commit**

```bash
git add analytics/data/cache.py analytics/tests/test_cache.py
git commit -m "feat(cache): forward gap-fill when request ends later than cache"
```

---

### Task 6: Fail-closed on live-fetch errors

**Files:**
- Modify: `analytics/data/cache.py` (verify only — see Step 3)
- Test: `analytics/tests/test_cache.py`

**Interfaces:**
- Consumes: `fetch_ohlcv_cached` from Task 5; `DataFetchError` from `analytics/data/fetch.py`.
- Produces: no new interface — this task is a regression-proofing pass confirming the existing control flow already fails closed, since every gap-fill block in Tasks 2-5 calls `fetch_ohlcv` (which can raise `DataFetchError`) *before* its matching `_insert_bars` call, so a raised exception always skips the write.

- [ ] **Step 1: Write the failing test**

```python
def test_fetch_ohlcv_cached_cold_cache_failure_leaves_cache_empty(tmp_path, mocker):
    db_path = tmp_path / "test_cache.db"
    mocker.patch("data.cache.fetch_ohlcv", side_effect=cache.DataFetchError("no data"))

    with pytest.raises(cache.DataFetchError):
        cache.fetch_ohlcv_cached(
            "AAPL", "equity", "2023-01-01", "2023-01-03", interval="1d", db_path=db_path,
        )

    conn = cache._connect(db_path)
    assert cache._get_coverage(conn, "AAPL", "equity", "1d") is None
    conn.close()


def test_fetch_ohlcv_cached_gap_fill_failure_leaves_existing_cache_intact(tmp_path, mocker):
    db_path = tmp_path / "test_cache.db"
    conn = cache._connect(db_path)
    cache._insert_bars(conn, "AAPL", "equity", "1d", _sample_df())  # covers 01-01..03
    conn.close()

    mocker.patch("data.cache.fetch_ohlcv", side_effect=cache.DataFetchError("rate limited"))

    with pytest.raises(cache.DataFetchError):
        cache.fetch_ohlcv_cached(
            "AAPL", "equity", "2022-12-30", "2023-01-03", interval="1d", db_path=db_path,
        )

    conn = cache._connect(db_path)
    coverage = cache._get_coverage(conn, "AAPL", "equity", "1d")
    expected_min = int(pd.Timestamp("2023-01-01", tz="UTC").value // 1_000_000)
    expected_max = int(pd.Timestamp("2023-01-03", tz="UTC").value // 1_000_000)
    assert coverage == (expected_min, expected_max)  # unchanged -- no partial backward write
    conn.close()
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_cache.py -k failure -v`
Expected: These should already PASS given Tasks 2-5's implementation, since `fetch_ohlcv` is always called before its corresponding `_insert_bars`. Run this step to confirm — if either fails, it means a gap-fill block was written with insert-before-fetch ordering and needs fixing before proceeding.

- [ ] **Step 3: Fix if needed, otherwise confirm**

If Step 2 passed both tests, no code change is needed — proceed to Step 4. If either failed, reorder the offending block in `fetch_ohlcv_cached` so `_insert_bars` is called only with the successful return value of `fetch_ohlcv`, never before it or with a partial result.

- [ ] **Step 4: Run the full cache test file to confirm no regressions**

Run: `cd analytics && .venv/bin/pytest tests/test_cache.py -v`
Expected: PASS (10 tests)

- [ ] **Step 5: Commit**

```bash
git add analytics/tests/test_cache.py
git commit -m "test(cache): confirm live-fetch failures leave the cache unchanged"
```

---

### Task 7: Wire callers to `fetch_ohlcv_cached` + integration verification

**Files:**
- Modify: `analytics/main.py`
- Modify: `analytics/strategies/method_714/mtf.py`
- Modify: `.gitignore` (repo root)
- Test: `analytics/tests/test_backtest_endpoint.py`, `analytics/tests/test_mtf.py` (verify existing mocks still target the right symbol)

**Interfaces:**
- Consumes: `fetch_ohlcv_cached` from `analytics/data/cache.py` (Tasks 1-6, complete).
- Produces: nothing new — this task only rewires existing call sites.

- [ ] **Step 1: Update `main.py`'s import and call site**

In `analytics/main.py`, change:

```python
from data.fetch import fetch_ohlcv, DataFetchError
```

to:

```python
from data.cache import fetch_ohlcv_cached
from data.fetch import DataFetchError
```

and change the fetch call inside `backtest()`:

```python
        df = fetch_ohlcv(
            request.symbol,
            request.asset_class,
            request.start_date,
            request.end_date,
            interval=entry["interval"],
        )
```

to:

```python
        df = fetch_ohlcv_cached(
            request.symbol,
            request.asset_class,
            request.start_date,
            request.end_date,
            interval=entry["interval"],
        )
```

- [ ] **Step 2: Update `mtf.py`'s import and call site**

Read `analytics/strategies/method_714/mtf.py` first to confirm the exact import line and call site, then apply the equivalent change: replace `from data.fetch import fetch_ohlcv` (or wherever it imports from) with `from data.cache import fetch_ohlcv_cached`, and replace the `fetch_ohlcv(...)` call inside `compute_htf_trend` with `fetch_ohlcv_cached(...)`, keeping all positional/keyword arguments identical.

- [ ] **Step 3: Update existing tests that mock the old symbol**

Run: `cd analytics && .venv/bin/pytest tests/test_backtest_endpoint.py tests/test_mtf.py -v`

If any test fails because it patches `main.fetch_ohlcv` or `strategies.method_714.mtf.fetch_ohlcv` (the now-unused import), update that patch target to `main.fetch_ohlcv_cached` / `strategies.method_714.mtf.fetch_ohlcv_cached` respectively, keeping the mock's `return_value`/`side_effect` and assertions otherwise identical — this is a rename of the patch target, not a behavior change, since `fetch_ohlcv_cached` has the same signature and return contract as `fetch_ohlcv`. Do not weaken any assertion to make it pass.

- [ ] **Step 4: Add the SQLite file to `.gitignore`**

Add to `.gitignore` (repo root), under the existing "Python analytics service" section:

```
analytics/data/ohlcv_cache.db*
```

(The trailing `*` covers the `-wal` and `-shm` sidecar files SQLite's WAL mode creates alongside the main `.db` file.)

- [ ] **Step 5: Run the full analytics test suite**

Run: `cd analytics && .venv/bin/pytest -v`
Expected: All tests pass, including the new `test_cache.py` suite and the updated `test_backtest_endpoint.py`/`test_mtf.py`. Fix any other regression found — do not weaken a test's intent to make it pass; fix the mismatch honestly.

- [ ] **Step 6: Manual end-to-end verification**

1. Start the analytics service: `cd analytics && .venv/bin/uvicorn main:app --port 8001`
2. Run a backtest twice in a row with identical params (e.g. `BTC/USDT`, `method_714`, a fixed date range) via curl against `http://localhost:8001/backtest`, and confirm the second call still returns correct `metrics`/`trades` (proves the cached path serves valid data, not just "doesn't crash").
3. Check `analytics/data/ohlcv_cache.db` now exists and has grown after the two calls (`ls -la analytics/data/ohlcv_cache.db`).
4. Run a third backtest with a *wider* date range covering the same symbol (e.g. extend `end_date` by a week) and confirm it still returns correct, larger `trades`/`equity_curve` output (proves forward gap-fill works against real data, not just mocks).
5. Stop the analytics service.

- [ ] **Step 7: Commit**

```bash
git add analytics/main.py analytics/strategies/method_714/mtf.py .gitignore
git commit -m "feat(cache): wire /backtest and MTF fetch through fetch_ohlcv_cached"
```
