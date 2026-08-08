import math

import pandas as pd
from analysis.chart_analysis import compute_chart_analysis


def _bullish_structure_df() -> pd.DataFrame:
    # 60 bars of a real (non-tied) sine-wave zigzag around 100 -- unlike a
    # short repeating pattern, this never has exact ties within a pivot
    # window, so compute_swing_pivots' strict-uniqueness check actually
    # confirms real swing highs/lows. Then a clean uptrend for 60 bars
    # breaks above the most recent zigzag high, producing a deterministic
    # bullish structure break -- no mocks needed beyond fetch_ohlcv_cached.
    idx = pd.date_range("2023-01-01", periods=120, freq="D", tz="UTC")
    flat = [100.0 + 2 * math.sin(i / 3) for i in range(60)]
    uptrend = [flat[-1] + i * 1.5 for i in range(1, 61)]
    close = pd.Series(flat + uptrend, index=idx)
    high = close + 0.5
    low = close - 0.5
    return pd.DataFrame({"open": close, "high": high, "low": low, "close": close, "volume": 1000})


def test_compute_chart_analysis_detects_bullish_trend_and_structure(mocker):
    mocker.patch(
        "analysis.chart_analysis.fetch_ohlcv_cached",
        return_value=_bullish_structure_df(),
    )

    result = compute_chart_analysis("AAPL", "equity", interval="1d")

    assert result["trend"] == "Bullish"
    assert result["signal"] == "Buy"
    assert result["confidence"] > 50
    assert len(result["supports"]) > 0
    assert len(result["resistances"]) > 0
    assert "structure" in result["summary"].lower()
    assert "714" in result["summary"] or "not" in result["summary"].lower()


def test_compute_chart_analysis_detects_bearish_trend_and_structure(mocker):
    idx = pd.date_range("2023-01-01", periods=120, freq="D", tz="UTC")
    flat = [200.0 - 2 * math.sin(i / 3) for i in range(60)]
    downtrend = [flat[-1] - i * 1.5 for i in range(1, 61)]
    close = pd.Series(flat + downtrend, index=idx)
    high = close + 0.5
    low = close - 0.5
    df = pd.DataFrame({"open": close, "high": high, "low": low, "close": close, "volume": 1000})
    mocker.patch("analysis.chart_analysis.fetch_ohlcv_cached", return_value=df)

    result = compute_chart_analysis("AAPL", "equity", interval="1d")

    assert result["trend"] == "Bearish"
    assert result["signal"] == "Sell"
