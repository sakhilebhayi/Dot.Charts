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
        "use_mtf_filter": False,  # no symbol/asset_class provided; MTF fetch is not exercised here
        "flatten_at_session_start": True,
    }

    result = run_backtrader(Method714Strategy, df, params)

    assert "metrics" in result
    assert "trade_count" in result["metrics"]
    assert "losing_trade_count" in result["metrics"]
    assert "equity_curve" in result
    assert "trades" in result
    assert len(result["equity_curve"]) > 0
    # Regression: backtrader's internal clock (num2date()) is always
    # tz-naive, even when the source DataFrame's index is tz-aware. A
    # tz-aware _signals/_session_starts index silently matches nothing in
    # next()'s lookups — every bar falls through to "no signal" and the
    # strategy never trades, with no error raised anywhere. The fixture's
    # daily decisive-up moves guarantee a momentum entry every session, so
    # a passing test here proves signals are actually reaching orders.
    assert result["metrics"]["trade_count"] > 0


def test_run_backtrader_method_714_confidence_only_mode_trades_with_low_confidence_ok(mocker):
    # confidence_only mode (the default): a signal with weak individual
    # filters (trend/volume disabled -> those components read as "ok" by
    # construction, matching how _trend_ok()/_volume_ok() already behave
    # when their filter is off) should still trade as long as the score
    # clears min_confidence, even without hard-gating on every filter.
    df = _synthetic_session_df()
    params = {
        "mode": "momentum",
        "use_ema_filter": False,
        "use_volume_filter": False,
        "use_mtf_filter": False,
        "min_confidence": 30,  # session base alone (30) is enough
        "symbol": "BTC/USDT",
        "asset_class": "crypto",
        "start_date": "2023-06-01",
        "end_date": "2023-06-05",
    }

    result = run_backtrader(Method714Strategy, df, params)

    assert result["metrics"]["trade_count"] > 0
    first_trade = result["trades"][0]
    assert "confidence_score" in first_trade
    assert "confidence_breakdown" in first_trade
    assert first_trade["confidence_score"] >= 30


def test_run_backtrader_method_714_min_confidence_blocks_low_confidence_entries(mocker):
    # This fixture's decisive session moves, combined with every optional
    # filter disabled (each reads as "ok" for free per _trend_ok() etc.'s
    # own semantics when its filter is off), reach the maximum possible
    # score of 100 — confirmed directly against this exact fixture/params.
    # min_confidence is set one point above that ceiling (101, outside the
    # normal 0-100 range) specifically to prove the `score < min_confidence`
    # comparison itself blocks entries, independent of which fixture is used.
    df = _synthetic_session_df()
    params = {
        "mode": "momentum",
        "use_ema_filter": False,
        "use_volume_filter": False,
        "use_mtf_filter": False,
        "min_confidence": 101,
        "symbol": "BTC/USDT",
        "asset_class": "crypto",
        "start_date": "2023-06-01",
        "end_date": "2023-06-05",
    }

    result = run_backtrader(Method714Strategy, df, params)

    assert result["metrics"]["trade_count"] == 0


def test_run_backtrader_method_714_hard_filters_mode_vetoes_regardless_of_score(mocker):
    # ema_slow's default (200) needs 200 bars to construct, and this
    # fixture is only 96 bars, so use_ema_filter is left off here to avoid
    # an unrelated indicator-construction crash. Instead this test vetoes
    # via the volume filter: the fixture's volume is a flat 1000 on every
    # bar, so volume[0] > volume_sma[0] * volume_mult (1000 > 1000) is
    # never true -> _volume_ok() reliably fails and hard_filters mode
    # should veto every entry regardless of confidence score.
    df = _synthetic_session_df()
    params = {
        "mode": "momentum",
        "use_ema_filter": False,
        "use_atr_filter": False,
        "use_volume_filter": True,
        "use_mtf_filter": False,
        "filter_mode": "hard_filters",
        "min_confidence": 0,
        "symbol": "BTC/USDT",
        "asset_class": "crypto",
        "start_date": "2023-06-01",
        "end_date": "2023-06-05",
    }

    result = run_backtrader(Method714Strategy, df, params)

    assert result["metrics"]["trade_count"] == 0
