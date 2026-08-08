import math

import pandas as pd
from fastapi.testclient import TestClient
from main import app

client = TestClient(app)


def _bullish_df():
    idx = pd.date_range("2023-01-01", periods=120, freq="D", tz="UTC")
    flat = [100.0 + 2 * math.sin(i / 3) for i in range(60)]
    uptrend = [flat[-1] + i * 1.5 for i in range(1, 61)]
    close = pd.Series(flat + uptrend, index=idx)
    high = close + 0.5
    low = close - 0.5
    return pd.DataFrame({"open": close, "high": high, "low": low, "close": close, "volume": 1000})


def test_chart_analysis_returns_computed_result(mocker):
    # Patched where fetch_ohlcv_cached is actually looked up at call time --
    # chart_analysis.py's own module namespace, not main.py's (main.py
    # never calls it directly; compute_chart_analysis does).
    mocker.patch("analysis.chart_analysis.fetch_ohlcv_cached", return_value=_bullish_df())

    response = client.post(
        "/chart-analysis",
        json={"symbol": "AAPL", "asset_class": "equity", "interval": "1d"},
    )

    assert response.status_code == 200
    body = response.json()
    assert body["trend"] == "Bullish"
    assert body["signal"] == "Buy"
    assert "confidence" in body
    assert "supports" in body
    assert "resistances" in body


def test_chart_analysis_returns_422_on_fetch_failure(mocker):
    from data.fetch import DataFetchError

    mocker.patch(
        "analysis.chart_analysis.fetch_ohlcv_cached",
        side_effect=DataFetchError("No equity data for symbol 'BADSYMBOL'"),
    )

    response = client.post(
        "/chart-analysis",
        json={"symbol": "BADSYMBOL", "asset_class": "equity", "interval": "1d"},
    )

    assert response.status_code == 422
