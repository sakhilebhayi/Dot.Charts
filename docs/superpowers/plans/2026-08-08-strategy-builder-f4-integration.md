# Strategy Builder F4: Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire saved custom strategies into `backtest.html`'s strategy dropdown (so they're runnable the same way the 5 built-in presets already are) and `history.html`'s strategy filter, closing out the final sub-project of the strategy builder.

**Architecture:** `backtest.js` fetches the logged-in user's saved strategies and appends them as extra `<option>`s on the existing `#strategy` dropdown, backed by a `savedStrategyRules` map resolving each option to its rule JSON when the existing `/api/backtests` payload is built. The existing re-run prefill logic gains a branch for `strategy === 'custom'`. `history.html`'s filter gains one static option.

**Tech Stack:** Vanilla JS — no new dependencies, matching every existing frontend page.

## Global Constraints

- No new backend endpoints — reuses `GET /api/strategies` (F2) and the existing `/api/backtests` contract (F1) unchanged (per spec's Dropdown Integration section).
- Custom-strategy dropdown options only appear when logged in — the fetch is skipped entirely when logged out, same as F3's Load-from-saved gating (per spec's Dropdown Integration section).
- Re-run of a custom-strategy backtest must use the exact rule JSON that produced the original result, independent of the user's currently-saved strategies list (per spec's Re-run Compatibility section).
- History's filter adds one aggregate `custom` option — no per-named-strategy distinction (per spec's History Filter section).

---

### Task 1: Dropdown integration — fetch saved strategies, resolve params on Run

**Files:**
- Modify: `frontend/src/backtest.js`

**Interfaces:**
- Consumes: `GET /api/strategies` (F2, existing), `getToken()`/`isLoggedIn()` from `auth.js` (existing).
- Produces: a module-level `savedStrategyRules` object (`{id: rules}`) and a `loadSavedStrategyOptions()` function — Task 2 (re-run) adds an entry to this same map and appends an option the same way.

- [ ] **Step 1: Add the fetch and dropdown population**

In `frontend/src/backtest.js`, add near the top (after the existing `const symbolForexSelect = ...` line):

```javascript
const strategySelect = document.getElementById('strategy');
const savedStrategyRules = {};

async function loadSavedStrategyOptions() {
  if (!isLoggedIn()) return;

  const response = await fetch(`${API_BASE}/strategies`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
  });
  if (!response.ok) return;
  const body = await response.json();

  (body.data || []).forEach((strategy) => {
    savedStrategyRules[strategy.id] = strategy.rules;
    const opt = document.createElement('option');
    opt.value = `custom:${strategy.id}`;
    opt.textContent = `${strategy.name} (custom)`;
    strategySelect.appendChild(opt);
  });
}

loadSavedStrategyOptions();
```

- [ ] **Step 2: Resolve strategy/params from the selected option when running**

In `frontend/src/backtest.js`'s `runButton` click handler, change:

```javascript
  const payload = {
    symbol: currentSymbol(),
    asset_class: document.getElementById('assetClass').value,
    strategy: document.getElementById('strategy').value,
    start_date: document.getElementById('startDate').value,
    end_date: document.getElementById('endDate').value,
    params: {},
  };
```

to:

```javascript
  const selectedStrategy = strategySelect.value;
  const isCustom = selectedStrategy.startsWith('custom:');
  const customId = isCustom ? selectedStrategy.slice('custom:'.length) : null;

  const payload = {
    symbol: currentSymbol(),
    asset_class: document.getElementById('assetClass').value,
    strategy: isCustom ? 'custom' : selectedStrategy,
    start_date: document.getElementById('startDate').value,
    end_date: document.getElementById('endDate').value,
    params: isCustom ? savedStrategyRules[customId] : {},
  };
```

- [ ] **Step 3: Manual verification**

