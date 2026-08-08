import pandas as pd
from strategies.method_714.sessions import compute_sessions
from strategies.method_714.retest import generate_signals, DEFAULT_PARAMS

_TEST_SESSIONS = [{"name": "s1", "start": "07:00", "end": "08:00"}]


def _base_df(opens: list[float], closes: list[float]) -> pd.DataFrame:
    # open/close deliberately differ per bar — the session engine reads the
    # session bar's own open (session_open) against its own close to decide
    # bias direction, which a single-bar session (hourly data, hourly
    # session) requires distinct open/close values to exercise at all.
    idx = pd.date_range("2023-06-01 04:00", periods=len(closes), freq="1h", tz="UTC")
    highs = [max(o, c) + 1 for o, c in zip(opens, closes)]
    lows = [min(o, c) - 1 for o, c in zip(opens, closes)]
    return pd.DataFrame(
        {"open": opens, "high": highs, "low": lows, "close": closes, "volume": 1},
        index=idx,
    )


def test_contrarian_mode_fires_immediately_at_session_end():
    # UTC 05:00 = SAST 07:00 (session start), UTC 06:00 = SAST 08:00 (session end)
    opens = [10, 20, 15, 15, 15, 15]
    closes = [10, 15, 15, 15, 15, 15]  # session bar (idx[1]) closes DOWN vs its own open
    df = _base_df(opens, closes)
    sessions_df = compute_sessions(df, _TEST_SESSIONS, tz="Africa/Johannesburg")
    atr = pd.Series(1.0, index=df.index)

    signals = generate_signals(sessions_df, atr, {**DEFAULT_PARAMS, "mode": "contrarian"})

    # Session closed down (15 < 20) -> contrarian fires a BUY (1) at session_end
    assert signals.loc[df.index[2]] == 1


def test_momentum_mode_fires_immediately_at_session_end():
    opens = [10, 20, 15, 15, 15, 15]
    closes = [10, 25, 15, 15, 15, 15]  # session bar closes UP vs its own open
    df = _base_df(opens, closes)
    sessions_df = compute_sessions(df, _TEST_SESSIONS, tz="Africa/Johannesburg")
    atr = pd.Series(1.0, index=df.index)

    signals = generate_signals(sessions_df, atr, {**DEFAULT_PARAMS, "mode": "momentum"})

    assert signals.loc[df.index[2]] == 1


def test_retest_continuation_fires_on_touch_and_reject():
    # Session open = 20 (bar 1's own open). Session bar closes up at 25
    # (bias established at bar 2, session_end). Bar 3: price dips to touch
    # 20 (low forced <= 20) and closes back above the bias side with a
    # decisive rejection (>= open + reject_atr).
    opens = [10, 20, 25, 21]
    closes = [10, 25, 25, 21]
    df = _base_df(opens, closes)
    df.loc[df.index[3], "low"] = 19  # force a touch of the session_open (20)
    sessions_df = compute_sessions(df, _TEST_SESSIONS, tz="Africa/Johannesburg")
    atr = pd.Series(1.0, index=df.index)

    signals = generate_signals(
        sessions_df, atr, {**DEFAULT_PARAMS, "mode": "retest_continuation", "retest_reject_atr": 0.5}
    )

    assert signals.loc[df.index[3]] == 1


def test_retest_continuation_expires_after_max_bars():
    # Session bar closes up (bias=1, bias_open=20), then a long flat
    # stretch at 25 that never touches back down to 20 — the setup must
    # expire once retest_max_bars elapses rather than fire late.
    opens = [10, 20, 25] + [25] * 20
    closes = [10, 25, 25] + [25] * 20
    df = _base_df(opens, closes)
    sessions_df = compute_sessions(df, _TEST_SESSIONS, tz="Africa/Johannesburg")
    atr = pd.Series(1.0, index=df.index)

    signals = generate_signals(
        sessions_df, atr, {**DEFAULT_PARAMS, "mode": "retest_continuation", "retest_max_bars": 2}
    )

    assert (signals == 0).all()
