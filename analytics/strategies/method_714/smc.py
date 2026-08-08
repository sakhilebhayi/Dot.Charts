from datetime import timedelta
from zoneinfo import ZoneInfo

import pandas as pd


def compute_swing_pivots(df: pd.DataFrame, piv_len: int = 5) -> pd.DataFrame:
    """
    Adds swing_high/swing_low columns: the pivot price, recorded at the bar
    where it becomes confirmed — piv_len bars after the actual extreme, the
    same confirmation lag as Pine's ta.pivothigh/pivotlow. This is real lag
    inherent to how a pivot is defined (you can't know a bar was a local
    extreme until you've seen piv_len bars on both sides of it), not
    repainting.
    """
    out = df.copy()
    out["swing_high"] = float("nan")
    out["swing_low"] = float("nan")

    highs = df["high"].to_numpy()
    lows = df["low"].to_numpy()
    n = len(df)
    high_col = out.columns.get_loc("swing_high")
    low_col = out.columns.get_loc("swing_low")

    for center in range(piv_len, n - piv_len):
        window = slice(center - piv_len, center + piv_len + 1)
        confirm_idx = center + piv_len
        window_highs = highs[window]
        window_lows = lows[window]
        # Strict, unique extreme — a flat run (all bars tied for max/min in
        # their window) is not a pivot, it's a plateau. Without the
        # uniqueness check every bar in a flat stretch would falsely
        # register as its own pivot.
        if highs[center] == window_highs.max() and (window_highs == highs[center]).sum() == 1:
            out.iat[confirm_idx, high_col] = highs[center]
        if lows[center] == window_lows.min() and (window_lows == lows[center]).sum() == 1:
            out.iat[confirm_idx, low_col] = lows[center]

    return out


def compute_structure(df_with_pivots: pd.DataFrame) -> pd.DataFrame:
    """
    Tracks a running structure_dir (1 bullish, -1 bearish, 0 undefined),
    flipping on a close crossing the last confirmed swing high (bullish
    break) or swing low (bearish break). A break against the prior
    structure direction is a Change of Character (CHoCH); a break with it
    is a Break of Structure (BOS) — matching the Pine source's
    bullChoch/bullBos/bearChoch/bearBos logic exactly.
    """
    out = df_with_pivots.copy()
    closes = out["close"].to_numpy()
    swing_highs = out["swing_high"].to_numpy()
    swing_lows = out["swing_low"].to_numpy()
    n = len(out)

    structure_dirs = [0] * n
    bos_flags = [False] * n
    choch_flags = [False] * n
    bull_breaks = [False] * n
    bear_breaks = [False] * n

    last_ph = float("nan")
    last_pl = float("nan")
    structure_dir = 0
    prev_close = None

    for i in range(n):
        if not pd.isna(swing_highs[i]):
            last_ph = swing_highs[i]
        if not pd.isna(swing_lows[i]):
            last_pl = swing_lows[i]

        bull_break = (
            not pd.isna(last_ph) and prev_close is not None and prev_close <= last_ph < closes[i]
        )
        bear_break = (
            not pd.isna(last_pl) and prev_close is not None and prev_close >= last_pl > closes[i]
        )

        if bull_break:
            choch_flags[i] = structure_dir == -1
            bos_flags[i] = not choch_flags[i]
            structure_dir = 1
        elif bear_break:
            choch_flags[i] = structure_dir == 1
            bos_flags[i] = not choch_flags[i]
            structure_dir = -1

        bull_breaks[i] = bull_break
        bear_breaks[i] = bear_break
        structure_dirs[i] = structure_dir
        prev_close = closes[i]

    out["structure_dir"] = structure_dirs
    out["bos"] = bos_flags
    out["choch"] = choch_flags
    out["bull_break"] = bull_breaks
    out["bear_break"] = bear_breaks
    return out


def compute_order_blocks(df_with_structure: pd.DataFrame, max_count: int = 6) -> list[dict]:
    """
    A bullish order block is the last down-candle (close < open) before a
    bullish structure break; a bearish order block is the last up-candle
    before a bearish break. Returns at most the most recent `max_count`,
    matching the Pine source's bounded rolling list.
    """
    order_blocks = []
    last_down = None
    last_up = None

    opens = df_with_structure["open"].to_numpy()
    closes = df_with_structure["close"].to_numpy()
    highs = df_with_structure["high"].to_numpy()
    lows = df_with_structure["low"].to_numpy()
    bull_breaks = df_with_structure["bull_break"].to_numpy()
    bear_breaks = df_with_structure["bear_break"].to_numpy()

    for i in range(len(df_with_structure)):
        if closes[i] < opens[i]:
            last_down = {"high": highs[i], "low": lows[i], "bar_index": i}
        if closes[i] > opens[i]:
            last_up = {"high": highs[i], "low": lows[i], "bar_index": i}

        if bull_breaks[i] and last_down is not None:
            order_blocks.append({"type": "bullish", **last_down})
        if bear_breaks[i] and last_up is not None:
            order_blocks.append({"type": "bearish", **last_up})

    return order_blocks[-max_count:] if len(order_blocks) > max_count else order_blocks


