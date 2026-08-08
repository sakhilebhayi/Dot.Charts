from strategies.method_714.confidence import (
    compute_confidence,
    extension_ok,
    pa_quality_ok,
    clv_ok,
    WEIGHTS,
)


def test_compute_confidence_sums_each_component_by_its_documented_weight():
    result = compute_confidence(
        direction=1,
        trend_ok=True,
        mtf_ok=False,
        atr_ok=True,
        volume_ok=False,
        structure_aligned=True,
        sweep_aligned=False,
        pa_quality_ok=True,
        clv_ok=False,
        prev_day_sweep_aligned=True,
    )

    expected = WEIGHTS["session"] + WEIGHTS["trend"] + WEIGHTS["atr"] + WEIGHTS["structure"] \
        + WEIGHTS["pa_quality"] + WEIGHTS["prev_day_sweep"]
    assert result["score"] == expected
    assert result["breakdown"]["mtf"] == 0
    assert result["breakdown"]["trend"] == WEIGHTS["trend"]


def test_compute_confidence_returns_zero_when_no_direction():
    result = compute_confidence(
        direction=0, trend_ok=True, mtf_ok=True, atr_ok=True, volume_ok=True,
        structure_aligned=True, sweep_aligned=True, pa_quality_ok=True, clv_ok=True,
        prev_day_sweep_aligned=True,
    )

    assert result["score"] == 0


def test_compute_confidence_caps_at_100_even_though_raw_weights_sum_higher():
    result = compute_confidence(
        direction=1, trend_ok=True, mtf_ok=True, atr_ok=True, volume_ok=True,
        structure_aligned=True, sweep_aligned=True, pa_quality_ok=True, clv_ok=True,
        prev_day_sweep_aligned=True,
    )

    raw_sum = sum(WEIGHTS.values())
    assert raw_sum > 100  # the source's own weights sum to 120 before capping
    assert result["score"] == 100


def test_extension_ok_rejects_moves_too_small_or_too_large():
    # ATR = 10; band is [1.0, 30.0]
    assert extension_ok(open_price=100, close_price=100.5, atr_value=10) is False  # 0.5 < min
    assert extension_ok(open_price=100, close_price=105, atr_value=10) is True  # 5 within band
    assert extension_ok(open_price=100, close_price=140, atr_value=10) is False  # 40 > max


def test_pa_quality_ok_momentum_mode_requires_strong_body_in_signal_direction():
    # Strong bullish body: open=100, close=110, range=12 (body=10, body/range=0.83)
    assert pa_quality_ok(direction=1, open_price=100, high=111, low=99, close=110, mode="momentum") is True
    # Weak body (body/range < 0.50)
    assert pa_quality_ok(direction=1, open_price=100, high=120, low=80, close=102, mode="momentum") is False


def test_pa_quality_ok_contrarian_mode_requires_rejection_wick():
    # Bullish bias: strong lower wick (rejection from the low)
    assert pa_quality_ok(direction=1, open_price=100, high=101, low=80, close=100.5, mode="contrarian") is True
    # No meaningful lower wick: range=1.2, wick=0.2, ratio=0.167 < 0.33
    assert pa_quality_ok(direction=1, open_price=100, high=101, low=99.8, close=100.5, mode="contrarian") is False


def test_clv_ok_requires_close_far_enough_from_the_bias_side_extreme():
    # Bullish: close near the high of the range -> good CLV
    assert clv_ok(direction=1, high=110, low=100, close=109, min_pct=25.0) is True
    # Bullish: close near the low of the range -> poor CLV
    assert clv_ok(direction=1, high=110, low=100, close=101, min_pct=25.0) is False
