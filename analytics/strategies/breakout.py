import pandas as pd
import vectorbt as vbt

DEFAULT_PARAMS = {"entry_lookback": 20, "exit_lookback": 10}


def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]:
    entry_lookback = params.get("entry_lookback", DEFAULT_PARAMS["entry_lookback"])
    exit_lookback = params.get("exit_lookback", DEFAULT_PARAMS["exit_lookback"])

    # Shifted by 1 so the breakout bar's own high/low never counts toward
    # the very channel it's breaking -- an unshifted channel would make
    # entries/exits impossible, since a bar's high can never exceed a
    # rolling max that includes itself.
    upper_channel = df["high"].rolling(entry_lookback).max().shift(1)
    lower_channel = df["low"].rolling(exit_lookback).min().shift(1)

    entries = df["close"] > upper_channel
    exits = df["close"] < lower_channel

    return entries.fillna(False), exits.fillna(False)


def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
