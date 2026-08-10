import numpy as np
import pandas as pd
import pytest

import analysis.options_vol as options_vol
from data.fetch import DataFetchError


def _underlying_df(seed: int = 1, periods: int = 280) -> pd.DataFrame:
    idx = pd.date_range("2024-01-01", periods=periods, freq="D", tz="UTC")
    rng = np.random.default_rng(seed)
    close = pd.Series(150 + np.cumsum(rng.normal(0, 1.5, periods)), index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def _future_expiry(days: int = 30) -> str:
    # Always computed relative to "now" rather than a hardcoded date --
    # yfinance's real `ticker.options` only ever lists future expiries, and
    # a hardcoded past date silently breaks the Black-Scholes fallback path
    # (T<=0 makes bs_implied_vol refuse to solve), which is exactly what
    # happened prototyping this test before this fixture existed.
    return (pd.Timestamp.utcnow() + pd.Timedelta(days=days)).strftime("%Y-%m-%d")


def test_compute_vol_signal_reports_elevated_put_skew_from_yfinance_iv(mocker):
    calls = pd.DataFrame({"strike": [145, 150, 155], "impliedVolatility": [0.25, 0.22, 0.20], "lastPrice": [8, 5, 3]})
    puts = pd.DataFrame({"strike": [145, 150, 155], "impliedVolatility": [0.30, 0.28, 0.26], "lastPrice": [3, 5, 8]})
    mocker.patch("analysis.options_vol._fetch_option_chain", return_value=(_future_expiry(), calls, puts, 150.0))
    mocker.patch("analysis.options_vol.fetch_ohlcv_cached", return_value=_underlying_df())

    result = options_vol.compute_vol_signal("AAPL", "equity", options_vol.DEFAULT_PARAMS)

    assert result["symbol"] == "AAPL"
    assert result["spot"] == 150.0
    assert result["skew"]["call_strike"] == 150.0
    assert result["skew"]["put_strike"] == 150.0
    assert result["skew"]["skew"] == pytest.approx(0.06, abs=1e-6)
    assert result["skew_regime"] == "put_skew_elevated"


def test_compute_vol_signal_falls_back_to_black_scholes_when_iv_is_zero(mocker):
    # impliedVolatility=0 is yfinance's signal for a stale/illiquid quote --
    # the module must recover an IV estimate from lastPrice via
    # Black-Scholes instead of treating 0% vol as real.
    calls = pd.DataFrame({"strike": [150], "impliedVolatility": [0.0], "lastPrice": [5.5]})
    puts = pd.DataFrame({"strike": [150], "impliedVolatility": [0.0], "lastPrice": [5.0]})
    mocker.patch("analysis.options_vol._fetch_option_chain", return_value=(_future_expiry(), calls, puts, 150.0))
    mocker.patch("analysis.options_vol.fetch_ohlcv_cached", return_value=_underlying_df())

    result = options_vol.compute_vol_signal("AAPL", "equity", options_vol.DEFAULT_PARAMS)

    assert result["skew"]["call_iv"] > 0
    assert result["skew"]["put_iv"] > 0


@pytest.mark.parametrize(
    "rank_pct,expected_regime",
    [(95.0, "elevated"), (50.0, "normal"), (5.0, "low")],
)
def test_vol_regime_classification_thresholds(mocker, rank_pct, expected_regime):
    calls = pd.DataFrame({"strike": [150], "impliedVolatility": [0.25], "lastPrice": [5]})
    puts = pd.DataFrame({"strike": [150], "impliedVolatility": [0.25], "lastPrice": [5]})
    mocker.patch("analysis.options_vol._fetch_option_chain", return_value=(_future_expiry(), calls, puts, 150.0))
    mocker.patch("analysis.options_vol.fetch_ohlcv_cached", return_value=_underlying_df())
    mocker.patch(
        "analysis.options_vol._realized_vol_rank",
        return_value={"current_annualized_pct": 30.0, "rank_pct": rank_pct, "window_days": 20},
    )

    result = options_vol.compute_vol_signal("AAPL", "equity", options_vol.DEFAULT_PARAMS)

    assert result["vol_regime"] == expected_regime


def test_compute_vol_signal_wraps_underlying_data_fetch_failure(mocker):
    calls = pd.DataFrame({"strike": [150], "impliedVolatility": [0.25], "lastPrice": [5]})
    puts = pd.DataFrame({"strike": [150], "impliedVolatility": [0.25], "lastPrice": [5]})
    mocker.patch("analysis.options_vol._fetch_option_chain", return_value=(_future_expiry(), calls, puts, 150.0))
    mocker.patch(
        "analysis.options_vol.fetch_ohlcv_cached",
        side_effect=DataFetchError("No equity data for symbol 'BADSYMBOL'"),
    )

    with pytest.raises(options_vol.OptionsDataError, match="BADSYMBOL"):
        options_vol.compute_vol_signal("BADSYMBOL", "equity", options_vol.DEFAULT_PARAMS)
