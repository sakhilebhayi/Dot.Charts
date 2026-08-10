import { getToken, clearToken, isLoggedIn } from './auth.js';
import { renderBacktestResult } from './results-renderer.js';

const API_BASE = 'http://localhost:8000/api';

const runButton = document.getElementById('runButton');
const errorEl = document.getElementById('error');
const resultsEl = document.getElementById('results');
const assetClassSelect = document.getElementById('assetClass');
const symbolInput = document.getElementById('symbol');
const symbolCommoditySelect = document.getElementById('symbolCommodity');
const symbolForexSelect = document.getElementById('symbolForex');
const strategySelect = document.getElementById('strategy');
const symbolBRow = document.getElementById('symbolBRow');
const symbolBInput = document.getElementById('symbolB');
const savedStrategyRules = {};

strategySelect.addEventListener('change', () => {
  symbolBRow.style.display = strategySelect.value === 'pairs_trading' ? '' : 'none';
});

async function loadSavedStrategyOptions() {
  if (!isLoggedIn()) return;

  try {
    let url = `${API_BASE}/strategies`;
    const strategies = [];

    while (url) {
      const response = await fetch(url, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
      });

      if (response.status === 401) {
        // Stale token: clear it and reflect the logged-out state instead of
        // silently leaving the header showing "Log out" with no explanation.
        clearToken();
        const authStateEl = document.getElementById('authState');
        if (authStateEl) {
          authStateEl.innerHTML = '<a href="/login.html" style="color:var(--accent)">Log in</a>';
        }
        errorEl.textContent = 'Your session has expired. Log in again to see your saved strategies.';
        errorEl.style.display = 'block';
        return;
      }

      if (!response.ok) return;
      const body = await response.json();
      strategies.push(...(body.data || []));
      url = body.next_page_url || null;
    }

    strategies.forEach((strategy) => {
      savedStrategyRules[strategy.id] = strategy.rules;
      const opt = document.createElement('option');
      opt.value = `custom:${strategy.id}`;
      opt.textContent = `${strategy.name} (custom)`;
      strategySelect.appendChild(opt);
    });
  } catch (err) {
    errorEl.textContent = 'Could not load your saved strategies.';
    errorEl.style.display = 'block';
  }
}

loadSavedStrategyOptions();

const authStateEl = document.getElementById('authState');
if (authStateEl) {
  if (isLoggedIn()) {
    authStateEl.innerHTML = '<a href="#" id="logoutLink" style="color:var(--accent)">Log out</a>';
    document.getElementById('logoutLink').addEventListener('click', (e) => {
      e.preventDefault();
      clearToken();
      window.location.reload();
    });
  } else {
    authStateEl.innerHTML = '<a href="/login.html" style="color:var(--accent)">Log in</a>';
  }
}

assetClassSelect.addEventListener('change', () => {
  const isCommodity = assetClassSelect.value === 'commodity';
  const isForex = assetClassSelect.value === 'forex';
  symbolInput.style.display = (isCommodity || isForex) ? 'none' : '';
  symbolCommoditySelect.style.display = isCommodity ? '' : 'none';
  symbolForexSelect.style.display = isForex ? '' : 'none';
});

function currentSymbol() {
  if (assetClassSelect.value === 'commodity') return symbolCommoditySelect.value;
  if (assetClassSelect.value === 'forex') return symbolForexSelect.value;
  return symbolInput.value.trim();
}

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
    // BacktestRun casts start_date/end_date as Eloquent 'date' fields, which
    // serialize to full ISO datetimes ("2023-01-01T00:00:00.000000Z") in
    // JSON — but <input type="date"> requires exactly "YYYY-MM-DD" and
    // silently rejects anything else, leaving the field blank. Truncate to
    // the date portion regardless of which shape arrives.
    document.getElementById('startDate').value = prefill.start_date.slice(0, 10);
    document.getElementById('endDate').value = prefill.end_date.slice(0, 10);
  } catch {
    // Malformed sessionStorage payload — ignore and leave the form at its defaults
    // rather than surfacing an error for something the user didn't directly do.
  }
}

runButton.addEventListener('click', async () => {
  errorEl.style.display = 'none';
  resultsEl.style.display = 'none';
  runButton.disabled = true;
  runButton.textContent = 'Running…';

  const selectedStrategy = strategySelect.value;
  const isCustom = selectedStrategy.startsWith('custom:');
  const customId = isCustom ? selectedStrategy.slice('custom:'.length) : null;

  const payload = {
    symbol: currentSymbol(),
    asset_class: document.getElementById('assetClass').value,
    strategy: isCustom ? 'custom' : selectedStrategy,
    start_date: document.getElementById('startDate').value,
    end_date: document.getElementById('endDate').value,
    params: isCustom
      ? savedStrategyRules[customId]
      : selectedStrategy === 'pairs_trading'
        ? { symbol_b: symbolBInput.value.trim() }
        : {},
  };

  try {
    const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
    const token = getToken();
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    const response = await fetch(`${API_BASE}/backtests`, {
      method: 'POST',
      headers,
      body: JSON.stringify(payload),
    });
    const body = await response.json();

    if (!response.ok || body.success === false) {
      throw new Error(body.error || 'Backtest failed');
    }

    renderBacktestResult(body.result);
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  } finally {
    runButton.disabled = false;
    runButton.textContent = 'Run backtest';
  }
});
