import { getToken, clearToken, isLoggedIn } from './auth.js';
import { renderBacktestResult } from './results-renderer.js';

const API_BASE = 'http://localhost:8000/api';

const runButton = document.getElementById('runButton');
const errorEl = document.getElementById('error');
const resultsEl = document.getElementById('results');
const assetClassSelect = document.getElementById('assetClass');
const symbolInput = document.getElementById('symbol');
const symbolCommoditySelect = document.getElementById('symbolCommodity');

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
  symbolInput.style.display = isCommodity ? 'none' : '';
  symbolCommoditySelect.style.display = isCommodity ? '' : 'none';
});

function currentSymbol() {
  return assetClassSelect.value === 'commodity'
    ? symbolCommoditySelect.value
    : symbolInput.value.trim();
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
