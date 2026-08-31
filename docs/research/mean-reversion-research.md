# High win-rate method research (2026-08-29)

Question: which trading method has the highest documented win rate, and does
it survive independent validation?

## Literature
- Trend-filtered short-term mean reversion (Connors RSI-2, Double 7s,
  published 2008) is the consistently documented high-win-rate family:
  75-79% winners on equity indices across multiple independent backtests.
- Trend following: 30-45% winners, but higher profit per unit risk (Turtles).
- Win rate ≠ expectancy: high-win methods win small; the trend filter and
  exit discipline carry the profit factor.

## Independent validation (this session, no costs)
| Test | n | Win | PF | Net |
|---|---|---|---|---|
| SPY 15y RSI-2 pullback | 120 | 75.8% | 2.62 | +64% |
| SPY 15y Double 7s | 154 | 77.3% | 2.37 | +100% |
| GOLD 15y RSI-2 <10, exit >65 | 71 | 64.8% | 1.25 | +13% |
| GOLD 15y RSI-2 <5, exit >65 | 38 | 65.8% | 1.50 | +12% |
| GOLD 15y Double 7s | 101 | **68.3%** | **1.65** | +51% |
| GOLD 15y short mirror | 55 | 49.1% | 0.57 | −27% |
| GOLD 1h RSI-2 (2y) | 327 | 65.7% | 1.09 | +6% |
| GOLD 1h Double 7s (2y) | 417 | 66.7% | 0.98 | −2% |

Findings: canonical numbers replicate on SPY; gold's best expression is
Double 7s at 68.3%/PF 1.65; the short side loses in gold's secular uptrend;
intraday keeps the win rate but has no edge (PF ≈ 1.0) — daily-chart method.

Implemented: pine/BluPin_MeanReversion.pine (strategy, Double 7s default,
RSI-2 mode, long-only default, optional catastrophic stop, intraday warning).
