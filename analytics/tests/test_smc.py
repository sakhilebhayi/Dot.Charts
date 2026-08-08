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


def test_compute_order_blocks_places_bullish_ob_on_last_down_candle_before_break():
    from strategies.method_714.smc import compute_order_blocks

    n = 10
    idx = pd.date_range("2023-01-01", periods=n, freq="1h", tz="UTC")
    opens = [10.0] * n
    closes = [10.0] * n
    highs = [10.5] * n
    lows = [9.5] * n

    # Bar 3: a down candle (close < open) — the expected order block.
    opens[3], closes[3] = 10.0, 9.0
    highs[3], lows[3] = 10.2, 8.8

    df = pd.DataFrame({"open": opens, "high": highs, "low": lows, "close": closes, "volume": 1}, index=idx)
    df["structure_dir"] = 0
    df["bull_break"] = False
    df["bear_break"] = False
    df.iloc[6, df.columns.get_loc("bull_break")] = True  # a bullish break fires at bar 6

    order_blocks = compute_order_blocks(df, max_count=6)

    assert len(order_blocks) == 1
    assert order_blocks[0]["type"] == "bullish"
    assert order_blocks[0]["bar_index"] == 3


def test_compute_fair_value_gaps_detects_a_bullish_gap_above_min_size():
    from strategies.method_714.smc import compute_fair_value_gaps

    # A clean gap-up at bar 2 (low=12 vs bar 0's high=10), then bars 3-4
    # stay within ranges that don't gap relative to 2 bars prior either
    # direction — isolates exactly one FVG event.
    idx = pd.date_range("2023-01-01", periods=5, freq="1h", tz="UTC")
    df = pd.DataFrame(
        {
            "open": [9.5, 9.5, 12.5, 10.0, 12.5],
            "close": [9.5, 9.5, 12.5, 10.0, 12.5],
            "high": [10, 10, 13, 10.5, 13],
            "low": [9, 9, 12, 9.5, 12],
            "volume": [1, 1, 1, 1, 1],
        },
        index=idx,
    )
    atr = pd.Series(1.0, index=idx)  # min gap size = 0.25 * 1.0 = 0.25; gap here is 2.0

    fvgs = compute_fair_value_gaps(df, atr, min_atr_mult=0.25)

    assert len(fvgs) == 1
    assert fvgs[0]["type"] == "bullish"
    assert fvgs[0]["bar_index"] == 2


def test_compute_liquidity_sweeps_detects_a_bullish_sweep_and_marks_it_recent():
    from strategies.method_714.smc import compute_liquidity_sweeps

    n = 15
    idx = pd.date_range("2023-01-01", periods=n, freq="1h", tz="UTC")
    highs = [10.0] * n
    lows = [9.0] * n
    closes = [9.5] * n

    lows[4] = 3.0  # confirmed swing low at bar 6 (piv_len=2)

    # Bar 8: wick trades below the swing low (3.0) but closes back above it
    lows[8] = 2.5
    closes[8] = 9.5

    df = pd.DataFrame({"open": closes, "high": highs, "low": lows, "close": closes, "volume": 1}, index=idx)
    pivots_df = compute_swing_pivots(df, piv_len=2)

    out = compute_liquidity_sweeps(pivots_df, lookback_bars=3)

    assert out.loc[idx[8], "sweep_bull"] == True  # noqa: E712
    assert out.loc[idx[8], "recent_bull_sweep"] == True  # noqa: E712
    assert out.loc[idx[8 + 3], "recent_bull_sweep"] == True  # noqa: E712
    assert out.loc[idx[8 + 4], "recent_bull_sweep"] == False  # noqa: E712  (outside lookback)


def test_compute_prev_day_sweeps_detects_a_sweep_of_yesterdays_low():
    from strategies.method_714.smc import compute_prev_day_sweeps

    # Day 1: 24 hourly bars, low stays at 9.0. Day 2: bar 3 wicks below 9.0
    # but closes back above it -> a previous-day-low sweep.
    idx = pd.date_range("2023-06-01 00:00", periods=48, freq="1h", tz="UTC")
    highs = [10.0] * 48
    lows = [9.0] * 48
    closes = [9.5] * 48

    lows[27] = 8.0  # day 2, hour 3: wicks below day 1's low (9.0)
    closes[27] = 9.5

    df = pd.DataFrame({"open": closes, "high": highs, "low": lows, "close": closes, "volume": 1}, index=idx)

    out = compute_prev_day_sweeps(df, tz="UTC", lookback_bars=3)

    assert out.loc[idx[27], "recent_pd_bull_sweep"] == True  # noqa: E712
