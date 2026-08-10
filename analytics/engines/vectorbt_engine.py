import pandas as pd
from metrics import compute_metrics_from_portfolio


def run_vectorbt(strategy_module, df: pd.DataFrame, params: dict) -> dict:
    portfolio = strategy_module.run(df, params)
    return compute_metrics_from_portfolio(portfolio)


def run_vectorbt_pairs(strategy_module, df_a: pd.DataFrame, df_b: pd.DataFrame, params: dict) -> dict:
    portfolio = strategy_module.run(df_a, df_b, params)
    return compute_metrics_from_portfolio(portfolio)
