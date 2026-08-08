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
    document.getElementById('strategy').value = prefill.strategy;
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

  const payload = {
    symbol: currentSymbol(),
    asset_class: document.getElementById('assetClass').value,
    strategy: document.getElementById('strategy').value,
    start_date: document.getElementById('startDate').value,
    end_date: document.getElementById('endDate').value,
    params: {},
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
