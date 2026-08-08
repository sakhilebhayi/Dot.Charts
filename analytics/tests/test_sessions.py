import pandas as pd
from strategies.method_714.sessions import compute_sessions

# 07:00-08:00 Africa/Johannesburg == 05:00-06:00 UTC (SAST is UTC+2, no DST)
_TEST_SESSIONS = [{"name": "session_1", "start": "07:00", "end": "08:00"}]


def test_compute_sessions_marks_start_end_and_open_price():
    idx = pd.date_range("2023-06-01 04:00", periods=6, freq="1h", tz="UTC")
    df = pd.DataFrame(
        {
            "open": [10, 20, 21, 22, 30, 31],
            "high": [10, 20, 21, 22, 30, 31],
            "low": [10, 20, 21, 22, 30, 31],
            "close": [10, 20, 21, 22, 30, 31],
            "volume": [1] * 6,
        },
        index=idx,
    )
    # idx (UTC): 04:00, 05:00, 06:00, 07:00, 08:00, 09:00
    # SAST:      06:00, 07:00, 08:00, 09:00, 10:00, 11:00
    # Session window 07:00-08:00 SAST covers the 05:00 UTC bar only.

    out = compute_sessions(df, _TEST_SESSIONS, tz="Africa/Johannesburg")

    assert out.loc[idx[1], "session_start"] == True  # noqa: E712
    assert out.loc[idx[1], "session_name"] == "session_1"
    assert out.loc[idx[1], "session_open"] == 20
    assert out.loc[idx[2], "session_end"] == True  # noqa: E712
    assert bool(out.loc[idx[0], "session_start"]) is False
    assert pd.isna(out.loc[idx[0], "session_open"])


def test_compute_sessions_defaults_do_not_raise():
    idx = pd.date_range("2023-06-01", periods=48, freq="1h", tz="UTC")
    df = pd.DataFrame(
        {"open": 1, "high": 1, "low": 1, "close": 1, "volume": 1},
        index=idx,
    )

    out = compute_sessions(df)

    assert "session_name" in out.columns
