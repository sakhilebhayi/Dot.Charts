import numpy as np
import pandas as pd
from strategies.pairs_trading import generate_signals, run, DEFAULT_PARAMS


def _cointegrated_pair_with_divergence() -> tuple[pd.DataFrame, pd.DataFrame]:
    # B drifts like a random walk; A = B + a mean-reverting AR(1) spread,
    # so A and B are genuinely cointegrated by construction. One large,
    # one-off shock is injected into the spread at bar 70 -- the AR(1)
    # process pulls it back toward zero afterward, giving a deterministic
    # divergence-then-reversion event. Fixed seed (3) for reproducibility.
    n = 150
    idx = pd.date_range("2023-01-01", periods=n, freq="D")
    rng = np.random.default_rng(3)

    b_close = pd.Series(100 + np.cumsum(rng.normal(0.03, 0.25, n)), index=idx)

    spread = np.zeros(n)
    for i in range(1, n):
        spread[i] = 0.7 * spread[i - 1] + rng.normal(0, 0.15)
    spread[70] -= 4.0

    a_close = pd.Series(b_close.values + spread, index=idx)

    df_a = pd.DataFrame({"open": a_close, "high": a_close, "low": a_close, "close": a_close, "volume": 1000})
    df_b = pd.DataFrame({"open": b_close, "high": b_close, "low": b_close, "close": b_close, "volume": 1000})
    return df_a, df_b


def _uncorrelated_pair() -> tuple[pd.DataFrame, pd.DataFrame]:
    # Two independent random walks -- not cointegrated. Fixed seed (11).
    n = 150
    idx = pd.date_range("2023-01-01", periods=n, freq="D")
    rng = np.random.default_rng(11)

    a_close = pd.Series(100 + np.cumsum(rng.normal(0.05, 0.5, n)), index=idx)
    b_close = pd.Series(50 + np.cumsum(rng.normal(-0.02, 0.6, n)), index=idx)

    df_a = pd.DataFrame({"open": a_close, "high": a_close, "low": a_close, "close": a_close, "volume": 1000})
    df_b = pd.DataFrame({"open": b_close, "high": b_close, "low": b_close, "close": b_close, "volume": 1000})
    return df_a, df_b


def test_generate_signals_fires_entry_on_divergence():
    df_a, df_b = _cointegrated_pair_with_divergence()

    entries, exits, _spread = generate_signals(df_a, df_b, DEFAULT_PARAMS)

    assert entries.iloc[70], "expected entry when the z-score crosses below -entry_z at the injected shock"
    assert not entries.iloc[:70].any(), "no entry before the shock bar"


def test_run_produces_one_round_trip_trade_through_the_divergence_and_reversion():
    # Signal-level assertions alone don't capture real behavior here --
    # the exit condition can fire spuriously while flat (ordinary AR(1)
    # noise crosses the +-exit_z band often), which vectorbt correctly
    # ignores since there's no open position to close. Assert on the
    # actual portfolio's trades instead, which is what a caller acts on.
    df_a, df_b = _cointegrated_pair_with_divergence()

    portfolio = run(df_a, df_b, DEFAULT_PARAMS)
    trades = portfolio.trades.records_readable

    assert len(trades) == 1
    assert str(trades.iloc[0]["Entry Timestamp"]) == "2023-03-12 00:00:00"
    assert str(trades.iloc[0]["Exit Timestamp"]) == "2023-03-24 00:00:00"


def test_generate_signals_returns_no_trades_for_uncointegrated_pair():
    df_a, df_b = _uncorrelated_pair()

    entries, exits, _spread = generate_signals(df_a, df_b, DEFAULT_PARAMS)

    assert not entries.any(), "the cointegration gate should reject an unrelated pair before any signal fires"
    assert not exits.any()
