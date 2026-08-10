import pandas as pd
from strategies.momentum import generate_signals

# Smaller than DEFAULT_PARAMS so a 100-bar synthetic fixture can exercise
# a real crossover -- production defaults (lookback=252) need a year of
# history, out of scope for a unit-test fixture.
PARAMS = {"lookback": 20, "skip": 5, "roc_window": 5, "roc_threshold": 0.0}


def _uptrend_after_flat_df() -> pd.DataFrame:
    # 40 flat bars (so the 20-bar lookback has clean, non-trending history
    # to compare against), then a steady uptrend -- deterministic single
    # momentum crossover, no mocks needed.
    idx = pd.date_range("2023-01-01", periods=100, freq="D")
    flat = [100.0] * 40
    uptrend = [100.0 + k * 1.5 for k in range(1, 61)]
    close = pd.Series(flat + uptrend, index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def _downtrend_after_flat_df() -> pd.DataFrame:
    idx = pd.date_range("2023-01-01", periods=100, freq="D")
    flat = [100.0] * 40
    downtrend = [100.0 - k * 1.5 for k in range(1, 61)]
    close = pd.Series(flat + downtrend, index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def test_generate_signals_fires_entry_on_momentum_crossover():
    df = _uptrend_after_flat_df()

    entries, exits = generate_signals(df, PARAMS)

    assert entries.iloc[45], "expected entry once the lookback-window momentum crosses above zero"
    assert not entries.iloc[:45].any(), "no entry during the flat section or before the crossover bar"
    assert entries.sum() == 1


def test_generate_signals_fires_exit_on_momentum_breakdown():
    df = _downtrend_after_flat_df()

    entries, exits = generate_signals(df, PARAMS)

    assert exits.iloc[45], "expected exit once the lookback-window momentum crosses below zero"
    assert not exits.iloc[:45].any()
    assert exits.sum() == 1
