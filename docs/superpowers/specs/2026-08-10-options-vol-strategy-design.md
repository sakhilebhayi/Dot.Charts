# Options Pricing / Volatility Signals — Design

**Roadmap:** sub-project 4 of 4 in the "quant-repo-derived strategy
expansion" initiative (see `2026-08-10-momentum-strategy-design.md` for the
initiative overview). This is the largest of the four — a new instrument
class, not just a new strategy — and should get its own planning pass
(and likely its own follow-up brainstorming session) before implementation
starts, rather than being scheduled directly off this spec.

**Provenance:** pricing formulas (Black-Scholes, and optionally binomial
tree as a cross-check) are reimplemented locally as a small reference
module, informed by financial-models-numerical-methods — not installed as
a dependency. Options-chain data comes from `yfinance` (already a
dependency, via `Ticker.option_chain()`), so no new external data source
is needed.

## Problem

Every existing strategy — including the other three sub-projects in this
initiative — trades an equity/forex/crypto/commodity *underlying* priced
as a simple OHLCV series. Options are a different instrument entirely:
priced by strike, expiry, and implied volatility, not just spot price, and
nothing in the platform's data model (`BacktestRun` schema, symbol input,
OHLCV fetch path) has a concept of "underlying + expiry + strike."

## Scope Decision: Signal Generation, Not Options Backtesting

Building a full options backtesting engine (simulating a position's P&L
day-by-day across changing spot, IV, and time decay) is a substantially
larger project than anything else in this initiative — it needs
historical options-chain data (yfinance only exposes the *current* chain,
not historical snapshots), proper Greeks-based P&L simulation, and
touches almost every layer (`BacktestRun` schema, `metrics.py`, the
frontend's entire backtest results view assumes an equity-style trade
list).

This spec proposes a **narrower first slice**: a **volatility-signal
strategy that generates a directional/vol-regime read on the
*underlying*, informed by current options-chain data** — not a strategy
that backtests option positions themselves. Concretely: "AAPL's IV rank
is at the 85th percentile and put-call skew is elevated → vol-regime
signal on AAPL" is in scope; "backtest buying AAPL $150 calls expiring in
30 days over the last year" is not, in this slice.

This keeps the deliverable inside the existing single-underlying-symbol
data model (no `BacktestRun` schema change, no new frontend results view)
while still surfacing genuinely new options-derived information. Full
options-position backtesting is left as an explicit future sub-project
if wanted, gated on sourcing historical chain data (a real, currently
unsolved data problem, not just an engineering one).

## Signal Definition

New module `analytics/pricing/black_scholes.py` (reference implementation,
not a strategy itself):

```python
def bs_price(S, K, T, r, sigma, option_type="call") -> float: ...
def bs_implied_vol(price, S, K, T, r, option_type="call") -> float: ...
```

Standard closed-form Black-Scholes + a Newton-Raphson or Brent's-method IV
solver, used only as a cross-check against yfinance's own reported IV
field (which can be stale/wide-quoted for illiquid strikes) — yfinance's
chain data is the primary IV source since it's free and already fetched.

`analytics/strategies/options_vol.py`:

```python
DEFAULT_PARAMS = {
    "iv_rank_window_days": 252,
    "iv_rank_high": 80,   # percentile threshold for "elevated" IV
    "iv_rank_low": 20,
    "skew_threshold": 0.05,  # put IV - call IV, at matched delta/moneyness
}
```

1. **Fetch chain**: `yf.Ticker(symbol).option_chain(nearest_expiry)` for
   calls and puts.
2. **IV rank**: current ATM IV vs. its own trailing `iv_rank_window_days`
   range (requires storing/approximating a trailing IV history — see Open
   Questions; yfinance has no historical-IV endpoint, so this needs either
   an approximation via historical realized volatility as a proxy, or the
   platform accumulating its own IV snapshots over time).
3. **Put-call skew**: IV difference between matched-moneyness put and call
   strikes — a standard directional-sentiment proxy (elevated put IV
   relative to calls signals downside hedging demand).
4. **Signal**: this produces a *regime read*, not an entry/exit pair on a
   price series like every other strategy — it doesn't fit the
   `generate_signals(df) -> (entries, exits)` contract at all. Proposed
   shape: a standalone `analyze()` function returning a structured signal
   object (`{"iv_rank": .., "skew": .., "regime": "elevated_iv" | "normal" | ...}`),
   exposed through a **new endpoint** (`GET /options/vol-signal/{symbol}`)
   rather than shoehorned into `/backtest` — it isn't a backtest at all,
   it's a current-state read, same category as chart analysis
   (`POST /api/chart/analyze`) rather than the backtest family.

## Backend Changes

- **New analytics endpoint**: `GET /options/vol-signal/{symbol}` in
  `main.py`, separate from `/backtest` — matches the scope decision that
  this isn't a backtestable strategy.
- **New Laravel route + controller**: `OptionsVolController` (new,
  mirrors `ChartAnalysisController`'s pattern of proxying to the analytics
  service for a real-time read rather than a persisted backtest run) —
  `GET /api/options/vol-signal/{symbol}`.
- **Disclosure**: still required — this is a signal a user could act on,
  so it needs the same confidence-band/attribution/risk-disclosure
  treatment as every backtest result, even though it isn't a `BacktestRun`.
  `DisclosureFormatter` needs a new code path for this response shape.
- **No `BacktestRun` schema change** in this slice — deliberately, per
  the Scope Decision above.
- **Frontend**: new page or a new panel on an existing page (e.g. an
  "Options Vol" card on `backtest.html` or its own `options.html`) — this
  needs a product decision (where does this surface live?) that's a
  design question, not an architecture one, and is called out in Open
  Questions rather than decided here.

## Testing

- **`analytics/tests/test_black_scholes.py`**: pricing sanity checks
  against known textbook values (e.g. ATM call/put parity), and an IV
  solver round-trip test (price → implied vol → price again, within
  tolerance).
- **`analytics/tests/test_options_vol.py`**: mocked `option_chain()`
  response (matching the existing pattern of mocking `yfinance`/`ccxt`
  calls elsewhere in the test suite) asserting IV rank and skew
  calculations against hand-computed expected values on a fixed synthetic
  chain.
- **`backend/tests/Feature/OptionsVolControllerTest.php`**: asserts the
  route proxies correctly and the response carries a disclosure object.

## Open Questions

| Question | Owner → Approver |
|---|---|
| **IV-rank history source**: yfinance has no historical-IV endpoint. Options: (a) approximate using trailing realized volatility of the underlying as an IV proxy (available today, imperfect), (b) have the platform start accumulating its own daily IV snapshots going forward and only offer IV rank once enough history exists, (c) source a paid historical-IV data provider. This blocks a real implementation of the IV-rank feature and needs a decision before building. | You → — |
| **Where does this surface in the frontend?** New page vs. a panel on an existing page — product/UX decision, not covered by this spec. | You → — |
| **Full options-position backtesting** (buying/selling actual contracts, P&L over time) is explicitly out of scope for this slice per the Scope Decision — confirm that's acceptable as a v1, with position-level backtesting deferred to its own future spec once historical chain data is solved. | You → — |
| Confirm the new-endpoint approach (`/options/vol-signal`, outside the `/backtest` family) rather than force-fitting this into the existing backtest contract — recommended given the signal shape genuinely doesn't have entries/exits/a portfolio. | You → — |