def compute_fair_value_gaps(
    df: pd.DataFrame, atr: pd.Series, min_atr_mult: float = 0.25, max_count: int = 8
) -> list[dict]:
    """
    A bullish FVG is a 3-bar gap where the current bar's low is above the
    high two bars ago; a bearish FVG mirrors it. Only gaps at least
    min_atr_mult * ATR wide count, matching the Pine source's fvgMinAtr.
    """
    fvgs = []
    highs = df["high"].to_numpy()
    lows = df["low"].to_numpy()
    atr_values = atr.to_numpy()

    for i in range(2, len(df)):
        if pd.isna(atr_values[i]):
            continue
        min_size = atr_values[i] * min_atr_mult

        if lows[i] > highs[i - 2] and (lows[i] - highs[i - 2]) >= min_size:
            fvgs.append({"type": "bullish", "top": lows[i], "bottom": highs[i - 2], "bar_index": i})
        if highs[i] < lows[i - 2] and (lows[i - 2] - highs[i]) >= min_size:
            fvgs.append({"type": "bearish", "top": lows[i - 2], "bottom": highs[i], "bar_index": i})

    return fvgs[-max_count:] if len(fvgs) > max_count else fvgs


def compute_liquidity_sweeps(df_with_pivots: pd.DataFrame, lookback_bars: int = 10) -> pd.DataFrame:
    """
    A bearish sweep: a wick trades above the last confirmed swing high but
    closes back below it (buy-side liquidity taken). A bullish sweep
    mirrors it at the last swing low. recent_*_sweep stays true for
    lookback_bars after the sweep bar, matching the Pine source's "recent
    memory" pattern so a session signal shortly after a sweep still counts
    it as confluence.
    """
    out = df_with_pivots.copy()
    swing_highs = out["swing_high"].to_numpy()
    swing_lows = out["swing_low"].to_numpy()
    highs = out["high"].to_numpy()
    lows = out["low"].to_numpy()
    closes = out["close"].to_numpy()
    n = len(out)

    sweep_bull = [False] * n
    sweep_bear = [False] * n
    recent_bull = [False] * n
    recent_bear = [False] * n

    last_ph = float("nan")
    last_pl = float("nan")
    last_bull_pos = None
    last_bear_pos = None

    for i in range(n):
        if not pd.isna(swing_highs[i]):
            last_ph = swing_highs[i]
        if not pd.isna(swing_lows[i]):
            last_pl = swing_lows[i]

        sb = not pd.isna(last_pl) and lows[i] < last_pl and closes[i] > last_pl
        se = not pd.isna(last_ph) and highs[i] > last_ph and closes[i] < last_ph

        if sb:
            last_bull_pos = i
        if se:
            last_bear_pos = i

        sweep_bull[i] = sb
        sweep_bear[i] = se
        recent_bull[i] = last_bull_pos is not None and (i - last_bull_pos) <= lookback_bars
        recent_bear[i] = last_bear_pos is not None and (i - last_bear_pos) <= lookback_bars

    out["sweep_bull"] = sweep_bull
    out["sweep_bear"] = sweep_bear
    out["recent_bull_sweep"] = recent_bull
    out["recent_bear_sweep"] = recent_bear
    return out


def compute_prev_day_sweeps(df: pd.DataFrame, tz: str, lookback_bars: int = 10) -> pd.DataFrame:
    """
    A sweep-and-reclaim of the previous calendar day's high/low — the
    liquidity levels most watched at daily scale. recent_pd_*_sweep uses
    the same lookback-window pattern as compute_liquidity_sweeps.
    """
    out = df.copy()
    local_index = out.index.tz_convert(ZoneInfo(tz))
    bar_dates = pd.Series([t.date() for t in local_index], index=out.index)

    daily_high = out["high"].groupby(bar_dates).max()
    daily_low = out["low"].groupby(bar_dates).min()

    prev_day_high = bar_dates.map(lambda d: daily_high.get(d - timedelta(days=1)))
    prev_day_low = bar_dates.map(lambda d: daily_low.get(d - timedelta(days=1)))

    out["prev_day_high"] = prev_day_high.to_numpy()
    out["prev_day_low"] = prev_day_low.to_numpy()

    pd_sweep_bull = (out["low"] < out["prev_day_low"]) & (out["close"] > out["prev_day_low"])
    pd_sweep_bear = (out["high"] > out["prev_day_high"]) & (out["close"] < out["prev_day_high"])

    bull_arr = pd_sweep_bull.fillna(False).to_numpy()
    bear_arr = pd_sweep_bear.fillna(False).to_numpy()
    n = len(out)
    recent_bull = [False] * n
    recent_bear = [False] * n
    last_bull_pos = None
    last_bear_pos = None

    for i in range(n):
        if bull_arr[i]:
            last_bull_pos = i
        if bear_arr[i]:
            last_bear_pos = i
        recent_bull[i] = last_bull_pos is not None and (i - last_bull_pos) <= lookback_bars
        recent_bear[i] = last_bear_pos is not None and (i - last_bear_pos) <= lookback_bars

    out["recent_pd_bull_sweep"] = recent_bull
    out["recent_pd_bear_sweep"] = recent_bear
    return out
