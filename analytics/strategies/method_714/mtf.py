import pandas as pd
import pandas_ta as ta

from data.fetch import fetch_ohlcv


def compute_htf_trend(
    symbol: str,
    asset_class: str,
    start_date: str,
    end_date: str,
    base_index: pd.DatetimeIndex,
    htf_interval: str = "4h",
    fast: int = 50,
    slow: int = 200,
) -> pd.Series:
    """
    Fetches a higher-timeframe dataset and returns a trend series (1
    bullish, -1 bearish, 0 flat/insufficient-data) aligned to base_index.

    Non-repainting: the HTF trend is shifted by one HTF bar before
    alignment, so a base-timeframe bar only ever sees the most recently
    fully-closed HTF bar's EMA state — matching the Pine source's
    request.security(..., lookahead=barmerge.lookahead_off) semantics.
    """
    htf_df = fetch_ohlcv(symbol, asset_class, start_date, end_date, interval=htf_interval)

    ema_fast = ta.ema(htf_df["close"], length=fast)
    ema_slow = ta.ema(htf_df["close"], length=slow)

    htf_trend = pd.Series(0, index=htf_df.index)
    htf_trend[ema_fast > ema_slow] = 1
    htf_trend[ema_fast < ema_slow] = -1
    htf_trend = htf_trend.shift(1).fillna(0)

    combined_index = base_index.union(htf_trend.index)
    aligned = htf_trend.reindex(combined_index).ffill().reindex(base_index).fillna(0)
    return aligned.astype(int)
