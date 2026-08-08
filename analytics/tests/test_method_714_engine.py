import pandas as pd
from engines.backtrader_engine import run_backtrader
from strategies.method_714.strategy import Method714Strategy


def _synthetic_session_df():
    # 4 days of hourly bars in UTC, structured so at least one session
    # (07:00-08:00 SAST = 05:00-06:00 UTC) closes decisively up each day,
    # giving momentum mode a clean, repeatable entry to trade.
    idx = pd.date_range("2023-06-01 00:00", periods=96, freq="1h", tz="UTC")
    base = 100.0
    opens = []
    closes = []
    for i in range(96):
        hour = idx[i].hour
        bar_open = base
        if hour == 5:
            base += 5  # session bar: decisive up move
        else:
            base += 0.1
        opens.append(bar_open)
        closes.append(base)
    df = pd.DataFrame(
        {
            "open": opens,
            "high": [max(o, c) + 1 for o, c in zip(opens, closes)],
            "low": [min(o, c) - 1 for o, c in zip(opens, closes)],
            "close": closes,
            "volume": [1000] * 96,
        },
        index=idx,
    )
    return df


def test_run_backtrader_method_714_returns_metrics_shape():
    df = _synthetic_session_df()
    params = {
        "mode": "momentum",
        "use_ema_filter": False,
        "use_atr_filter": False,
        "use_volume_filter": False,
        "flatten_at_session_start": True,
    }

    result = run_backtrader(Method714Strategy, df, params)

    assert "metrics" in result
    assert "trade_count" in result["metrics"]
    assert "losing_trade_count" in result["metrics"]
    assert "equity_curve" in result
    assert "trades" in result
    assert len(result["equity_curve"]) > 0
