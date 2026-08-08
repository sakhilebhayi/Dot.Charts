import pandas as pd
import pandas_ta as ta
import vectorbt as vbt

DEFAULT_PARAMS = {"rsi_length": 14, "oversold": 30, "overbought": 70}


def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]:
    length = params.get("rsi_length", DEFAULT_PARAMS["rsi_length"])
    oversold = params.get("oversold", DEFAULT_PARAMS["oversold"])
    overbought = params.get("overbought", DEFAULT_PARAMS["overbought"])

    rsi = ta.rsi(df["close"], length=length)

    entries = (rsi < oversold) & (rsi.shift(1) >= oversold)
    exits = (rsi > overbought) & (rsi.shift(1) <= overbought)

    return entries.fillna(False), exits.fillna(False)


def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
