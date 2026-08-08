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
