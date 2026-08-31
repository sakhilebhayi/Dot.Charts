# Win-Rate Improvement Study — 2026-08-31

Baseline: user's live 30m tester Jan 2–Aug 31 2026 = **46.15% win (78/169), PF 2.72**,
smooth equity. Sim baseline (1h resolution, pessimistic on wick stop-outs): window
38.04%/163, full 2y 28.79%/594/+89.9 ATR. All findings judged as RELATIVE deltas vs the
sim baseline; robust = effect direction holds in both halves of the 2y sample.

## Robust, adoptable

| Change | Window win Δ | 2y pnl effect | Notes |
|---|---|---|---|
| **Skip Friday** | +4.3pp (38.0→42.3) | pnl flat/up (Fri is −11.7 ATR by itself) | Cleanest lever. Fri 20.5% win 2y, negative pnl in both halves and 2026. |
| **+ DXY conditional veto** (skip when overnight DXY move is top-tercile AND against the signal) | +6.1pp combined (38.0→44.1) | 2y pnl 89.9→108.9 | Win-rate robust both halves; pnl gain is recent-regime-driven (h1 flat). Combo skips ~38% of days. |
| **Trend-agree veto** (skip days signal points WITH the 20:00→03:00 drift) | +0.6pp | 2y pnl 89.9→108.5 | Agree days robustly LOSE (−18.7 ATR). The engine's edge IS the fade. |
| **Widen SL 1.0→~1.4–1.5 ATR** | +0.6–2.0pp | 2y pnl +22% (89.9→109.6) | Strictly dominates in sim; consistent with the earlier SL1.5+ride +103.5% test. |
| **TP cap at 4 ATR (SL 1.4)** | +1.8pp | keeps 96% of 2y pnl | Best true exit tweak; only clips monster days. |

## Rejected (tested, failed)

- **Partial bank at 1.0 ATR**: window win → 51.5% but 2y pnl ≈ ZERO (+2.2 ATR). The
  win-rate mirage — banking the +1 ATR pocket amputates the tail that pays for everything.
- **Breakeven stops** (0.5/1.0/1.5 ATR): worse on BOTH win rate and pnl, every cell.
- **Trend-following** (flip/replace with the overnight trend): beats baseline only in the
  2024/early-25 half; collapses to ~30% win on the 2026 window. Decayed regime.
- **Range-width filter, plain DXY sign, monthly seasonality**: flip between halves — noise.
- **Sweep vs fallback**: fallback looks better (39% vs 28%) but n=28 < 30 — not actionable.

## Loss anatomy (why the win rate is what it is)

- 56% of losers never reached even +0.5 ATR — dead on arrival, no exit tweak saves them.
- 21% of losers had ≥1.0 ATR open profit before dying; 87% of winners pass +1.0 ATR.
  That gap is real but monetizing it (partial/BE) destroys the economics — see above.
- SL losers cost −1.10 ATR avg; cancel losers −0.51 ATR.
- **Cancel rule at live timing is net-negative over 2y** (−24.5 ATR; 86% of cancelled
  trades that would have reached day end would have won) but the effect flips between
  halves and was mildly positive in 2026 — left as user preference, watch forward.
- Entry delay (+1–2h) raises win rate only via interaction with the cancel rule and
  bleeds 33–42% of window ride pnl — not recommended.

## Verdict

Realistic honest ceiling with economics intact: **~50–53%** (Friday skip → ~50%; + DXY
veto → ~52%; + wider SL pushes both win rate and profit). Anything above ~55% on this
engine is bought by trading away the asymmetric payoff (partials → mid-50s win, PF → ~1).
46% @ PF 2.72 is already a strong system; the win-rate number is the cost of the
ride-to-day-end tail that generates the profit.
