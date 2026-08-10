import pandas as pd
from fastapi.testclient import TestClient
from main import app

client = TestClient(app)


def _synthetic_uptrend_df():
    # tz-aware, matching fetch_ohlcv's real contract (every OHLCV frame it
    # returns is localized to UTC) — method_714's session math requires it.
    idx = pd.date_range("2023-01-01", periods=100, freq="D", tz="UTC")
    flat = [100.0] * 60
    uptrend = [100.0 + i * 2 for i in range(1, 41)]
    close = pd.Series(flat + uptrend, index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def test_backtest_ma_crossover_returns_metrics_and_trades(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

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


def test_backtest_method_714_fetches_intraday_data(mocker):
    # method_714's session logic (07:00-08:00-style windows) needs intraday
    # bars — daily bars are always midnight and never fall inside a session
    # window, silently producing zero trades. The endpoint must request an
    # intraday interval for this strategy specifically.
    fetch_mock = mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "BTC/USDT",
            "asset_class": "crypto",
            "strategy": "method_714",
            # EMA(200) needs 200+ bars; disable it since the fixture is 100 bars
            # and this test only cares about the fetch_ohlcv call arguments.
            "params": {"use_ema_filter": False},
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 200
    fetch_mock.assert_called_once_with("BTC/USDT", "crypto", "2023-01-01", "2023-04-10", interval="1h")


def test_backtest_ma_crossover_fetches_daily_data(mocker):
    fetch_mock = mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "ma_crossover",
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    fetch_mock.assert_called_once_with("AAPL", "equity", "2023-01-01", "2023-04-10", interval="1d")


def test_backtest_commodity_returns_metrics_and_trades(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "GC=F",
            "asset_class": "commodity",
            "strategy": "ma_crossover",
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["asset_class"] == "commodity"
    assert body["symbol"] == "GC=F"


def test_backtest_breakout_returns_metrics_and_trades(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "breakout",
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["strategy"] == "breakout"
    assert "metrics" in body
    assert "trade_count" in body["metrics"]


def test_backtest_bollinger_mean_reversion_returns_metrics_and_trades(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "bollinger_mean_reversion",
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["strategy"] == "bollinger_mean_reversion"
    assert "metrics" in body
    assert "trade_count" in body["metrics"]


def test_backtest_forex_returns_metrics_and_trades(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "EURUSD=X",
            "asset_class": "forex",
            "strategy": "ma_crossover",
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["asset_class"] == "forex"
    assert body["symbol"] == "EURUSD=X"


def test_backtest_unknown_strategy_returns_422(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

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


def test_backtest_custom_strategy_returns_metrics_and_trades(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "custom",
            "params": {
                "entry": {
                    "combinator": "all",
                    "conditions": [
                        {"left": {"indicator": "ema", "length": 5}, "comparator": "crosses_above", "right": {"indicator": "ema", "length": 20}},
                    ],
                },
                "exit": {
                    "combinator": "all",
                    "conditions": [
                        {"left": {"indicator": "ema", "length": 5}, "comparator": "crosses_below", "right": {"indicator": "ema", "length": 20}},
                    ],
                },
            },
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["strategy"] == "custom"
    assert "metrics" in body
    assert "trade_count" in body["metrics"]


def test_backtest_custom_strategy_returns_422_on_invalid_rule(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "custom",
            "params": {"entry": {"combinator": "all", "conditions": []}, "exit": {"combinator": "all", "conditions": []}},
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 422


def test_backtest_data_fetch_error_returns_422(mocker):
    from data.fetch import DataFetchError

    mocker.patch("main.fetch_ohlcv_cached", side_effect=DataFetchError("No equity data for symbol 'BADSYMBOL'"))

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


def test_backtest_momentum_returns_metrics_and_trades(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "AAPL",
            "asset_class": "equity",
            "strategy": "momentum",
            "params": {"lookback": 20, "skip": 5, "roc_window": 5, "roc_threshold": 0.0},
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["strategy"] == "momentum"
    assert "metrics" in body
    assert "trade_count" in body["metrics"]
