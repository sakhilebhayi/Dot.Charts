import pandas_ta as ta
import backtrader as bt

from .sessions import compute_sessions, DEFAULT_SESSIONS, DEFAULT_TZ
from .retest import generate_signals


class Method714Strategy(bt.Strategy):
    params = dict(
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
    )

    def __init__(self):
        # ATR always exists — it drives SL/TP/breakeven/trailing regardless
        # of use_atr_filter (which only gates the *session-range* filter).
        self.atr = bt.indicators.ATR(period=self.p.atr_length)

        # EMA(200)/volume SMA are only built when their filter is enabled —
        # building them unconditionally would force every backtest to need
        # at least `ema_slow` bars of data even with the filter turned off.
        self.ema_fast = bt.indicators.EMA(period=self.p.ema_fast) if self.p.use_ema_filter else None
        self.ema_slow = bt.indicators.EMA(period=self.p.ema_slow) if self.p.use_ema_filter else None
        self.volume_sma = (
            bt.indicators.SMA(self.data.volume, period=self.p.volume_sma_length)
            if self.p.use_volume_filter
            else None
        )

        df = self.data.p.dataname  # the pandas.DataFrame passed into bt.feeds.PandasData
        sessions_df = compute_sessions(df, self.p.sessions or DEFAULT_SESSIONS, self.p.tz)
        atr_series = ta.atr(df["high"], df["low"], df["close"], length=self.p.atr_length)
        retest_params = {
            "mode": self.p.mode,
            "retest_max_bars": self.p.retest_max_bars,
            "retest_reject_atr": self.p.retest_reject_atr,
            "retest_invalidate_atr": self.p.retest_invalidate_atr,
        }
        self._signals = generate_signals(sessions_df, atr_series, retest_params)
        self._session_starts = sessions_df["session_start"]

        self.entry_price = None
        self.entry_atr = None
        self.stop_price = None
        self.take_profit_price = None
        self._last_exit_price = None
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
        if not self._trend_ok(signal):
            return
        if not self._atr_ok() or not self._volume_ok():
            return

        atr_value = self.atr[0]
        price = self.data.close[0]
        self.entry_price = price
        self.entry_atr = atr_value
        if signal == 1:
            self.stop_price = price - atr_value * self.p.sl_atr_mult
            self.take_profit_price = price + atr_value * self.p.tp_atr_mult
            self.buy()
        else:
            self.stop_price = price + atr_value * self.p.sl_atr_mult
            self.take_profit_price = price - atr_value * self.p.tp_atr_mult
            self.sell()

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
            self.trade_log.append(
                {
                    "entry_time": bt.num2date(trade.dtopen).isoformat(),
                    "exit_time": bt.num2date(trade.dtclose).isoformat(),
                    "direction": "long" if trade.long else "short",
                    "entry_price": self.entry_price,
                    "exit_price": self._last_exit_price,
                    "pnl": trade.pnl,
                }
            )
