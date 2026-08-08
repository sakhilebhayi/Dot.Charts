import pandas as pd
import pytest
from strategies.custom_rules import evaluate_rule, InvalidStrategyParamsError


def _trending_df() -> pd.DataFrame:
    # Flat for 60 bars, then a clean uptrend for 40 bars — same pattern
    # test_ma_crossover.py already uses, guarantees a deterministic EMA
    # crossover partway through.
    idx = pd.date_range("2023-01-01", periods=100, freq="D")
    flat = [100.0] * 60
    uptrend = [100.0 + i * 2 for i in range(1, 41)]
    close = pd.Series(flat + uptrend, index=idx)
    return pd.DataFrame({"open": close, "high": close, "low": close, "close": close, "volume": 1000})


def test_evaluate_rule_crosses_above_fires_on_crossover():
    df = _trending_df()
    rule = {
        "combinator": "all",
        "conditions": [
            {"left": {"indicator": "ema", "length": 5}, "comparator": "crosses_above", "right": {"indicator": "ema", "length": 20}},
        ],
    }

    result = evaluate_rule(df, rule)

    assert result.any()
    assert not result.iloc[:60].any(), "no crossover should fire during the flat section"


def test_evaluate_rule_less_than_fires_when_value_below_threshold():
    df = _trending_df()
    rule = {
        "combinator": "all",
        "conditions": [
            {"left": {"indicator": "rsi", "length": 14}, "comparator": "less_than", "right": {"value": 200}},
        ],
    }

    result = evaluate_rule(df, rule)

    assert result.any()  # RSI is always < 200


def test_evaluate_rule_all_combinator_requires_every_condition():
    df = _trending_df()
    rule = {
        "combinator": "all",
        "conditions": [
            {"left": {"indicator": "close"}, "comparator": "greater_than", "right": {"value": 1_000_000}},  # never true
            {"left": {"indicator": "rsi", "length": 14}, "comparator": "less_than", "right": {"value": 200}},  # always true
        ],
    }

    result = evaluate_rule(df, rule)

    assert not result.any()


def test_evaluate_rule_any_combinator_needs_just_one_condition():
    df = _trending_df()
    rule = {
        "combinator": "any",
        "conditions": [
            {"left": {"indicator": "close"}, "comparator": "greater_than", "right": {"value": 1_000_000}},  # never true
            {"left": {"indicator": "rsi", "length": 14}, "comparator": "less_than", "right": {"value": 200}},  # always true
        ],
    }

    result = evaluate_rule(df, rule)

    # RSI is undefined (NaN) while price is perfectly flat -- there's no
    # gain/loss to compute a ratio from -- so it stays NaN for the entire
    # 60-bar flat section, not just a fixed warm-up window, and a NaN
    # comparison reads as False after fillna(False). Check only the tail
    # of the uptrend, well past where RSI has real values every bar.
    assert result.iloc[-10:].all()


def test_evaluate_rule_rejects_unknown_indicator():
    df = _trending_df()
    rule = {"combinator": "all", "conditions": [{"left": {"indicator": "made_up"}, "comparator": "greater_than", "right": {"value": 1}}]}

    with pytest.raises(InvalidStrategyParamsError):
        evaluate_rule(df, rule)


def test_evaluate_rule_rejects_unknown_comparator():
    df = _trending_df()
    rule = {"combinator": "all", "conditions": [{"left": {"indicator": "close"}, "comparator": "made_up", "right": {"value": 1}}]}

    with pytest.raises(InvalidStrategyParamsError):
        evaluate_rule(df, rule)


def test_evaluate_rule_rejects_unknown_combinator():
    df = _trending_df()
    rule = {"combinator": "made_up", "conditions": [{"left": {"indicator": "close"}, "comparator": "greater_than", "right": {"value": 1}}]}

    with pytest.raises(InvalidStrategyParamsError):
        evaluate_rule(df, rule)


def test_evaluate_rule_rejects_indicator_missing_required_length():
    df = _trending_df()
    rule = {"combinator": "all", "conditions": [{"left": {"indicator": "ema"}, "comparator": "greater_than", "right": {"value": 1}}]}

    with pytest.raises(InvalidStrategyParamsError):
        evaluate_rule(df, rule)


def test_evaluate_rule_rejects_empty_conditions_list():
    df = _trending_df()
    rule = {"combinator": "all", "conditions": []}

    with pytest.raises(InvalidStrategyParamsError):
        evaluate_rule(df, rule)
