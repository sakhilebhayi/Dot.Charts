import pandas as pd
from fastapi.testclient import TestClient
from main import app

client = TestClient(app)


def _synthetic_uptrend_df():
    idx = pd.date_range("2023-01-01", periods=100, freq="D")
    flat = [100.0] * 60
    uptrend = [100.0 + i * 2 for i in range(1, 41)]
    close = pd.Series(flat + uptrend, index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def test_backtest_ma_crossover_returns_metrics_and_trades(mocker):
    mocker.patch("main.fetch_ohlcv", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "ma_crossover",
            "params": {"fast_window": 20, "slow_window": 50},
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["strategy"] == "ma_crossover"
    assert "metrics" in body
    assert "trade_count" in body["metrics"]
    assert "losing_trade_count" in body["metrics"]
    assert "equity_curve" in body
    assert "trades" in body


def test_backtest_unknown_strategy_returns_422(mocker):
    mocker.patch("main.fetch_ohlcv", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "not_a_real_strategy",
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 422


def test_backtest_data_fetch_error_returns_422(mocker):
    from data.fetch import DataFetchError

    mocker.patch("main.fetch_ohlcv", side_effect=DataFetchError("No equity data for symbol 'BADSYMBOL'"))

    response = client.post(
        "/backtest",
        json={
            "symbol": "BADSYMBOL",
            "asset_class": "equity",
            "strategy": "ma_crossover",
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 422
    assert "BADSYMBOL" in response.json()["detail"]
