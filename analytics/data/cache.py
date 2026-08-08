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

        cached_min, cached_max = coverage

        if start_ms < cached_min:
            gap_end_date = pd.Timestamp(cached_min, unit="ms", tz="UTC").strftime("%Y-%m-%d")
            backward_df = fetch_ohlcv(symbol, asset_class, start_date, gap_end_date, interval)
            _insert_bars(conn, symbol, asset_class, interval, backward_df)

        if end_ms > cached_max:
            gap_start_date = pd.Timestamp(cached_max, unit="ms", tz="UTC").strftime("%Y-%m-%d")
            forward_df = fetch_ohlcv(symbol, asset_class, gap_start_date, end_date, interval)
            _insert_bars(conn, symbol, asset_class, interval, forward_df)

        if start_ms <= cached_max <= end_ms:
            # The bar at the old cached_max may still have been in progress
            # when it was cached; re-fetch and overwrite it on every request
            # that touches it, regardless of whether that also happened to
            # extend forward above -- see the design spec's Gap-Fill
            # Algorithm step 4 for why this isn't conditioned on wall-clock
            # "now".
            tail_date = pd.Timestamp(cached_max, unit="ms", tz="UTC").strftime("%Y-%m-%d")
            tail_df = fetch_ohlcv(symbol, asset_class, tail_date, end_date, interval)
            _insert_bars(conn, symbol, asset_class, interval, tail_df)

        return _query_range(conn, symbol, asset_class, interval, start_ms, end_ms)
    finally:
        conn.close()
