import pandas as pd
import pytest
import vectorbt as vbt
from strategies.custom import generate_signals, run, DEFAULT_PARAMS
from strategies.custom_rules import InvalidStrategyParamsError


def _trending_df() -> pd.DataFrame:
    idx = pd.date_range("2023-01-01", periods=100, freq="D")
    flat = [100.0] * 60
    uptrend = [100.0 + i * 2 for i in range(1, 41)]
    close = pd.Series(flat + uptrend, index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def _rule_params() -> dict:
    return {
        "entry": {
            "combinator": "all",
            "conditions": [
                {"left": {"indicator": "ema", "length": 5}, "comparator": "crosses_above", "right": {"indicator": "ema", "length": 20}},
            ],
        },
        "exit": {
            "combinator": "all",
            "conditions": [
                {"left": {"indicator": "ema", "length": 5}, "comparator": "crosses_below", "right": {"indicator": "ema", "length": 20}},
            ],
        },
    }


def test_default_params_is_empty():
    assert DEFAULT_PARAMS == {}


def test_generate_signals_uses_entry_and_exit_rules():
    df = _trending_df()

    entries, exits = generate_signals(df, _rule_params())

    assert entries.any()


def test_generate_signals_raises_when_entry_rule_missing():
    df = _trending_df()
    params = {"exit": _rule_params()["exit"]}

    with pytest.raises(InvalidStrategyParamsError):
        generate_signals(df, params)


def test_generate_signals_raises_when_exit_rule_missing():
    df = _trending_df()
    params = {"entry": _rule_params()["entry"]}

    with pytest.raises(InvalidStrategyParamsError):
        generate_signals(df, params)


def test_run_returns_a_vectorbt_portfolio():
    df = _trending_df()

    portfolio = run(df, _rule_params())

    assert isinstance(portfolio, vbt.Portfolio)
