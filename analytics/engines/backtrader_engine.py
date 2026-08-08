import backtrader as bt
import pandas as pd

from metrics import compute_metrics_from_backtrader_strategy


def run_backtrader(strategy_cls, df: pd.DataFrame, params: dict) -> dict:
    cerebro = bt.Cerebro()
    data = bt.feeds.PandasData(dataname=df)
    cerebro.adddata(data)
    cerebro.broker.setcash(10_000)
    cerebro.addstrategy(strategy_cls, **params)
    results = cerebro.run()
    strategy_instance = results[0]
    return compute_metrics_from_backtrader_strategy(strategy_instance)
