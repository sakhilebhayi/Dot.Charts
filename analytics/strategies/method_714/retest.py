import pandas as pd

DEFAULT_PARAMS = {
    "mode": "retest_continuation",  # "retest_continuation" | "contrarian" | "momentum"
    "retest_max_bars": 16,
    "retest_reject_atr": 0.15,
    "retest_invalidate_atr": 0.75,
}


def generate_signals(df_with_sessions: pd.DataFrame, atr: pd.Series, params: dict) -> pd.Series:
    """
    Returns a pd.Series aligned to df_with_sessions.index: 1 = long, -1 = short,
    0 = no signal, on the bar the signal fires.
    """
    p = {**DEFAULT_PARAMS, **params}
    mode = p["mode"]

    if mode in ("contrarian", "momentum"):
        return _immediate_mode_signals(df_with_sessions, mode)

    return _retest_continuation_signals(df_with_sessions, atr, p)


def _immediate_mode_signals(df: pd.DataFrame, mode: str) -> pd.Series:
    signals = pd.Series(0, index=df.index)
    session_end_mask = df["session_end"]

    for idx in df.index[session_end_mask]:
        pos = df.index.get_loc(idx)
        if pos == 0:
            continue
        prior_close = df["close"].iloc[pos - 1]
        session_open = df["session_open"].iloc[pos - 1]
        if pd.isna(prior_close) or pd.isna(session_open):
            continue

        closed_down = prior_close < session_open
        closed_up = prior_close > session_open
        if mode == "contrarian":
            signals.loc[idx] = 1 if closed_down else (-1 if closed_up else 0)
        else:
            signals.loc[idx] = 1 if closed_up else (-1 if closed_down else 0)

    return signals


def _retest_continuation_signals(df: pd.DataFrame, atr: pd.Series, p: dict) -> pd.Series:
    signals = pd.Series(0, index=df.index)

    armed = False
    bias_dir = 0
    bias_open = float("nan")
    bias_end_pos = None

    for i, idx in enumerate(df.index):
        row = df.iloc[i]

        if row["session_start"]:
            armed = False  # any new session cancels a pending setup

        if row["session_end"]:
            if i > 0:
                prior_close = df["close"].iloc[i - 1]
                session_open = df["session_open"].iloc[i - 1]
                if not pd.isna(prior_close) and not pd.isna(session_open) and prior_close != session_open:
                    bias_dir = 1 if prior_close > session_open else -1
                    bias_open = session_open
                    bias_end_pos = i
                    armed = True
            continue

        if not armed or bias_end_pos is None or i <= bias_end_pos:
            continue

        bars_elapsed = i - bias_end_pos
        if bars_elapsed > p["retest_max_bars"]:
            armed = False
            continue

        close = row["close"]
        high = row["high"]
        low = row["low"]
        a = atr.iloc[i]

        invalidated = (
            (bias_dir == 1 and close < bias_open - a * p["retest_invalidate_atr"])
            or (bias_dir == -1 and close > bias_open + a * p["retest_invalidate_atr"])
        )
        if invalidated:
            armed = False
            continue

        touched = (low <= bias_open) if bias_dir == 1 else (high >= bias_open)
        rejected = (
            (bias_dir == 1 and close >= bias_open + a * p["retest_reject_atr"])
            or (bias_dir == -1 and close <= bias_open - a * p["retest_reject_atr"])
        )
        if touched and rejected:
            signals.iloc[i] = bias_dir
            armed = False

    return signals
