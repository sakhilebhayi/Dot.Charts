import pandas as pd
import pytest
from data.fetch import fetch_ohlcv, DataFetchError


def _fake_yf_download(*args, **kwargs):
    # Matches real yfinance's current shape for a single-symbol download:
    # MultiIndex columns of (field, ticker), not flat field names.
    idx = pd.date_range("2023-01-01", periods=5, freq="D")
    columns = pd.MultiIndex.from_product([["Open", "High", "Low", "Close", "Volume"], ["AAPL"]])
    return pd.DataFrame(
        [
            [100, 101, 99, 100.5, 1000],
            [101, 102, 100, 101.5, 1100],
            [102, 103, 101, 102.5, 1200],
            [103, 104, 102, 103.5, 1300],
            [104, 105, 103, 104.5, 1400],
        ],
        index=idx,
        columns=columns,
    )


def test_fetch_ohlcv_equity_returns_normalized_columns(mocker):
    mocker.patch("data.fetch.yf.download", side_effect=_fake_yf_download)

    df = fetch_ohlcv("AAPL", "equity", "2023-01-01", "2023-01-05")

    assert list(df.columns) == ["open", "high", "low", "close", "volume"]
    assert len(df) == 5
    # Regression: MultiIndex columns must be flattened, not left in place —
    # otherwise df["close"] silently returns a DataFrame, not a Series.
    assert isinstance(df["close"], pd.Series)
    # Regression: yfinance returns a tz-naive index; strategies doing
    # timezone-aware session math (method_714) require tz-aware data.
    assert df.index.tz is not None


def test_fetch_ohlcv_equity_raises_on_empty_result(mocker):
    mocker.patch("data.fetch.yf.download", return_value=pd.DataFrame())

    with pytest.raises(DataFetchError):
        fetch_ohlcv("BADSYMBOL", "equity", "2023-01-01", "2023-01-05")


def test_fetch_ohlcv_crypto_returns_normalized_columns(mocker):
    fake_exchange = mocker.Mock()
    fake_exchange.parse8601 = lambda s: 0 if "01-01" in s else 5 * 86_400_000
    fake_exchange.fetch_ohlcv.side_effect = [
        [
            [i * 86_400_000, 100 + i, 101 + i, 99 + i, 100.5 + i, 1000 + i]
            for i in range(5)
        ],
        [],
    ]
    mocker.patch("data.fetch.ccxt.binance", return_value=fake_exchange)

    df = fetch_ohlcv("BTC/USDT", "crypto", "2023-01-01", "2023-01-05")

    assert list(df.columns) == ["open", "high", "low", "close", "volume"]
    assert len(df) == 5
    # Regression: ccxt's ms-epoch timestamps parse to a tz-naive index;
    # strategies doing timezone-aware session math (method_714) require
    # tz-aware data.
    assert df.index.tz is not None


def test_fetch_ohlcv_commodity_reuses_the_yfinance_path(mocker):
    mocker.patch("data.fetch.yf.download", side_effect=_fake_yf_download)

    df = fetch_ohlcv("GC=F", "commodity", "2023-01-01", "2023-01-05")

    assert list(df.columns) == ["open", "high", "low", "close", "volume"]
    assert len(df) == 5
    assert df.index.tz is not None


def test_fetch_ohlcv_forex_reuses_the_yfinance_path(mocker):
    mocker.patch("data.fetch.yf.download", side_effect=_fake_yf_download)

    df = fetch_ohlcv("EURUSD=X", "forex", "2023-01-01", "2023-01-05")

    assert list(df.columns) == ["open", "high", "low", "close", "volume"]
    assert len(df) == 5
    assert df.index.tz is not None


def test_fetch_ohlcv_unsupported_asset_class_raises():
    with pytest.raises(DataFetchError):
        fetch_ohlcv("AAPL", "not_a_real_asset_class", "2023-01-01", "2023-01-05")
