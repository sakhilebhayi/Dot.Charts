from zoneinfo import ZoneInfo

import pandas as pd

DEFAULT_SESSIONS = [
    {"name": "session_1", "start": "07:00", "end": "08:00"},
    {"name": "session_2", "start": "13:00", "end": "14:00"},
    {"name": "session_3", "start": "16:00", "end": "17:00"},
]
DEFAULT_TZ = "Africa/Johannesburg"


def compute_sessions(df: pd.DataFrame, sessions: list[dict] | None = None, tz: str = DEFAULT_TZ) -> pd.DataFrame:
    """
    Adds session columns to a copy of `df`, which must have a tz-aware
    DatetimeIndex (UTC or otherwise).

    Added columns:
      - session_name: str | None — which configured session this bar falls in
      - session_open: float | NaN — the open price of the session this bar belongs to
      - session_start: bool — True on the first bar inside a session
      - session_end: bool — True on the first bar AFTER a session ends
    """
    sessions = sessions or DEFAULT_SESSIONS
    local_index = df.index.tz_convert(ZoneInfo(tz))

    out = df.copy()
    out["session_name"] = None
    out["session_open"] = float("nan")
    out["session_start"] = False
    out["session_end"] = False

    for sess in sessions:
        start_t = pd.to_datetime(sess["start"]).time()
        end_t = pd.to_datetime(sess["end"]).time()

        in_session = pd.Series([start_t <= t.time() < end_t for t in local_index], index=df.index)
        session_start = in_session & ~in_session.shift(1, fill_value=False)
        session_end = ~in_session & in_session.shift(1, fill_value=False)

        out.loc[in_session, "session_name"] = sess["name"]
        out.loc[session_start, "session_start"] = True
        out.loc[session_end, "session_end"] = True

        session_open_series = out["open"].where(session_start).ffill().where(in_session)
        out.loc[in_session, "session_open"] = session_open_series[in_session]

    return out
