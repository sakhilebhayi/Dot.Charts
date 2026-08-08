import pandas as pd
from strategies.method_714.smc import compute_swing_pivots, compute_structure


def _flat_df_with_spike(spike_high_at: int, spike_high_value: float, n: int = 11) -> pd.DataFrame:
    idx = pd.date_range("2023-01-01", periods=n, freq="1h", tz="UTC")
    highs = [10.0] * n
    highs[spike_high_at] = spike_high_value
    lows = [9.0] * n
    closes = [9.5] * n
    return pd.DataFrame({"open": closes, "high": highs, "low": lows, "close": closes, "volume": 1}, index=idx)


def test_compute_swing_pivots_confirms_a_high_piv_len_bars_later():
    df = _flat_df_with_spike(spike_high_at=3, spike_high_value=15.0, n=11)

    out = compute_swing_pivots(df, piv_len=2)

    confirm_idx = df.index[3 + 2]
    assert out.loc[confirm_idx, "swing_high"] == 15.0
    # No pivot recorded anywhere else
    assert (out["swing_high"].dropna() == 15.0).all()
    assert out["swing_high"].notna().sum() == 1


def test_compute_swing_pivots_confirms_a_low_piv_len_bars_later():
    idx = pd.date_range("2023-01-01", periods=11, freq="1h", tz="UTC")
    highs = [10.0] * 11
    lows = [9.0] * 11
    lows[4] = 3.0
    closes = [9.5] * 11
    df = pd.DataFrame({"open": closes, "high": highs, "low": lows, "close": closes, "volume": 1}, index=idx)

    out = compute_swing_pivots(df, piv_len=2)

    confirm_idx = idx[4 + 2]
    assert out.loc[confirm_idx, "swing_low"] == 3.0


def test_compute_structure_detects_bullish_bos_then_bearish_choch():
    # Bar 0-4: flat. Bar 3 has a high spike (confirmed pivot at bar 5).
    # Bars 6+: close breaks above the confirmed swing high -> bullish BOS
    # (structure_dir was 0, so this is a BOS, not a CHoCH).
    # Then a low spike gets confirmed and closes break below it -> bearish
    # CHoCH (structure_dir was 1, so breaking down is against prior structure).
    n = 20
    idx = pd.date_range("2023-01-01", periods=n, freq="1h", tz="UTC")
    highs = [10.0] * n
    lows = [9.0] * n
    closes = [9.5] * n

    highs[3] = 15.0  # confirmed at bar 5 (piv_len=2)
    for i in range(6, 9):
        closes[i] = 16.0  # breaks above 15.0 -> bullish BOS somewhere in 6..8

    lows[10] = 3.0  # confirmed at bar 12
    for i in range(13, 16):
        closes[i] = 2.0  # breaks below 3.0 -> bearish CHoCH (structure was bullish)

    df = pd.DataFrame({"open": closes, "high": highs, "low": lows, "close": closes, "volume": 1}, index=idx)
    pivots_df = compute_swing_pivots(df, piv_len=2)

    out = compute_structure(pivots_df)

    assert out["bos"].any()
    assert out["choch"].any()
    # The bullish break happens before the bearish one
    first_bos_pos = out.index.get_loc(out[out["bos"]].index[0])
    first_choch_pos = out.index.get_loc(out[out["choch"]].index[0])
    assert first_bos_pos < first_choch_pos
    # After the bearish CHoCH, structure_dir is -1
    assert out.loc[out[out["choch"]].index[0], "structure_dir"] == -1
