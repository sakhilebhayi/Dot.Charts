import pandas as pd
from strategies.ma_crossover import generate_signals, DEFAULT_PARAMS


def _trending_price_series() -> pd.DataFrame:
    # Flat for 60 bars, then a clean uptrend for 40 bars — guarantees a
    # fast/slow SMA crossover partway through, deterministic and mock-free.
    idx = pd.date_range("2023-01-01", periods=100, freq="D")
    flat = [100.0] * 60
    uptrend = [100.0 + i * 2 for i in range(1, 41)]
    close = pd.Series(flat + uptrend, index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def test_generate_signals_fires_entry_on_crossover():
    df = _trending_price_series()

    entries, exits = generate_signals(df, DEFAULT_PARAMS)

    assert entries.any(), "expected at least one entry signal during the uptrend"
    assert entries.sum() >= 1
    # No entries during the flat section where fast == slow MA
    assert not entries.iloc[:60].any()
