import pandas as pd
from strategies.breakout import generate_signals, DEFAULT_PARAMS


def _breakout_price_series() -> pd.DataFrame:
    # 25 flat bars (establishes a stable 20-bar Donchian channel), then a
    # sharp one-bar spike above the prior channel high on bar 25, then
    # flat again -- deterministic single breakout, no mocks needed.
    idx = pd.date_range("2023-01-01", periods=40, freq="D")
    flat_before = [100.0] * 25
    spike = [110.0]
    flat_after = [100.5] * 14
    close = pd.Series(flat_before + spike + flat_after, index=idx)
    high = close + 0.5
    low = close - 0.5
    return pd.DataFrame({"open": close, "high": high, "low": low, "close": close, "volume": 1000})


def test_generate_signals_fires_entry_on_channel_breakout():
    df = _breakout_price_series()

    entries, exits = generate_signals(df, DEFAULT_PARAMS)

    assert entries.iloc[25], "expected entry on the spike bar breaking the prior 20-bar high"
    assert not entries.iloc[:25].any(), "no entry before the channel is established or before the spike"
    assert entries.sum() == 1


def test_generate_signals_fires_exit_on_channel_breakdown():
    # Mirror fixture: flat, then a sharp one-bar drop below the prior
    # 10-bar low, then flat again.
    idx = pd.date_range("2023-01-01", periods=40, freq="D")
    flat_before = [100.0] * 25
    drop = [90.0]
    flat_after = [99.5] * 14
    close = pd.Series(flat_before + drop + flat_after, index=idx)
    high = close + 0.5
    low = close - 0.5
    df = pd.DataFrame({"open": close, "high": high, "low": low, "close": close, "volume": 1000})

    entries, exits = generate_signals(df, DEFAULT_PARAMS)

    assert exits.iloc[25], "expected exit on the drop bar breaking the prior 10-bar low"
    assert not exits.iloc[:25].any()
