# Gold method loop — 1,218 configurations (2026-08-29)

Loop: 7 families (RSI-2, Asia sweep-reclaim, any-hour sweep-reclaim, opening-
range breakout, EMA pullback, Bollinger MR, time-of-day) × parameter grids ×
25 TP/SL combos (0.5-3.0 × ATR14) on 1h and 4h streams (~2y, JHB days).
Both-TP-and-SL-in-one-bar counted as LOSS (conservative). No costs.

WINNER (all of: win>=80%, profitable, both halves >=70%):
- BUY 16:00 JHB (4h block), TP 0.5×ATR, SL 3.0×ATR: n=444, 84.9%, +25.3% (87/82)
- BUY 16:00 JHB, TP 0.5/SL 2.0: n=478, 82.2%, +25.5% (84/80)
- SELL 14:00 JHB (1h), TP 0.5/SL 3.0: n=582, 86.1%, +19.7% (88/85)
- BUY 22:00 JHB (1h), TP 0.5/SL 3.0: n=574, 86.2%, +18.5% (86/86)
Same entry, rider variant: BUY 16:00 4h TP 3.0/SL 2.0: 57.4%, +71.1% (58/57).

30m out-of-sample (last 60 days): BUY 16:00 TP.5/SL3 91.8% +3.3%;
SELL 14:00 87.8% +1.6%; the TP3/SL2 rider did NOT validate (37.5%, -8.3%).

Interpretation: gold intraday seasonality — weakness into the London
PM-fix/early-US window, strength through the NY morning session. The
time-of-day family dominated BOTH the win-rate and P/L tables; every other
family's best cells were weaker. The 80%+ win rate is the seasonal timing
edge converted by asymmetric TP/SL geometry (win 0.5×ATR often, lose up to
3×ATR rarely); expectancy stays positive because the timing bias is real.

Caveats: no spread/costs (0.5×ATR TP on 1h ≈ $1.5-2 — costs matter; the 4h
version's larger ATR absorbs spread best); one loss erases ~6 wins, so every
signal must be taken; asymmetric R:R means high win rate ≠ high profit —
the rider variant makes 3× the money at 57% win but failed recent 30m.
