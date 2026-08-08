import pandas as pd
import pandas_ta as ta
import backtrader as bt

from .sessions import compute_sessions, DEFAULT_SESSIONS, DEFAULT_TZ
from .retest import generate_signals
from .smc import compute_swing_pivots, compute_structure, compute_liquidity_sweeps, compute_prev_day_sweeps
from .mtf import compute_htf_trend
from .confidence import compute_confidence, extension_ok, pa_quality_ok, clv_ok


def _compute_session_reference(sessions_df: pd.DataFrame) -> pd.DataFrame:
    """
    Extension band, PA quality, and CLV all reference the SESSION that
    produced the current signal's bias — its own open/close and its
    accumulated high/low across every bar inside it (matching the Pine
    source's sessOpen/sessClose/sessHigh/sessLow, fixed at session-close
    time) — not the bar where a retest_continuation signal eventually
    fires, which can be many bars later with an irrelevant tiny
    bar-to-bar move. Holds the most recently completed session's
    reference OHLC for every bar until the next session starts.
    """
    n = len(sessions_df)
    ref_open = [float("nan")] * n
    ref_high = [float("nan")] * n
    ref_low = [float("nan")] * n
    ref_close = [float("nan")] * n

    session_open_col = sessions_df["session_open"].to_numpy()
    high_col = sessions_df["high"].to_numpy()
    low_col = sessions_df["low"].to_numpy()
    close_col = sessions_df["close"].to_numpy()
    session_start = sessions_df["session_start"].to_numpy()
    session_end = sessions_df["session_end"].to_numpy()
    in_session = sessions_df["session_name"].notna().to_numpy()

    current = None
    running_high = float("nan")
    running_low = float("nan")

    for i in range(n):
        if session_start[i]:
            running_high = high_col[i]
            running_low = low_col[i]
        elif in_session[i]:
            running_high = high_col[i] if pd.isna(running_high) else max(running_high, high_col[i])
            running_low = low_col[i] if pd.isna(running_low) else min(running_low, low_col[i])

        if session_end[i] and i > 0:
            current = {
                "open": session_open_col[i - 1],
                "high": running_high,
                "low": running_low,
                "close": close_col[i - 1],
            }

        if current is not None:
            ref_open[i] = current["open"]
            ref_high[i] = current["high"]
            ref_low[i] = current["low"]
            ref_close[i] = current["close"]

    out = sessions_df.copy()
    out["ref_open"] = ref_open
    out["ref_high"] = ref_high
    out["ref_low"] = ref_low
    out["ref_close"] = ref_close
    return out


