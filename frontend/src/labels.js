// Display names for the server's strategy enum, as the Backtest page's own <select> words them
// (the same map the backend's DisclosureFormatter and SearchController use). One copy for the dashboard and the header search.
export const STRATEGY_NAMES = {
  ma_crossover: 'MA Crossover',
  rsi_mean_reversion: 'RSI Mean-Reversion',
  breakout: 'Breakout (Donchian)',
  bollinger_mean_reversion: 'Bollinger Mean-Reversion',
  momentum: 'Momentum',
  pairs_trading: 'Pairs Trading (Stat-Arb)',
  ml_signal: 'ML Signal (Explainable)',
  method_714: '714 Method',
  custom: 'Custom Strategy',
};

// The pages of the platform, one list: the header nav, the search's "Go to" chips and its page matches all read it.
export const PAGES = [
  { key: 'home', label: 'Home', href: '/home.html', about: 'Your backtests, strategies and notes' },
  { key: 'chart', label: 'Chart read', href: '/', about: 'Trend and levels read off a chart screenshot' },
  { key: 'backtest', label: 'Backtest', href: '/backtest.html', about: 'Run a strategy on market history', group: 'Tools' },
  { key: 'strategy', label: 'Strategy Builder', href: '/strategy-builder.html', about: 'Entry and exit rules from indicators', group: 'Tools' },
  { key: 'options', label: 'Options Vol', href: '/options.html', about: 'Realized-vol rank and put-call skew', group: 'Tools' },
  { key: 'history', label: 'History', href: '/history.html', about: 'Every run on this account', group: 'Records' },
  { key: 'journal', label: 'Journal', href: '/journal.html', about: 'Notes tied to runs and strategies', group: 'Records' },
];

// The header's primary nav as the shared renderer wants it: the ungrouped pages first, then one dropdown per group, in the order the
// groups first appear in PAGES. `current` is a page key (the page's data-page); the matching item carries aria-current.
export function navModel(current) {
  const item = (p) => ({ label: p.label, href: p.href, current: p.key === current });
  const top = PAGES.filter((p) => !p.group).map(item);
  const groups = [...new Set(PAGES.filter((p) => p.group).map((p) => p.group))].map((name) => ({
    label: name,
    items: PAGES.filter((p) => p.group === name).map(item),
  }));
  return [...top, ...groups];
}
