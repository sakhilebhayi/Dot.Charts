import pandas as pd
import pytest
from data.fetch import fetch_ohlcv, DataFetchError


def _fake_yf_download(*args, **kwargs):
    idx = pd.date_range("2023-01-01", periods=5, freq="D")
    return pd.DataFrame(
        {
            "Open": [100, 101, 102, 103, 104],
            "High": [101, 102, 103, 104, 105],
            "Low": [99, 100, 101, 102, 103],
            "Close": [100.5, 101.5, 102.5, 103.5, 104.5],
            "Volume": [1000, 1100, 1200, 1300, 1400],
        },
        index=idx,
    )


def test_fetch_ohlcv_equity_returns_normalized_columns(mocker):
    mocker.patch("data.fetch.yf.download", side_effect=_fake_yf_download)

    df = fetch_ohlcv("AAPL", "equity", "2023-01-01", "2023-01-05")

    assert list(df.columns) == ["open", "high", "low", "close", "volume"]
    assert len(df) == 5


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


def test_fetch_ohlcv_unsupported_asset_class_raises():
    with pytest.raises(DataFetchError):
        fetch_ohlcv("AAPL", "commodity", "2023-01-01", "2023-01-05")
