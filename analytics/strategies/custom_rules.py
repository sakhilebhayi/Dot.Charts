import pandas as pd
import pandas_ta as ta


class InvalidStrategyParamsError(Exception):
    pass


_RAW_COLUMNS = ("close", "open", "high", "low", "volume")
_LENGTH_ONLY_INDICATORS = ("ema", "sma", "rsi", "atr")
_BB_INDICATORS = {"bb_lower": 0, "bb_mid": 1, "bb_upper": 2}
_COMPARATORS = ("crosses_above", "crosses_below", "greater_than", "less_than")


def _require(operand: dict, key: str, indicator_name: str):
    if key not in operand:
        raise InvalidStrategyParamsError(f"Indicator '{indicator_name}' requires '{key}'")
    return operand[key]


def _resolve_operand(df: pd.DataFrame, operand: dict) -> pd.Series:
    if "value" in operand:
        return pd.Series(operand["value"], index=df.index)

    if "indicator" not in operand:
        raise InvalidStrategyParamsError(f"Operand must have 'indicator' or 'value': {operand}")

    name = operand["indicator"]

    if name in _RAW_COLUMNS:
        return df[name]

    if name in _LENGTH_ONLY_INDICATORS:
        length = _require(operand, "length", name)
        if name == "ema":
            return ta.ema(df["close"], length=length)
        if name == "sma":
            return ta.sma(df["close"], length=length)
        if name == "rsi":
            return ta.rsi(df["close"], length=length)
        return ta.atr(df["high"], df["low"], df["close"], length=length)

    if name in _BB_INDICATORS:
        length = _require(operand, "length", name)
        std = _require(operand, "std", name)
        # Selected positionally rather than by exact column name --
        # pandas_ta's bbands() column-name suffix format is not consistent
        # across releases, but the column order (lower, mid, upper,
        # bandwidth, percent) is stable (see bollinger_mean_reversion.py's
        # identical reasoning).
        bands = ta.bbands(df["close"], length=length, std=std)
        return bands.iloc[:, _BB_INDICATORS[name]]

    raise InvalidStrategyParamsError(f"Unknown indicator: {name}")


def _apply_comparator(left: pd.Series, right: pd.Series, comparator: str) -> pd.Series:
    if comparator == "crosses_above":
        return (left > right) & (left.shift(1) <= right.shift(1))
    if comparator == "crosses_below":
        return (left < right) & (left.shift(1) >= right.shift(1))
    if comparator == "greater_than":
        return left > right
    if comparator == "less_than":
        return left < right
    raise InvalidStrategyParamsError(f"Unknown comparator: {comparator}")


def evaluate_rule(df: pd.DataFrame, rule: dict) -> pd.Series:
    combinator = rule.get("combinator")
    if combinator not in ("all", "any"):
        raise InvalidStrategyParamsError(f"Unknown combinator: {combinator}")

    conditions = rule.get("conditions")
    if not conditions:
        raise InvalidStrategyParamsError("Rule must have at least one condition")

    signals = []
    for condition in conditions:
        comparator = condition.get("comparator")
        if comparator not in _COMPARATORS:
            raise InvalidStrategyParamsError(f"Unknown comparator: {comparator}")
        left = _resolve_operand(df, condition.get("left", {}))
        right = _resolve_operand(df, condition.get("right", {}))
        signals.append(_apply_comparator(left, right, comparator))

    combined = signals[0]
    for signal in signals[1:]:
        combined = (combined & signal) if combinator == "all" else (combined | signal)

    return combined.fillna(False)
