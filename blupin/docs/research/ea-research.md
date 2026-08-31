# MetaTrader free-EA research → TradingView blueprint (2026-08-29)

Sources: live MQL5 market listings (MT4 + MT5 free EA sections), individual
product pages and review sections, cross-checked; concepts backtested on this
repo's cached gold data (2y hourly, 15y daily, 60d 30m). No proprietary code
examined or reproduced — public descriptions and reviews only.

## 1. Shortlist (15 EAs)

| EA | MT | Rating | Reviews | Market | TF | Strategy | Money-mgmt risk |
|---|---|---|---|---|---|---|---|
| Dark Venus | 4+5 | 4.61 | 1,947 (MT5) + 1,053 (MT4) | FX majors | M5/M15 | Bollinger counter-trend scalp | **GRID/MARTINGALE** manages trades to basket TP |
| EA Gold Stuff | 4 | 4.7 | 1,429 | XAUUSD | H1 | Trend-follow via Gold Stuff indicator | **GRID/averaging + lot multiplier** |
| Dark Moon | 4+5 | 4.62–4.72 | 441 (MT5) + 341 (MT4) | FX majors | M5–H1 | Trend-indicator scalp (Dark Absolute Trend: trend + candle patterns + volatility) | No grid claimed; heavy settings dependence |
| Stratos Goldwind | 4+5 | 4.68 | 383 (MT5) + 202 (MT4) | XAUUSD | M15–H4 | Stratos Pali trend histogram + reversal arrows, 10 modes | Per-trade SL/TP, no grid claimed; opaque logic |
| Stratos Zephyr | 4+5 | 4.76 | 195+57 | FX | M15+ | RSI-based, 10 modes | Same family; opaque |
| Lite Hamster Scalping | 4 | 4.11 | 61 | EURUSD/gold | M5 | Night scalp, indicator filters, virtual SL/TP, spread cap | No classic martingale; spread/broker sensitive |
| HedgingMartingale | 5 | 4.73 | 131 | FX | any | Hedging + martingale (honest name) | **MARTINGALE** by design |
| Grid Scalper MA | 5 | 4.51 | 98 | FX | any | MA + dynamic grid | **GRID** |
| MyGrid Scalper | 4 | 3.94 | 52 | FX | any | Grid + "soft martingale" | **GRID/MART** |
| RangeBreakout EA | 5 | 4.47 | 90 | any (XAGUSD noted) | any | Time-range/session breakout, range-based SL, partial TP, trailing | Clean: "No Martingale. No Grid." |
| Universal Breakout | 4+5 | 4.24–4.59 | 64+39 | any | H1 | Interval H/L + stop orders, offset, BE, trailing, order expiry | Clean, transparent, simple |
| ZigZag Extremum points | 4 | 4.81 | 32 | FX | any | Extremum breakout via ZigZag | **REPAINT RISK** (ZigZag pivots finalize late) |
| Supertrend G5 | 5 | 4.86 | 28 | XAUUSD | M5 | Supertrend-based | Small sample of reviews |
| Gold Zone EA | 5 | 4.04 | 46 | XAUUSD | — | Supply/demand zones | Weakest rating of the set |
| MATrader AI | 4 | 4.57 | 139 | XAUUSD | — | "Neural network + cycles" | Marketing-opaque; untranslatable |

## 2. Review analysis — the recurring truths

Positive themes across strong performers: works "once tuned", high win
rates, responsive developers, session/time filters praised, spread caps
praised on scalpers.

Negative themes, remarkably consistent:
- "Only losses on gold" / "be aware of DD" on every grid-managed system.
- "Requires optimization; defaults underperform" on nearly everything —
  free EAs ship de-tuned, users trade the overfit.
- Broker/spread/slippage sensitivity on all sub-M15 scalpers.
- Breakout EAs: "false breakout filtering inadequate".
- Live-vs-backtest divergence complaints concentrate in tick-sensitive
  scalpers and grid systems.

## 3. Edge vs money management — verdicts

Masking money management (high win rate, catastrophic tail): Dark Venus
(basket-managed counter-trend), EA Gold Stuff (averaging + lot multiply),
HedgingMartingale, Grid Scalper MA, MyGrid Scalper, Quantitative Athena
("repositioning"). Their star ratings measure the years grid works, not the
week it doesn't.

Genuine (if modest) entry/exit methodology: RangeBreakout, Universal
Breakout (defined range, defined SL, BE, trailing), Dark Moon (trend filter
+ volatility + candle structure, per-trade exits), Stratos family (per-trade
SL/TP off a trend indicator), Lite Hamster (session filter + spread cap).

## 4. Recurring credible concepts (across ≥3 systems)

1. Session/time windows (night scalpers, range breakout EAs, schedule filters)
2. Defined range → breakout with offset + range-based SL (both breakout EAs)
3. Trend filter before entry (Dark Moon, Stratos, Gold Stuff, Supertrend G5)
4. Volatility awareness (Dark Moon; ATR-sized stops in breakout EAs)
5. Per-trade SL/TP + breakeven + trailing (every credible system)
6. Spread/condition caps — refuse bad conditions (Hamster, scalpers)
7. Counter-trend fade at band extremes (Dark Venus family — the entry idea,
   separable from its grid management)

## 5. Backtests of the extracted concepts on OUR gold data

