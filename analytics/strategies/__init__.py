from . import ma_crossover, rsi_mean_reversion, breakout, bollinger_mean_reversion, momentum, pairs_trading, ml_signal, custom
from .method_714.strategy import Method714Strategy

STRATEGY_REGISTRY = {
    "ma_crossover": {
        "engine": "vectorbt",
        "module": ma_crossover,
        "default_params": ma_crossover.DEFAULT_PARAMS,
        "interval": "1d",
    },
    "rsi_mean_reversion": {
        "engine": "vectorbt",
        "module": rsi_mean_reversion,
        "default_params": rsi_mean_reversion.DEFAULT_PARAMS,
        "interval": "1d",
    },
    "breakout": {
        "engine": "vectorbt",
        "module": breakout,
        "default_params": breakout.DEFAULT_PARAMS,
        "interval": "1d",
    },
    "bollinger_mean_reversion": {
        "engine": "vectorbt",
        "module": bollinger_mean_reversion,
        "default_params": bollinger_mean_reversion.DEFAULT_PARAMS,
        "interval": "1d",
    },
    "momentum": {
        "engine": "vectorbt",
        "module": momentum,
        "default_params": momentum.DEFAULT_PARAMS,
        "interval": "1d",
    },
    "pairs_trading": {
        "engine": "vectorbt",
        "module": pairs_trading,
        "default_params": pairs_trading.DEFAULT_PARAMS,
        "interval": "1d",
        # Handled by a separate two-symbol dispatch path in main.py --
        # every other vectorbt strategy consumes a single df.
        "requires_symbol_b": True,
    },
    "ml_signal": {
        "engine": "vectorbt",
        "module": ml_signal,
        "default_params": ml_signal.DEFAULT_PARAMS,
        "interval": "1d",
    },
    "custom": {
        "engine": "vectorbt",
        "module": custom,
        "default_params": custom.DEFAULT_PARAMS,
        "interval": "1d",
    },
    "method_714": {
        "engine": "backtrader",
        "strategy_cls": Method714Strategy,
        "default_params": {},
        # method_714's session logic needs intraday bars — daily bars are
        # always midnight and never fall inside a session window.
        "interval": "1h",
    },
}
