from . import ma_crossover, rsi_mean_reversion

# method_714 is added to this registry in Task 8.
STRATEGY_REGISTRY = {
    "ma_crossover": {
        "engine": "vectorbt",
        "module": ma_crossover,
        "default_params": ma_crossover.DEFAULT_PARAMS,
    },
    "rsi_mean_reversion": {
        "engine": "vectorbt",
        "module": rsi_mean_reversion,
        "default_params": rsi_mean_reversion.DEFAULT_PARAMS,
    },
}
