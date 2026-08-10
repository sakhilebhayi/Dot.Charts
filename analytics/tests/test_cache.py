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


def test_fetch_ohlcv_cached_cold_cache_fetches_full_range_once(tmp_path, mocker):
    db_path = tmp_path / "test_cache.db"
    live_df = _sample_df()
    mock_fetch = mocker.patch("data.cache.fetch_ohlcv", return_value=live_df)

    result = cache.fetch_ohlcv_cached(
        "AAPL", "equity", "2023-01-01", "2023-01-03", interval="1d", db_path=db_path,
    )

    mock_fetch.assert_called_once_with("AAPL", "equity", "2023-01-01", "2023-01-03", "1d")
    assert len(result) == 3
    assert list(result.columns) == ["open", "high", "low", "close", "volume"]


def test_fetch_ohlcv_cached_fully_covered_request_only_refreshes_tail_bar(tmp_path, mocker):
    db_path = tmp_path / "test_cache.db"
    conn = cache._connect(db_path)
    cache._insert_bars(conn, "AAPL", "equity", "1d", _sample_df())  # covers 01-01..03
    conn.close()

    tail_df = _sample_df().iloc[[-1]]  # just the 01-03 bar, freshly "live"
    mock_fetch = mocker.patch("data.cache.fetch_ohlcv", return_value=tail_df)

    result = cache.fetch_ohlcv_cached(
        "AAPL", "equity", "2023-01-01", "2023-01-03", interval="1d", db_path=db_path,
    )

    # No fetch for the already-covered historical range -- only the tail
    # refresh, padded to "2023-01-04" since a same-day window would return
    # zero rows from a real exclusive-end data provider (see
    # _safe_fetch_window).
    mock_fetch.assert_called_once_with("AAPL", "equity", "2023-01-03", "2023-01-04", "1d")
    assert len(result) == 3


def test_fetch_ohlcv_cached_tail_refresh_does_not_request_a_zero_width_window(tmp_path, mocker):
    # Regression: yfinance's `end` and ccxt's since-loop upper bound are
    # both exclusive of the boundary date, so a real live fetch with
    # start_date == end_date returns zero rows and raises DataFetchError.
    # A fully-covered repeat request's tail refresh must never hand the
    # live fetcher such a window, or every repeat backtest would 422.
    db_path = tmp_path / "test_cache.db"
    conn = cache._connect(db_path)
    cache._insert_bars(conn, "AAPL", "equity", "1d", _sample_df())  # covers 01-01..03
    conn.close()

    def fake_fetch_like_real_yfinance(symbol, asset_class, start_date, end_date, interval):
        if start_date == end_date:
            raise cache.DataFetchError(f"No equity data for symbol '{symbol}'")
        return _sample_df().iloc[[-1]]

    mocker.patch("data.cache.fetch_ohlcv", side_effect=fake_fetch_like_real_yfinance)

    result = cache.fetch_ohlcv_cached(
        "AAPL", "equity", "2023-01-01", "2023-01-03", interval="1d", db_path=db_path,
    )

    assert len(result) == 3


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


def test_fetch_ohlcv_cached_rejects_a_range_wider_than_the_max_before_any_fetch(
    tmp_path, mocker,
):
    # Found during an audit: /backtest's date range was otherwise unbounded,
    # and crypto's manual since-loop pagination turns a wide range into an
    # unbounded number of real upstream calls held open on one request --
    # this must fail fast, before touching the network or the cache DB at
    # all, for every asset class (not just crypto).
    db_path = tmp_path / "test_cache.db"
    mock_fetch = mocker.patch("data.cache.fetch_ohlcv")

    with pytest.raises(cache.DataFetchError, match="more than 1825 days"):
        cache.fetch_ohlcv_cached(
            "AAPL", "equity", "2010-01-01", "2023-01-03", interval="1d", db_path=db_path,
        )

    mock_fetch.assert_not_called()
    assert not db_path.exists()  # never even opened a connection


def test_fetch_ohlcv_cached_allows_a_range_exactly_at_the_max(tmp_path, mocker):
    db_path = tmp_path / "test_cache.db"
    live_df = _sample_df()
    mocker.patch("data.cache.fetch_ohlcv", return_value=live_df)

    start = pd.Timestamp("2023-01-01", tz="UTC")
    end = start + pd.Timedelta(days=cache.MAX_RANGE_DAYS)

    result = cache.fetch_ohlcv_cached(
        "AAPL", "equity", start.strftime("%Y-%m-%d"), end.strftime("%Y-%m-%d"),
        interval="1d", db_path=db_path,
    )

    assert len(result) == 3  # the mocked fetch response, not a rejection


def test_fetch_ohlcv_cached_gap_fill_failure_is_best_effort_and_still_serves_cached_data(
    tmp_path, mocker,
):
    # A gap-fill failure past the cold-cache fetch is best-effort, not
    # fail-closed: the symbol/asset_class are already proven valid by
    # whatever originally populated the cache, and a real provider raises
    # this same DataFetchError for a legitimately-empty sub-range (e.g. a
    # weekend/holiday just before a symbol's first trading day) as it does
    # for an actual outage -- so failing the whole request here would mean
    # a repeat request with the same start_date 422s forever.
    db_path = tmp_path / "test_cache.db"
    conn = cache._connect(db_path)
    cache._insert_bars(conn, "AAPL", "equity", "1d", _sample_df())  # covers 01-01..03
    conn.close()

    mocker.patch("data.cache.fetch_ohlcv", side_effect=cache.DataFetchError("no data"))

    result = cache.fetch_ohlcv_cached(
        "AAPL", "equity", "2022-12-30", "2023-01-03", interval="1d", db_path=db_path,
    )

    # The backward gap (12-30, 12-31) was never filled, so only the
    # already-cached 3 days come back -- but the request succeeds.
    assert len(result) == 3

    conn = cache._connect(db_path)
    coverage = cache._get_coverage(conn, "AAPL", "equity", "1d")
    expected_min = int(pd.Timestamp("2023-01-01", tz="UTC").value // 1_000_000)
    expected_max = int(pd.Timestamp("2023-01-03", tz="UTC").value // 1_000_000)
    assert coverage == (expected_min, expected_max)  # unchanged -- no partial backward write
    conn.close()