class Method714Strategy(bt.Strategy):
    params = dict(
        # Required — used for the MTF fetch, since the strategy only ever
        # receives the base-timeframe DataFrame from backtrader, not the
        # request context that produced it.
        symbol=None,
        asset_class=None,
        start_date=None,
        end_date=None,
        sessions=None,
        tz=DEFAULT_TZ,
        mode="retest_continuation",
        retest_max_bars=16,
        retest_reject_atr=0.15,
        retest_invalidate_atr=0.75,
        ema_fast=50,
        ema_slow=200,
        use_ema_filter=True,
        atr_length=14,
        atr_min_mult=0.5,
        use_atr_filter=True,
        use_volume_filter=True,
        volume_sma_length=20,
        volume_mult=1.0,
        sl_atr_mult=1.5,
        tp_atr_mult=3.0,
        use_breakeven=True,
        breakeven_trigger_atr=1.0,
        use_trailing_stop=False,
        trailing_atr_mult=2.0,
        flatten_at_session_start=True,
        position_fraction=0.10,
        # SMC / MTF / confidence — new this slice
        smc_pivot_len=5,
        smc_sweep_lookback=10,
        fvg_min_atr_mult=0.25,
        use_mtf_filter=True,
        mtf_interval="4h",
        mtf_fast=50,
        mtf_slow=200,
        extension_min_atr_mult=0.10,
        extension_max_atr_mult=3.00,
        pa_body_min=0.50,
        pa_wick_min=0.33,
        clv_min_pct=25.0,
        min_confidence=45,
        filter_mode="confidence_only",  # "confidence_only" | "hard_filters"
    )

    def __init__(self):
        self.atr = bt.indicators.ATR(period=self.p.atr_length)
        self.ema_fast = bt.indicators.EMA(period=self.p.ema_fast) if self.p.use_ema_filter else None
        self.ema_slow = bt.indicators.EMA(period=self.p.ema_slow) if self.p.use_ema_filter else None
        self.volume_sma = (
            bt.indicators.SMA(self.data.volume, period=self.p.volume_sma_length)
            if self.p.use_volume_filter
            else None
        )

        df = self.data.p.dataname
        sessions_df = compute_sessions(df, self.p.sessions or DEFAULT_SESSIONS, self.p.tz)
        atr_series = ta.atr(df["high"], df["low"], df["close"], length=self.p.atr_length)
        retest_params = {
            "mode": self.p.mode,
            "retest_max_bars": self.p.retest_max_bars,
            "retest_reject_atr": self.p.retest_reject_atr,
            "retest_invalidate_atr": self.p.retest_invalidate_atr,
        }
        # backtrader's own clock (self.data.num2date()) always returns
        # tz-naive datetimes, even when the feed's source DataFrame has a
        # tz-aware index — so a tz-aware self._signals/_session_starts index
        # would never match a lookup by current_time in next() (every bar
        # would silently fall through to "no signal"). Session math needs
        # the tz-aware index (Africa/Johannesburg conversion); it's dropped
        # here, after that math is done, to match backtrader's naive clock.
        self._signals = generate_signals(sessions_df, atr_series, retest_params).tz_localize(None)
        self._session_starts = sessions_df["session_start"].tz_localize(None)

        ref_df = _compute_session_reference(sessions_df)
        self._ref_open = ref_df["ref_open"].tz_localize(None)
        self._ref_high = ref_df["ref_high"].tz_localize(None)
        self._ref_low = ref_df["ref_low"].tz_localize(None)
        self._ref_close = ref_df["ref_close"].tz_localize(None)

        pivots_df = compute_swing_pivots(df, piv_len=self.p.smc_pivot_len)
        structure_df = compute_structure(pivots_df)
        sweeps_df = compute_liquidity_sweeps(structure_df, lookback_bars=self.p.smc_sweep_lookback)
        pd_sweeps_df = compute_prev_day_sweeps(sweeps_df, tz=self.p.tz, lookback_bars=self.p.smc_sweep_lookback)

        self._structure_dir = structure_df["structure_dir"].tz_localize(None)
        self._recent_bull_sweep = sweeps_df["recent_bull_sweep"].tz_localize(None)
        self._recent_bear_sweep = sweeps_df["recent_bear_sweep"].tz_localize(None)
        self._recent_pd_bull_sweep = pd_sweeps_df["recent_pd_bull_sweep"].tz_localize(None)
        self._recent_pd_bear_sweep = pd_sweeps_df["recent_pd_bear_sweep"].tz_localize(None)

        if self.p.use_mtf_filter:
            self._htf_trend = compute_htf_trend(
                self.p.symbol,
                self.p.asset_class,
                self.p.start_date,
                self.p.end_date,
                df.index,
                htf_interval=self.p.mtf_interval,
                fast=self.p.mtf_fast,
                slow=self.p.mtf_slow,
            ).tz_localize(None)
        else:
            self._htf_trend = pd.Series(0, index=df.index).tz_localize(None)

        self.entry_price = None
        self.entry_atr = None
        self.stop_price = None
        self.take_profit_price = None
        self._last_exit_price = None
        self._pending_confidence = None
        self.trade_log = []
        self.equity_curve = []

    def _trend_ok(self, direction: int) -> bool:
        if not self.p.use_ema_filter:
            return True
        return (direction == 1 and self.ema_fast[0] > self.ema_slow[0]) or (
            direction == -1 and self.ema_fast[0] < self.ema_slow[0]
        )

    def _atr_ok(self) -> bool:
        if not self.p.use_atr_filter:
            return True
        session_range = self.data.high[0] - self.data.low[0]
        return session_range >= self.atr[0] * self.p.atr_min_mult

    def _volume_ok(self) -> bool:
        if not self.p.use_volume_filter:
            return True
        return self.data.volume[0] > self.volume_sma[0] * self.p.volume_mult

    def _mtf_ok(self, direction: int, current_time) -> bool:
        if not self.p.use_mtf_filter:
            return True
        htf_trend = int(self._htf_trend.get(current_time, 0))
        return (direction == 1 and htf_trend == 1) or (direction == -1 and htf_trend == -1)

    def _position_size(self, price: float) -> float:
        notional = self.broker.getvalue() * self.p.position_fraction
        return notional / price if price > 0 else 0

    def _reference_bar(self, current_time) -> tuple[float, float, float, float]:
        """
        The most recently completed session's own open/high/low/close —
        see _compute_session_reference for why this, not the live bar,
        is what extension/PA-quality/CLV must evaluate.
        """
        return (
            float(self._ref_open.get(current_time, float("nan"))),
            float(self._ref_high.get(current_time, float("nan"))),
            float(self._ref_low.get(current_time, float("nan"))),
            float(self._ref_close.get(current_time, float("nan"))),
        )

    def _confidence_for_signal(self, signal: int, current_time) -> dict:
        structure_dir = int(self._structure_dir.get(current_time, 0))
        recent_bull_sweep = bool(self._recent_bull_sweep.get(current_time, False))
        recent_bear_sweep = bool(self._recent_bear_sweep.get(current_time, False))
        recent_pd_bull = bool(self._recent_pd_bull_sweep.get(current_time, False))
        recent_pd_bear = bool(self._recent_pd_bear_sweep.get(current_time, False))

        structure_aligned = (signal == 1 and structure_dir == 1) or (signal == -1 and structure_dir == -1)
        sweep_aligned = (signal == 1 and recent_bull_sweep) or (signal == -1 and recent_bear_sweep)
        prev_day_sweep_aligned = (signal == 1 and recent_pd_bull) or (signal == -1 and recent_pd_bear)

        o, h, l, c = self._reference_bar(current_time)
        pa_mode = "momentum" if self.p.mode == "momentum" else "contrarian"

        return compute_confidence(
            direction=signal,
            trend_ok=self._trend_ok(signal),
            mtf_ok=self._mtf_ok(signal, current_time),
            atr_ok=self._atr_ok(),
            volume_ok=self._volume_ok(),
            structure_aligned=structure_aligned,
            sweep_aligned=sweep_aligned,
            pa_quality_ok=pa_quality_ok(
                signal, o, h, l, c, mode=pa_mode, body_min=self.p.pa_body_min, wick_min=self.p.pa_wick_min
            ),
            clv_ok=clv_ok(signal, h, l, c, min_pct=self.p.clv_min_pct),
            prev_day_sweep_aligned=prev_day_sweep_aligned,
        )

    def next(self):
        current_time = self.data.num2date(self.data.datetime[0])
        self.equity_curve.append({"time": current_time.isoformat(), "equity": self.broker.getvalue()})

        if self.p.flatten_at_session_start and self.position:
            is_session_start = bool(self._session_starts.get(current_time, False))
            if is_session_start:
                self._last_exit_price = self.data.close[0]
                self.close()
                return

        if self.position:
            self._manage_open_position()
            return

        signal = int(self._signals.get(current_time, 0))
        if signal == 0:
            return

        atr_value = self.atr[0]

        # Extension band is a hard gate in both filter modes, matching the
        # Pine source's own "(hard gate)" labeling — it is never part of
        # the confidence score. It evaluates the session's own open/close
        # (see _reference_bar), not the live entry bar — for
        # retest_continuation mode especially, the entry bar can be many
        # bars after the session and its own tiny bar-to-bar move is not
        # what "extension" is meant to measure.
        ref_open, _, _, ref_close = self._reference_bar(current_time)
        if not extension_ok(
            ref_open, ref_close, atr_value,
            min_mult=self.p.extension_min_atr_mult, max_mult=self.p.extension_max_atr_mult,
        ):
            return

        confidence = self._confidence_for_signal(signal, current_time)
        if confidence["score"] < self.p.min_confidence:
            return

        # In "confidence_only" mode (the default), trend/ATR/volume/MTF
        # already shaped the score above and never independently veto —
        # this is a deliberate change from the strategy's earlier
        # reduced-core behavior (which hard-gated on these unconditionally)
        # to reach parity with the Pine source's own two-mode design.
        if self.p.filter_mode == "hard_filters":
            if not (
                self._trend_ok(signal)
                and self._atr_ok()
                and self._mtf_ok(signal, current_time)
                and self._volume_ok()
            ):
                return

        price = self.data.close[0]
        self.entry_price = price
        self.entry_atr = atr_value
        self._pending_confidence = confidence
        size = self._position_size(price)
        if signal == 1:
            self.stop_price = price - atr_value * self.p.sl_atr_mult
            self.take_profit_price = price + atr_value * self.p.tp_atr_mult
            self.buy(size=size)
        else:
            self.stop_price = price + atr_value * self.p.sl_atr_mult
            self.take_profit_price = price - atr_value * self.p.tp_atr_mult
            self.sell(size=size)

    def _manage_open_position(self):
        price = self.data.close[0]
        is_long = self.position.size > 0

        if self.p.use_breakeven and self.entry_price is not None:
            moved_enough = (
                is_long and price >= self.entry_price + self.entry_atr * self.p.breakeven_trigger_atr
            ) or (not is_long and price <= self.entry_price - self.entry_atr * self.p.breakeven_trigger_atr)
            if moved_enough:
                self.stop_price = (
                    max(self.stop_price, self.entry_price) if is_long else min(self.stop_price, self.entry_price)
                )

        if self.p.use_trailing_stop:
            trail = (
                price - self.atr[0] * self.p.trailing_atr_mult
                if is_long
                else price + self.atr[0] * self.p.trailing_atr_mult
            )
            self.stop_price = max(self.stop_price, trail) if is_long else min(self.stop_price, trail)

        hit_stop = (is_long and price <= self.stop_price) or (not is_long and price >= self.stop_price)
        hit_tp = (is_long and price >= self.take_profit_price) or (not is_long and price <= self.take_profit_price)
        if hit_stop or hit_tp:
            self._last_exit_price = price
            self.close()

    def notify_trade(self, trade):
        # trade.size is reset to 0 by the time isclosed fires, so exit price
        # cannot be reconstructed from trade.pnl / trade.size (always
        # divides by zero / collapses to entry price) — entry/exit prices
        # are tracked directly by this strategy instead (self.entry_price,
        # self._last_exit_price), set at the moment each order is placed.
        if trade.isclosed:
            confidence = self._pending_confidence or {"score": None, "breakdown": None}
            self.trade_log.append(
                {
                    "entry_time": bt.num2date(trade.dtopen).isoformat(),
                    "exit_time": bt.num2date(trade.dtclose).isoformat(),
                    "direction": "long" if trade.long else "short",
                    "entry_price": self.entry_price,
                    "exit_price": self._last_exit_price,
                    "pnl": trade.pnl,
                    "confidence_score": confidence["score"],
                    "confidence_breakdown": confidence["breakdown"],
                }
            )
            self._pending_confidence = None
