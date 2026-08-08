import pandas as pd
import quantstats as qs


def compute_metrics_from_portfolio(portfolio) -> dict:
    returns = portfolio.returns()
    equity_curve = portfolio.value()
    trades_df = portfolio.trades.records_readable

    trade_count = len(trades_df)
    losing_trade_count = int((trades_df["PnL"] < 0).sum()) if trade_count else 0
    win_rate_pct = float((trades_df["PnL"] > 0).mean() * 100) if trade_count else 0.0
    total_return_pct = float(qs.stats.comp(returns) * 100) if len(returns) else 0.0
    max_drawdown_pct = float(qs.stats.max_drawdown(returns) * 100) if len(returns) else 0.0
    sharpe_ratio = float(qs.stats.sharpe(returns)) if len(returns) > 1 else None

    equity_curve_records = [
        {"time": str(ts), "equity": float(v)} for ts, v in equity_curve.items()
    ]

    trades = [
        {
            "entry_time": str(row["Entry Timestamp"]),
            "exit_time": str(row["Exit Timestamp"]) if pd.notna(row["Exit Timestamp"]) else None,
            "direction": "long" if row["Direction"] == "Long" else "short",
            "entry_price": float(row["Avg Entry Price"]),
            "exit_price": float(row["Avg Exit Price"]) if pd.notna(row["Avg Exit Price"]) else None,
            "pnl": float(row["PnL"]) if pd.notna(row["PnL"]) else None,
        }
        for _, row in trades_df.iterrows()
    ]

    return {
        "metrics": {
            "total_return_pct": total_return_pct,
            "win_rate_pct": win_rate_pct,
            "max_drawdown_pct": max_drawdown_pct,
            "sharpe_ratio": sharpe_ratio,
            "trade_count": trade_count,
            "losing_trade_count": losing_trade_count,
        },
        "equity_curve": equity_curve_records,
        "trades": trades,
    }
