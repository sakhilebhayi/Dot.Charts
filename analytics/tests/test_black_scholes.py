import math

import pytest
from pricing.black_scholes import bs_implied_vol, bs_price


def test_bs_price_matches_known_textbook_values():
    # S=100, K=100, T=1y, r=5%, sigma=20% -- a standard reference case
    # (e.g. Hull's "Options, Futures, and Other Derivatives").
    call = bs_price(100, 100, 1, 0.05, 0.2, "call")
    put = bs_price(100, 100, 1, 0.05, 0.2, "put")

    assert call == pytest.approx(10.4506, abs=1e-3)
    assert put == pytest.approx(5.5735, abs=1e-3)


def test_bs_price_satisfies_put_call_parity():
    S, K, T, r, sigma = 150.0, 140.0, 0.5, 0.03, 0.35
    call = bs_price(S, K, T, r, sigma, "call")
    put = bs_price(S, K, T, r, sigma, "put")

    # C - P = S - K*e^(-rT), independent of sigma -- a model-free
    # no-arbitrage identity, so this holds regardless of the vol input.
    lhs = call - put
    rhs = S - K * math.exp(-r * T)
    assert lhs == pytest.approx(rhs, abs=1e-6)


def test_bs_price_falls_back_to_intrinsic_value_at_expiry():
    # T=0: no time value left, price must equal intrinsic value exactly.
    assert bs_price(110, 100, 0, 0.05, 0.2, "call") == 10.0
    assert bs_price(90, 100, 0, 0.05, 0.2, "call") == 0.0
    assert bs_price(90, 100, 0, 0.05, 0.2, "put") == 10.0


def test_bs_implied_vol_round_trips_a_price():
    S, K, T, r, sigma_true = 200.0, 195.0, 0.25, 0.04, 0.28
    price = bs_price(S, K, T, r, sigma_true, "call")

    recovered = bs_implied_vol(price, S, K, T, r, "call")

    assert recovered == pytest.approx(sigma_true, abs=1e-4)


def test_bs_implied_vol_returns_none_for_an_unconverging_input():
    # A price nobody would ever legitimately quote (larger than the
    # underlying itself) -- the solver must give up cleanly, not throw or
    # return a fabricated number.
    assert bs_implied_vol(price=500.0, S=100.0, K=100.0, T=0.1, r=0.05, option_type="call") is None
