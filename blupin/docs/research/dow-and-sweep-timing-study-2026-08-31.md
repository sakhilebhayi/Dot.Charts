# Day-of-Week + Sweep-Timing Study — 2026-08-31

Verdict-only study (no code changes). Sim: 1h resolution, SL 1.1 ATR + ride, 2y / halves /
Jan–Aug 2026 window; robust = direction holds in both halves. Real 30m tester runs ~8pts
higher than sim absolutes — read effects as relative.

## Day-of-week (594 trades, 2y)

| Day | n | win% 2y | pnl (ATR) | 2026 win% / pnl | Verdict |
|---|---|---|---|---|---|
| Mon | 119 | 31.9 | −10.0 | 48.4% / −1.8 | NEVER PROFITABLE (robust); decaying — 2026 winners (0.69 ATR avg) smaller than losers |
| Tue | 116 | 30.2 | +35.1 | 48.4% / +28.0 | PROFITABLE, robust — the consistency day (2026 PF 3.03); SELL side carries it |
| Wed | 123 | 25.2 | +37.2 | 32.4% / +34.1 | PROFITABLE, robust — the payoff day: lowest win%, biggest winners (avgW 4.82 in 2026) |
| Thu | 119 | 36.1 | +39.2 | 41.2% / +17.0 | PROFITABLE, robust — the accuracy day (best win% everywhere); only day the fallback works (64% win, n=14 indic.) |
| Fri | 117 | 20.5 | −11.7 | 21.2% / +1.9 | LOSING, robust — worst win% in every segment; 2026 profit is tail-luck |

- **Friday SELLs are the toxic half**: negative in all three segments (h1 −9.7 / h2 −5.8 /
  2026 −5.2 at 14% win); Friday BUYs flat-to-positive (−0.1 / +3.8 / +7.1). Cleanest filter found.
- **Monday mechanism**: its "prior-day" range is Friday's late session, 3+ days stale in
  119/119 trades; sweeps fire 98% of the time but carry no information; Monday moves are
  truncated (avg MFE 1.05 vs 1.75 ATR Tue–Fri). 43% of Monday losers wrong from the start.
  A Monday fix needs a fresher range, not a filter tweak.

## Sweep-window timing (full R×T grid, 20:00 → 06:00)

- **03:00 is the right decision hour.** Every T≥4 cell is pnl-negative over 2y in every
  range variant; day-call accuracy collapses to 44–47%. By 04:00 sweep share hits 96–100% —
  when every night sweeps, the sweep says nothing.
- **Post-03:00 penetrations are ANTI-fade** — the only statistically strong effect found
  (t up to −2.9, negative both halves + 2026): a 03:00–05:00 push through the range is a
  breakout in progress (stale-open bars: −0.43 ATR faded, t=−4.9). Validates the cancel
  watch; never a fresh fade signal.
- **R1T3 — extend range build to 01:00, hunt sweeps 01:00–03:00, decide 03:00 — is the one
  robust upgrade**: beats live in both halves and 2026 (window 38.0%/+103.8 vs 36.6%/+90.4
  ATR) at zero ride cost. Mechanism narrow (20 flipped days) — magnitude indicative.
- **02:00 decision has the best day-CALL accuracy** (R22T2: 53.4% in 2026, robust on accuracy)
  but does not convert to robust money — rejected as a KPI change.
- **First vs latest sweep**: no robust winner; halves flip; 2026 favors the live latest-wins.
- **Deep truth**: no hour from 20:00–02:00 has positive fade information (all 48–50% match,
  |t|<0.6, signs flip). Raw sweep-direction edge ≈ zero; the system's +0.15 ATR/trade comes
  from the asymmetric exits (SL truncation + cancel), not the sweep's predictive power.
