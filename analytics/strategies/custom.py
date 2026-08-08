import pandas as pd
import vectorbt as vbt

from .custom_rules import evaluate_rule, InvalidStrategyParamsError

DEFAULT_PARAMS = {}


def generate_signals(df: pd.DataFrame, params: dict) -> tuple[pd.Series, pd.Series]:
    entry_rule = params.get("entry")
    exit_rule = params.get("exit")
    if not entry_rule:
        raise InvalidStrategyParamsError("params must include an 'entry' rule")
    if not exit_rule:
        raise InvalidStrategyParamsError("params must include an 'exit' rule")

    entries = evaluate_rule(df, entry_rule)
    exits = evaluate_rule(df, exit_rule)
    return entries, exits


def run(df: pd.DataFrame, params: dict) -> vbt.Portfolio:
    entries, exits = generate_signals(df, params)
    return vbt.Portfolio.from_signals(df["close"], entries, exits, freq="1D", init_cash=10_000)
