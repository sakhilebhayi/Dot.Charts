import pandas as pd
from strategies.bollinger_mean_reversion import generate_signals, DEFAULT_PARAMS


def _mean_reverting_price_series() -> pd.DataFrame:
    # 25 bars of small, stable noise around 100 (establishes tight bands),
    # then one sharp dip well below the lower band, then a recovery back
    # above 100 (crosses back above the middle band/SMA).
    idx = pd.date_range("2023-01-01", periods=40, freq="D")
    stable = [100.0, 100.2, 99.8, 100.1, 99.9] * 5  # 25 bars, tight range
    dip = [90.0]
    recovery = [100.0] * 14
    close = pd.Series(stable + dip + recovery, index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def test_generate_signals_fires_entry_on_lower_band_cross():
    df = _mean_reverting_price_series()

    entries, exits = generate_signals(df, DEFAULT_PARAMS)

    assert entries.iloc[25], "expected entry on the dip bar crossing below the lower band"
    assert not entries.iloc[:25].any(), "no entry during the stable, in-band section"


def test_generate_signals_fires_exit_on_middle_band_cross():
    df = _mean_reverting_price_series()

    entries, exits = generate_signals(df, DEFAULT_PARAMS)

    # Some bar after the dip, once the SMA has caught up enough for close
    # to cross back above it, must fire an exit.
    assert exits.iloc[26:].any(), "expected an exit once price recovers back above the middle band"
