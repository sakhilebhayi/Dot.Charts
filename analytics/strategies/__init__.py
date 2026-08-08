from . import ma_crossover, rsi_mean_reversion
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
    "method_714": {
        "engine": "backtrader",
        "strategy_cls": Method714Strategy,
        "default_params": {},
        # method_714's session logic needs intraday bars — daily bars are
        # always midnight and never fall inside a session window.
        "interval": "1h",
    },
}