| Concept (as tested this session) | Result | Verdict |
|---|---|---|
| Sweep & reclaim of frozen prev-day/late-range levels (rank-1 ULT) | +87.2%, 53% win, halves +33/+55 | **Best tested concept** |
| Time-of-day seasonality (16:00/14:00 JHB) | +25.5% @ 82% win (scalp) / +71% @ 57% (rider), OOS-validated | Strong second |
| Daily MR (Double 7s, uptrend) | 68% win, PF 1.65 (15y) | Solid, daily TF |
| Supertrend(10,3) flip-to-flip 1h | 39.5% win, +37.9%, halves +27/+11 | Real trend edge, low win rate |
| Asian-session BB fade (night-scalp archetype, no grid) | best cell 51.7% win +13.4% stable; 80%-win cells LOSE | Marginal; geometry, not edge |
| Session/opening-range breakout (ORB 09:00/15:00) | ≈ breakeven best cells | Weak on gold |
| Grid/martingale management | not tested — the mechanism IS the tail risk | Excluded |

## 6. Why the strongest robots actually work

Not "AI", not indicator count. The mechanisms: (1) they refuse to trade most
of the time — session windows, spread caps, trend gates cut exposure to the
hours/conditions where their edge exists; (2) volatility-adaptive stops;
(3) defined invalidation per trade (the credible ones) vs deferred loss
realization (the grid ones); (4) one clear setup definition rather than
indicator confluence stacks; (5) asymmetric exits (partial TP/BE/trail)
letting a mediocre hit rate stay net positive.

## 7. Rankings (robustness→translatability, per brief §7)

1. Time-range breakout w/ range-based risk (RangeBreakout/Universal) —
   transparent, deterministic, non-repainting; but tested weak on gold.
2. Trend-filtered scalp w/ volatility gate (Dark Moon concept) — translatable;
   settings-fragile.
3. Session mean-reversion fade (Hamster/Dark Venus entry minus grid) —
   translatable; marginal edge on gold.
4. Supply/demand & "neural" systems — opaque, weak evidence, skip.
5. ZigZag extremum — repaints; excluded per §12 unless redesigned to
   confirmed-pivot logic (lag makes it a different system).
6. All grid/martingale — excluded regardless of rating.

## 8. Final recommendations

- Best overall method: liquidity sweep & reclaim of frozen session/day levels
  (our own tested implementation beats every EA concept imported).
- Best trend method: Supertrend/EMA-aligned trend hold with ATR trail (accept
  ~40% win rate).
- Best reversal method: band/extreme fade ONLY with a session window and hard
  per-trade SL (never grid-managed).
- Best breakout method: time-range breakout with range-based SL + BE + trail;
  on gold, demand a volatility-expansion confirmation before trusting it.
- Best risk management: ATR-sized SL, structure-based targets, breakeven
  ratchet, trailing in trends, min R:R gate, one position, spread/vol caps.
- Avoid: martingale, grid recovery, unlimited averaging, basket TPs, lot
  multiplication, sub-M15 tick-sensitive scalping, ZigZag-repaint logic,
  "AI/neural" black boxes.

## 9. Blueprint — "BluPin Apex" (hierarchical, WAIT-first)

L1 REGIME: ATR14 vs SMA(ATR,100): <0.7× = LOW-VOL (no breakout setups);
  >2.5× = SHOCK (NO TRADE). EMA50 vs EMA200 + slope: TRENDING vs RANGING.
L2 DIRECTION: 4h EMA alignment + last confirmed structure break direction
  → BULL / BEAR / NEUTRAL (NEUTRAL ⇒ WAIT).
L3 SETUP (first match wins):
  a. SWEEP: wick through frozen prev-day H/L or late-session range edge...
  b. PULLBACK (TRENDING only): retrace to EMA20/structure in trend direction.
  c. BREAKOUT (TRENDING + vol expansion only): close beyond N-hour range.
L4 CONFIRMATION: one close-based confirmation per setup — sweep: close back
  inside (reclaim); pullback: rejection close in trend direction; breakout:
  close beyond + ATR expansion vs prior bar. No confluence stacks.
L5 ENTRY: on the confirming candle close only. States: BUY / SELL / WAIT /
  NO TRADE (shock or spread-hostile hours).
L6 RISK: SL = beyond setup structure + 0.25 ATR, capped 1.5 ATR. TP =
  opposing structure level, min R:R 1.5 else WAIT. BE at +1.0 ATR; ATR trail
  (2×) in TRENDING regime. One position; session filter default 08:00-22:00
  JHB for pullback/breakout, 00:00-03:00 retained for sweep setups.
Non-repainting: confirmed closes only, frozen levels, no lookahead security,
no ZigZag.

---

## Addendum: Kronos foundation-model benchmark (2026-08-29)

Kronos-small (24.7M params, NeoQuasar/Kronos-small + Tokenizer-base) run
walk-forward over the cached hourly gold data: 150 points, 256-bar context,
1-4 bar horizon, sampling T=1.0/top_p=0.9, one sample per point, CPU. No
future leakage.

| Horizon | Kronos accuracy | Always-up baseline | Coin |
|---|---|---|---|
| +1 bar  | 50.0% | 52.0% | 50% |
| +4 bars | 46.7% | 50.7% | 50% |

Halves: +1 bar 53.3%/46.7%, +4 bars 49.3%/44.0% — no stability, drifting
below chance. Verdict: no directional edge on hourly gold; it fails to match
even the naive always-up drift. Consistent with the authors' own disclaimer
(forecasting ≠ tradable signals). Caveats: small model variant, single
sample per point, 150 points, gold futures may sit outside its training
distribution's core. Practical conclusion: the repo's rule-based engines
(sweep-reclaim, time-of-day) remain strictly better on this instrument;
Kronos stays out of the stack.
