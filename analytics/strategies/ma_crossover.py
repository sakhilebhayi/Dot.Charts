import pandas as pd
import pandas_ta as ta
import vectorbt as vbt

DEFAULT_PARAMS = {"fast_window": 20, "slow_window": 50}


def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]:
    fast_window = params.get("fast_window", DEFAULT_PARAMS["fast_window"])
    slow_window = params.get("slow_window", DEFAULT_PARAMS["slow_window"])

    fast_ma = ta.sma(df["close"], length=fast_window)
    slow_ma = ta.sma(df["close"], length=slow_window)

    entries = (fast_ma > slow_ma) & (fast_ma.shift(1) <= slow_ma.shift(1))
    exits = (fast_ma < slow_ma) & (fast_ma.shift(1) >= slow_ma.shift(1))

    return entries.fillna(False), exits.fillna(False)


def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