1. Start the analytics service (`cd analytics && .venv/bin/uvicorn main:app --port 8001`), backend (`chartsense-backend`, port 8000), frontend (`chartsense`, port 3000).
2. Log in via `login.html` (or register a new account).
3. Open `strategy-builder.html`, build and save a strategy named e.g. "F4 Test Strategy" — leave the default EMA(20)-crosses-above-EMA(50) entry condition as-is, but change the Exit panel's comparator to `crosses below` (matching F3's earlier verified asymmetric-rule test case exactly, so the expected figures below are reproducible).
4. Open `backtest.html`. Confirm the strategy dropdown now shows "F4 Test Strategy (custom)" in addition to the 5 built-ins.
5. Select it, fill in a real symbol/date range (e.g. `AAPL`, equity, `2022-01-01` to `2024-01-01`), click "Run backtest". Confirm it renders real results (should match F3's Test Run figures for the same rules/symbol/dates: 12.71% return, 5 trades, if the conditions match exactly), and confirm `body.strategy === 'custom'` reached the backend correctly by checking the rendered `resultTitle` shows "custom" as the strategy name.
6. Log out, reload `backtest.html`. Confirm only the 5 built-in options remain in the dropdown (no custom entries, no console errors from the skipped fetch).
7. Stop all three dev servers.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/backtest.js
git commit -m "feat(strategy-builder): wire saved custom strategies into backtest.html's dropdown"
```

---

### Task 2: Re-run compatibility for custom strategies

**Files:**
- Modify: `frontend/src/backtest.js`

**Interfaces:**
- Consumes: `savedStrategyRules`, `strategySelect` from Task 1.
- Produces: nothing new for later tasks — this task only extends the existing re-run prefill block.

- [ ] **Step 1: Extend the re-run prefill logic**

In `frontend/src/backtest.js`, find the existing re-run block:

```javascript
const rerunData = sessionStorage.getItem('chartsense_rerun');
if (rerunData) {
  sessionStorage.removeItem('chartsense_rerun');
  try {
    const prefill = JSON.parse(rerunData);
    document.getElementById('assetClass').value = prefill.asset_class;
    assetClassSelect.dispatchEvent(new Event('change')); // toggles symbol field visibility
    if (prefill.asset_class === 'commodity') {
      symbolCommoditySelect.value = prefill.symbol;
    } else if (prefill.asset_class === 'forex') {
      symbolForexSelect.value = prefill.symbol;
    } else {
      symbolInput.value = prefill.symbol;
    }
    document.getElementById('strategy').value = prefill.strategy;
    document.getElementById('startDate').value = prefill.start_date.slice(0, 10);
    document.getElementById('endDate').value = prefill.end_date.slice(0, 10);
  } catch {
    // Malformed sessionStorage payload — ignore and leave the form at its defaults
    // rather than surfacing an error for something the user didn't directly do.
  }
}
```

Change the `document.getElementById('strategy').value = prefill.strategy;` line to:

```javascript
    if (prefill.strategy === 'custom') {
      // The exact rules that produced the original result -- not
      // necessarily any currently-saved strategy (it may have been
      // edited-and-saved-as-new, or deleted, since this run happened).
      // A synthetic option carrying those exact rules keeps re-run
      // working the same way it already does for every built-in
      // strategy: reproducing precisely what was actually run.
      savedStrategyRules['rerun'] = prefill.params;
      const rerunOpt = document.createElement('option');
      rerunOpt.value = 'custom:rerun';
      rerunOpt.textContent = 'Custom (from history)';
      strategySelect.appendChild(rerunOpt);
      strategySelect.value = 'custom:rerun';
    } else {
      strategySelect.value = prefill.strategy;
    }
```

so the full block reads:

```javascript
const rerunData = sessionStorage.getItem('chartsense_rerun');
if (rerunData) {
  sessionStorage.removeItem('chartsense_rerun');
  try {
    const prefill = JSON.parse(rerunData);
    document.getElementById('assetClass').value = prefill.asset_class;
    assetClassSelect.dispatchEvent(new Event('change')); // toggles symbol field visibility
    if (prefill.asset_class === 'commodity') {
      symbolCommoditySelect.value = prefill.symbol;
    } else if (prefill.asset_class === 'forex') {
      symbolForexSelect.value = prefill.symbol;
    } else {
      symbolInput.value = prefill.symbol;
    }
    if (prefill.strategy === 'custom') {
      savedStrategyRules['rerun'] = prefill.params;
      const rerunOpt = document.createElement('option');
      rerunOpt.value = 'custom:rerun';
      rerunOpt.textContent = 'Custom (from history)';
      strategySelect.appendChild(rerunOpt);
      strategySelect.value = 'custom:rerun';
    } else {
      strategySelect.value = prefill.strategy;
    }
    document.getElementById('startDate').value = prefill.start_date.slice(0, 10);
    document.getElementById('endDate').value = prefill.end_date.slice(0, 10);
  } catch {
    // Malformed sessionStorage payload — ignore and leave the form at its defaults
    // rather than surfacing an error for something the user didn't directly do.
  }
}
```

Note: this block runs at module-load time, before `loadSavedStrategyOptions()`'s async fetch necessarily resolves — that's fine, since the synthetic `custom:rerun` option is appended directly by this block itself, not dependent on the fetch completing.

- [ ] **Step 2: Manual verification**

1. Start all three dev servers.
2. While logged in, run a custom-strategy backtest directly (via `backtest.html`'s dropdown from Task 1, or via `strategy-builder.html`'s Test Run + Save).
3. Open `history.html`, find that run, click "Re-run".
4. Confirm `backtest.html` opens with the strategy dropdown showing "Custom (from history)" selected, and the symbol/asset class/dates correctly prefilled.
5. Click "Run backtest" without changing anything. Confirm it reproduces the same result (same metrics) as the original run.
6. Stop all three dev servers.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/backtest.js
git commit -m "feat(strategy-builder): make Re-run work for custom-strategy backtest runs"
```

---

### Task 3: History filter option + full end-to-end verification

**Files:**
- Modify: `frontend/history.html`

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing new — final task in the plan (and the final task of the entire strategy builder F1-F4 project).

- [ ] **Step 1: Add the filter option**

In `frontend/history.html`, find the strategy filter `<select>`:

```html
        <select id="filterStrategy">
          <option value="">All</option>
          <option value="ma_crossover">MA Crossover</option>
          <option value="rsi_mean_reversion">RSI Mean-Reversion</option>
          <option value="breakout">Breakout (Donchian)</option>
          <option value="bollinger_mean_reversion">Bollinger Mean-Reversion</option>
          <option value="method_714">714 Method</option>
        </select>
```

and add a `custom` option:

```html
        <select id="filterStrategy">
          <option value="">All</option>
          <option value="ma_crossover">MA Crossover</option>
          <option value="rsi_mean_reversion">RSI Mean-Reversion</option>
          <option value="breakout">Breakout (Donchian)</option>
          <option value="bollinger_mean_reversion">Bollinger Mean-Reversion</option>
          <option value="method_714">714 Method</option>
          <option value="custom">Custom Strategy</option>
        </select>
```

- [ ] **Step 2: Full end-to-end manual verification**

1. Start all three dev servers.
2. On `history.html`, filter by "Custom Strategy". Confirm only custom-strategy runs (the ones created in Tasks 1-2's verification) appear in the list, and the meta line for each shows `custom` as the strategy.
3. Clear the filter, confirm all runs (built-in and custom) reappear.
4. As a final full-project check: from `strategy-builder.html`, build a brand-new strategy (different from Tasks 1-2's), save it, immediately switch to `backtest.html`, confirm it appears in the dropdown without needing a fresh login, run it, confirm real results, then check `history.html` shows it filterable by "Custom Strategy" too.
5. Stop all three dev servers.

- [ ] **Step 3: Commit**

```bash
git add frontend/history.html
git commit -m "feat(strategy-builder): add Custom Strategy option to History's strategy filter"
```
