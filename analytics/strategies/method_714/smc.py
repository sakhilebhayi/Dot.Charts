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
