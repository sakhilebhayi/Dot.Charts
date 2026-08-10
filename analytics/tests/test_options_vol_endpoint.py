import pandas as pd
from fastapi.testclient import TestClient
from main import app

client = TestClient(app)


def _future_expiry(days: int = 30) -> str:
    return (pd.Timestamp.utcnow() + pd.Timedelta(days=days)).strftime("%Y-%m-%d")


def test_options_vol_signal_returns_full_shape(mocker):
    calls = pd.DataFrame({"strike": [150], "impliedVolatility": [0.25], "lastPrice": [5]})
    puts = pd.DataFrame({"strike": [150], "impliedVolatility": [0.28], "lastPrice": [5]})
    mocker.patch("main.compute_vol_signal", return_value={
        "symbol": "AAPL",
        "asset_class": "equity",
        "spot": 150.0,
        "expiry_used": _future_expiry(),
        "realized_vol": {"current_annualized_pct": 30.0, "rank_pct": 70.0, "window_days": 20},
        "skew": {"call_strike": 150.0, "call_iv": 0.25, "put_strike": 150.0, "put_iv": 0.28, "skew": 0.03},
        "vol_regime": "normal",
        "skew_regime": "balanced",
        "as_of": pd.Timestamp.utcnow().isoformat(),
    })

    response = client.get("/options/vol-signal/AAPL")

    assert response.status_code == 200
    body = response.json()
    assert body["symbol"] == "AAPL"
    assert body["vol_regime"] == "normal"
    assert body["skew"]["skew"] == 0.03


def test_options_vol_signal_returns_422_on_options_data_error(mocker):
    from analysis.options_vol import OptionsDataError

    mocker.patch("main.compute_vol_signal", side_effect=OptionsDataError("No options chain available for symbol 'BADSYMBOL'"))

    response = client.get("/options/vol-signal/BADSYMBOL")

    assert response.status_code == 422
    assert "BADSYMBOL" in response.json()["detail"]


def test_options_vol_signal_defaults_asset_class_to_equity(mocker):
    mock = mocker.patch("main.compute_vol_signal", return_value={
        "symbol": "AAPL",
        "asset_class": "equity",
        "spot": 150.0,
        "expiry_used": _future_expiry(),
        "realized_vol": {"current_annualized_pct": 30.0, "rank_pct": 70.0, "window_days": 20},
        "skew": {"call_strike": 150.0, "call_iv": 0.25, "put_strike": 150.0, "put_iv": 0.28, "skew": 0.03},
        "vol_regime": "normal",
        "skew_regime": "balanced",
        "as_of": pd.Timestamp.utcnow().isoformat(),
    })

    client.get("/options/vol-signal/AAPL")

    assert mock.call_args.args[1] == "equity"
