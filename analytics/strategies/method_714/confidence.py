# Weights match the Pine source's f_confidence function exactly. Raw sum is
# 120 (not 100) — the source caps the total at 100 rather than normalizing
# the weights to sum to 100, so a strong-but-imperfect signal (missing one
# or two components) can still reach the cap.
WEIGHTS = {
    "session": 30,
    "trend": 15,
    "mtf": 15,
    "atr": 15,
    "volume": 10,
    "structure": 10,
    "sweep": 5,
    "pa_quality": 10,
    "clv": 5,
    "prev_day_sweep": 5,
}


def compute_confidence(
    direction: int,
    trend_ok: bool,
    mtf_ok: bool,
    atr_ok: bool,
    volume_ok: bool,
    structure_aligned: bool,
    sweep_aligned: bool,
    pa_quality_ok: bool,
    clv_ok: bool,
    prev_day_sweep_aligned: bool,
) -> dict:
    """
    Returns {"score": int, "breakdown": {component: points}} — the
    breakdown exists so a confidence number is never opaque: callers can
    see exactly which components fired and how many points each
    contributed, per the explainability requirement.
    """
    if direction == 0:
        return {"score": 0, "breakdown": {k: 0 for k in WEIGHTS}}

    breakdown = {
        "session": WEIGHTS["session"],
        "trend": WEIGHTS["trend"] if trend_ok else 0,
        "mtf": WEIGHTS["mtf"] if mtf_ok else 0,
        "atr": WEIGHTS["atr"] if atr_ok else 0,
        "volume": WEIGHTS["volume"] if volume_ok else 0,
        "structure": WEIGHTS["structure"] if structure_aligned else 0,
        "sweep": WEIGHTS["sweep"] if sweep_aligned else 0,
        "pa_quality": WEIGHTS["pa_quality"] if pa_quality_ok else 0,
        "clv": WEIGHTS["clv"] if clv_ok else 0,
        "prev_day_sweep": WEIGHTS["prev_day_sweep"] if prev_day_sweep_aligned else 0,
    }
    score = min(sum(breakdown.values()), 100)
    return {"score": score, "breakdown": breakdown}


def extension_ok(
    open_price: float, close_price: float, atr_value: float, min_mult: float = 0.10, max_mult: float = 3.00
) -> bool:
    """
    Hard gate (not scored): the session's |close - open| must be between
    min_mult and max_mult times ATR. Below min = no conviction; above max
    = the move is already exhausted, don't chase it. Enforced in both
    filter modes, matching the Pine source's own "(hard gate)" labeling.
    """
    if atr_value <= 0:
        return False
    extension = abs(close_price - open_price)
    return min_mult * atr_value <= extension <= max_mult * atr_value


def pa_quality_ok(
    direction: int,
    open_price: float,
    high: float,
    low: float,
    close: float,
    mode: str,
    body_min: float = 0.50,
    wick_min: float = 0.33,
) -> bool:
    """
    Momentum mode: the signal-bar candle must have a strong directional
    body (body/range >= body_min) in the signal's direction. Contrarian
    (and retest) modes: the candle must show a rejection wick on the bias
    side (evidence the fade/rejection has already started).
    """
    candle_range = high - low
    if candle_range <= 0:
        return False

    if mode == "momentum":
        correct_direction = (direction == 1 and close > open_price) or (direction == -1 and close < open_price)
        body = abs(close - open_price)
        return correct_direction and (body / candle_range) >= body_min

    lower_wick = min(close, open_price) - low
    upper_wick = high - max(close, open_price)
    if direction == 1:
        return (lower_wick / candle_range) >= wick_min
    return (upper_wick / candle_range) >= wick_min


def clv_ok(direction: int, high: float, low: float, close: float, min_pct: float = 25.0) -> bool:
    """
    Close Location Value: where the bar closed within its own high-low
    range. Longs require the close to be at least min_pct off the low
    (absorption, not free-fall); shorts mirror at the high.
    """
    candle_range = high - low
    if candle_range <= 0:
        return False
    clv_pct = 100.0 * (close - low) / candle_range
    if direction == 1:
        return clv_pct >= min_pct
    return (100 - clv_pct) >= min_pct
