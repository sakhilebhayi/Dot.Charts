import numpy as np
import pandas as pd
from strategies.rsi_mean_reversion import generate_signals, DEFAULT_PARAMS


def _oversold_bounce_series() -> pd.DataFrame:
    # A sine-wave price series oscillates RSI cleanly between oversold (<30)
    # and overbought (>70) territory with a long enough period (60 bars) for
    # the RSI(14) average to stabilize between swings — deterministic
    # entry/exit crossings without mocking data.
    idx = pd.date_range("2023-01-01", periods=150, freq="D")
    t = np.arange(150)
    close = pd.Series(100 + 20 * np.sin(2 * np.pi * t / 60), index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def test_generate_signals_fires_entry_after_oversold_and_exit_after_overbought():
    df = _oversold_bounce_series()

    entries, exits = generate_signals(df, DEFAULT_PARAMS)

    assert entries.any(), "expected an entry signal after the RSI dips below 30 and recovers"
    assert exits.any(), "expected an exit signal after the RSI climbs above 70"
