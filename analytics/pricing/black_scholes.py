import math

from scipy.stats import norm

# Reference implementation, informed by the standard closed-form
# Black-Scholes formula (financial-models-numerical-methods' treatment of
# it, reimplemented locally rather than installed as a dependency -- see
# docs/superpowers/specs/2026-08-10-options-vol-strategy-design.md).
#
# Used only as a cross-check / fallback: yfinance's own `impliedVolatility`
# field is the primary IV source (it's free and already fetched), but it is
# sometimes 0 or missing for illiquid/stale-quoted contracts. bs_implied_vol
# recovers an IV estimate from a contract's lastPrice in that situation.


def bs_price(S: float, K: float, T: float, r: float, sigma: float, option_type: str = "call") -> float:
    """European option price. T is in years, r and sigma are annualized
    decimals (0.05 = 5%). Falls back to intrinsic value at/after expiry or
    for a degenerate (zero-vol) input, rather than dividing by zero."""
    if T <= 0 or sigma <= 0:
        return max(0.0, S - K) if option_type == "call" else max(0.0, K - S)

    d1 = (math.log(S / K) + (r + 0.5 * sigma ** 2) * T) / (sigma * math.sqrt(T))
    d2 = d1 - sigma * math.sqrt(T)

    if option_type == "call":
        return S * norm.cdf(d1) - K * math.exp(-r * T) * norm.cdf(d2)
    return K * math.exp(-r * T) * norm.cdf(-d2) - S * norm.cdf(-d1)


def bs_implied_vol(
    price: float, S: float, K: float, T: float, r: float, option_type: str = "call",
    tol: float = 1e-6, max_iter: int = 100,
) -> float | None:
    """Newton-Raphson solve for the implied volatility that reprices a
    quoted option price. Returns None rather than raising when it fails to
    converge (a stale/crossed quote outside any achievable no-arbitrage
    bound) -- the caller falls back to skipping that contract, not to a
    fabricated number."""
    if T <= 0 or price <= 0:
        return None

    sigma = 0.3  # reasonable starting guess for a Newton-Raphson search
    for _ in range(max_iter):
        model_price = bs_price(S, K, T, r, sigma, option_type)
        d1 = (math.log(S / K) + (r + 0.5 * sigma ** 2) * T) / (sigma * math.sqrt(T))
        vega = S * norm.pdf(d1) * math.sqrt(T)
        if vega < 1e-8:
            return None

        diff = model_price - price
        if abs(diff) < tol:
            return sigma

        sigma -= diff / vega
        if sigma <= 0:
            return None

    return None
