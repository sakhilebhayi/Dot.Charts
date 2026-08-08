import pandas as pd
import pandas_ta as ta

from data.cache import fetch_ohlcv_cached
from strategies.method_714.smc import compute_swing_pivots, compute_structure


def compute_chart_analysis(symbol: str, asset_class: str, interval: str = "1d") -> dict:
    end_date = pd.Timestamp.utcnow().strftime("%Y-%m-%d")
    start_date = (pd.Timestamp.utcnow() - pd.Timedelta(days=180)).strftime("%Y-%m-%d")

    df = fetch_ohlcv_cached(symbol, asset_class, start_date, end_date, interval=interval)

    ema_fast = ta.ema(df["close"], length=20)
    ema_slow = ta.ema(df["close"], length=50)
    if ema_fast.iloc[-1] > ema_slow.iloc[-1]:
        trend = "Bullish"
    elif ema_fast.iloc[-1] < ema_slow.iloc[-1]:
        trend = "Bearish"
    else:
        trend = "Neutral"

    pivots = compute_swing_pivots(df)
    structure = compute_structure(pivots)
    structure_dir = int(structure["structure_dir"].iloc[-1])

    supports = [f"{v:.2f}" for v in structure["swing_low"].dropna().tail(2).tolist()]
    resistances = [f"{v:.2f}" for v in structure["swing_high"].dropna().tail(2).tolist()]

    if trend == "Bullish" and structure_dir == 1:
        signal, confidence = "Buy", 80
    elif trend == "Bearish" and structure_dir == -1:
        signal, confidence = "Sell", 80
    elif structure_dir == 0:
        signal, confidence = "Hold", 20
    else:
        signal, confidence = "Hold", 40

    # Most recent structure event (CHoCH takes priority over BOS if both
    # exist, since a CHoCH is the more recent-relevant regime change) --
    # matching compute_structure's own bos/choch semantics exactly.
    bos_idx = structure.index[structure["bos"]]
    choch_idx = structure.index[structure["choch"]]
    last_bos_ts = bos_idx[-1] if len(bos_idx) else None
    last_choch_ts = choch_idx[-1] if len(choch_idx) else None

    if last_choch_ts is not None and (last_bos_ts is None or last_choch_ts > last_bos_ts):
        row = structure.loc[last_choch_ts]
        direction = "Bullish" if row["bull_break"] else "Bearish"
        pattern = f"{direction} Change of Character"
    elif last_bos_ts is not None:
        row = structure.loc[last_bos_ts]
        direction = "Bullish" if row["bull_break"] else "Bearish"
        pattern = f"{direction} Break of Structure"
    else:
        pattern = "No recent structure break"

    structure_word = "bullish" if structure_dir == 1 else "bearish" if structure_dir == -1 else "neutral"
    summary = (
        f"{trend} trend with {structure_word} structure on {symbol} ({interval}). "
        "Confidence is a lightweight trend/structure agreement score, not a "
        "backtested strategy confidence like 714 Method's."
    )

    return {
        "signal": signal,
        "confidence": confidence,
        "trend": trend,
        "patterns": [pattern],
        "supports": supports,
        "resistances": resistances,
        "summary": summary,
    }
