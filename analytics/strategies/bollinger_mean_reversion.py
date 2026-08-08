import pandas as pd
import pandas_ta as ta
import vectorbt as vbt

DEFAULT_PARAMS = {"length": 20, "std": 2.0}


def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]:
    length = params.get("length", DEFAULT_PARAMS["length"])
    std = params.get("std", DEFAULT_PARAMS["std"])

    # Selected positionally rather than by exact column name -- pandas_ta's
    # bbands() column-name suffix format (e.g. "BBL_20_2.0_2.0" on the
    # installed version, verified via `ta.bbands(...).columns.tolist()`)
    # is not consistent across releases, but the column order (lower,
    # middle, upper, bandwidth, percent) is stable and documented.
    bands = ta.bbands(df["close"], length=length, std=std)
    lower = bands.iloc[:, 0]
    middle = bands.iloc[:, 1]

    entries = (df["close"] < lower) & (df["close"].shift(1) >= lower.shift(1))
    exits = (df["close"] > middle) & (df["close"].shift(1) <= middle.shift(1))

    return entries.fillna(False), exits.fillna(False)


def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
