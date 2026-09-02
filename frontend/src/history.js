import { getToken, clearToken, isLoggedIn, logout } from './auth.js';
import { renderBacktestResult } from './results-renderer.js';

import { API_BASE } from './api-base.js';
import { showFailure } from './ecosystem.js';

const authStateEl = document.getElementById('authState');
if (isLoggedIn()) {
  authStateEl.innerHTML = '<a href="#" id="logoutLink" style="color:var(--accent)">Log out</a>';
  document.getElementById('logoutLink').addEventListener('click', async (e) => {
    e.preventDefault();
    await logout();
    window.location.reload();
  });
} else {
  authStateEl.innerHTML = '<a href="/login.html" style="color:var(--accent)">Log in</a>';
}

const errorEl = document.getElementById('error');
const emptyEl = document.getElementById('empty');
const listEl = document.getElementById('runsList');
const loadMoreButton = document.getElementById('loadMore');
const resultsEl = document.getElementById('results');

const filterStrategy = document.getElementById('filterStrategy');
const filterAssetClass = document.getElementById('filterAssetClass');
const filterStatus = document.getElementById('filterStatus');

let nextPageUrl = null;

function authHeaders() {
  const token = getToken();
  return token ? { Authorization: `Bearer ${token}`, Accept: 'application/json' } : { Accept: 'application/json' };
}

async function loadRuns(url, { reset }) {
  let failStatus = null;
  errorEl.style.display = 'none';

  try {
    const response = await fetch(url, { headers: authHeaders() });
    failStatus = response.status;
    const body = await response.json();

    if (!response.ok) {
      throw new Error(body.message || 'Failed to load history');
    }

    if (reset) {
      listEl.innerHTML = '';
    }

    body.data.forEach(renderRunRow);

    nextPageUrl = body.next_page_url;
    loadMoreButton.style.display = nextPageUrl ? 'block' : 'none';
    emptyEl.style.display = reset && body.data.length === 0 ? 'block' : 'none';
  } catch (err) {
    showFailure(errorEl, err.message, failStatus);
  }
}

function buildListUrl() {
  const params = new URLSearchParams();
  if (filterStrategy.value) params.set('strategy', filterStrategy.value);
  if (filterAssetClass.value) params.set('asset_class', filterAssetClass.value);
  if (filterStatus.value) params.set('status', filterStatus.value);
  const query = params.toString();
  return `${API_BASE}/backtests${query ? `?${query}` : ''}`;
}

function renderRunRow(run) {
  const row = document.createElement('div');
  row.className = 'run-row';

  const totalReturn = run.results?.metrics?.total_return_pct;
  const returnText = totalReturn == null ? '—' : `${totalReturn.toFixed(2)}%`;

  // run.symbol is freeform user text (validated server-side only for
  // length/presence, not content) -- interpolating it into innerHTML would
  // be stored XSS, and the auth token lives in localStorage where any
  // injected script could read it. run.strategy/asset_class/status are all
  // server-enum-constrained and safe to interpolate as-is.
  row.innerHTML = `
    <div>
      <div class="symbol"><span class="symbol-text"></span><span class="symbol-b-text"></span> <span class="status ${run.status}">${run.status}</span></div>
      <div class="meta">${run.strategy} · ${run.asset_class} · ${new Date(run.created_at).toLocaleString()} · ${returnText}</div>
    </div>
    <div class="run-actions">
      <button class="secondary journal-btn">+ Journal</button>
      <button class="secondary rerun-btn">Re-run</button>
      <button class="danger delete-btn">Delete</button>
    </div>
  `;
  row.querySelector('.symbol-text').textContent = run.symbol;
  // pairs_trading is the one strategy with a second instrument -- it
  // lives in run.params.symbol_b (freeform user text, same as run.symbol),
  // so this goes through textContent too, not the innerHTML template above.
  if (run.strategy === 'pairs_trading' && run.params?.symbol_b) {
    row.querySelector('.symbol-b-text').textContent = ` vs. ${run.params.symbol_b}`;
  }

  row.addEventListener('click', (e) => {
    if (e.target.closest('.run-actions')) return;
    showDetail(run.id);
  });

  row.querySelector('.journal-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    const params = new URLSearchParams({ backtest_run_id: run.id, symbol: run.symbol });
    window.location.href = `/journal.html?${params.toString()}`;
  });

  row.querySelector('.rerun-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    rerun(run);
  });

  row.querySelector('.delete-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    deleteRun(run.id, row);
  });

  listEl.appendChild(row);
}

async function showDetail(id) {
  errorEl.style.display = 'none';

  try {
    const response = await fetch(`${API_BASE}/backtests/${id}`, { headers: authHeaders() });
    const run = await response.json();

    if (!response.ok) {
      throw new Error(run.message || 'Failed to load this run');
    }
    if (!run.results) {
      throw new Error('This run has no results to display yet');
    }

    renderBacktestResult(run.results); // sets #results display:block itself
    resultsEl.scrollIntoView({ behavior: 'smooth' });
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
}

function rerun(run) {
  sessionStorage.setItem(
    'chartsense_rerun',
    JSON.stringify({
      symbol: run.symbol,
      asset_class: run.asset_class,
      strategy: run.strategy,
      params: run.params,
      start_date: run.start_date,
      end_date: run.end_date,
    })
  );
  window.location.href = '/backtest.html';
}

async function deleteRun(id, rowEl) {
  if (!confirm('Delete this backtest run? This cannot be undone.')) return;

  try {
    const response = await fetch(`${API_BASE}/backtests/${id}`, {
      method: 'DELETE',
      headers: authHeaders(),
    });
    const body = await response.json();

    if (!response.ok || body.success === false) {
      throw new Error(body.message || 'Failed to delete');
    }

    rowEl.remove();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
}

[filterStrategy, filterAssetClass, filterStatus].forEach((el) => {
  el.addEventListener('change', () => loadRuns(buildListUrl(), { reset: true }));
});

loadMoreButton.addEventListener('click', () => {
  if (nextPageUrl) loadRuns(nextPageUrl, { reset: false });
});

loadRuns(buildListUrl(), { reset: true });
