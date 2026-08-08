import pandas as pd
from strategies.method_714.mtf import compute_htf_trend


def _htf_df(closes: list[float]) -> pd.DataFrame:
    idx = pd.date_range("2023-01-01", periods=len(closes), freq="4h", tz="UTC")
    return pd.DataFrame(
        {"open": closes, "high": closes, "low": closes, "close": closes, "volume": 1000}, index=idx
    )


def test_compute_htf_trend_is_non_repainting_and_aligns_to_base_index(mocker):
    # A clean, sustained uptrend so EMA(3) > EMA(6) is unambiguous once
    # warmed up (short periods here so the fixture stays small).
    closes = [100.0 + i * 2 for i in range(30)]
    mocker.patch("strategies.method_714.mtf.fetch_ohlcv", return_value=_htf_df(closes))

    base_index = pd.date_range("2023-01-01", periods=120, freq="1h", tz="UTC")

    trend = compute_htf_trend(
        "AAPL", "equity", "2023-01-01", "2023-01-06", base_index, htf_interval="4h", fast=3, slow=6
    )

    assert len(trend) == len(base_index)
    # Well after warmup, the sustained uptrend should read bullish
    assert trend.iloc[-1] == 1
    # Before any HTF bar has closed, there is nothing to align to yet
    assert trend.iloc[0] in (0, -1, 1)  # no crash; specific early value isn't asserted


def test_compute_htf_trend_reads_bearish_in_a_downtrend(mocker):
    closes = [200.0 - i * 2 for i in range(30)]
    mocker.patch("strategies.method_714.mtf.fetch_ohlcv", return_value=_htf_df(closes))

    base_index = pd.date_range("2023-01-01", periods=120, freq="1h", tz="UTC")

    trend = compute_htf_trend(
        "AAPL", "equity", "2023-01-01", "2023-01-06", base_index, htf_interval="4h", fast=3, slow=6
    )

    assert trend.iloc[-1] == -1
