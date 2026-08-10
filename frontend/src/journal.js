import { getToken, clearToken, isLoggedIn } from './auth.js';

const API_BASE = 'http://localhost:8000/api';

const authStateEl = document.getElementById('authState');
const loginNoticeEl = document.getElementById('loginNotice');
const formCardEl = document.getElementById('formCard');

if (isLoggedIn()) {
  authStateEl.innerHTML = '<a href="#" id="logoutLink" style="color:var(--accent)">Log out</a>';
  document.getElementById('logoutLink').addEventListener('click', (e) => {
    e.preventDefault();
    clearToken();
    window.location.reload();
  });
} else {
  authStateEl.innerHTML = '<a href="/login.html" style="color:var(--accent)">Log in</a>';
  loginNoticeEl.style.display = 'block';
  formCardEl.style.display = 'none';
}

const errorEl = document.getElementById('error');
const emptyEl = document.getElementById('empty');
const listEl = document.getElementById('entriesList');
const loadMoreButton = document.getElementById('loadMore');

const titleInput = document.getElementById('entryTitle');
const bodyInput = document.getElementById('entryBody');
const symbolInput = document.getElementById('entrySymbol');
const strategySelect = document.getElementById('entryStrategy');
const backtestSelect = document.getElementById('entryBacktest');
const saveButton = document.getElementById('saveButton');
const formTitleEl = document.getElementById('formTitle');
const formErrorEl = document.getElementById('formError');

let nextPageUrl = null;
let editingId = null; // set by the edit flow (added next)

function authHeaders() {
  const token = getToken();
  return token
    ? { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' }
    : { Accept: 'application/json', 'Content-Type': 'application/json' };
}

async function handleUnauthorized() {
  clearToken();
  authStateEl.innerHTML = '<a href="/login.html" style="color:var(--accent)">Log in</a>';
  loginNoticeEl.style.display = 'block';
  formCardEl.style.display = 'none';
  errorEl.textContent = 'Your session has expired. Log in again.';
  errorEl.style.display = 'block';
}

async function loadDropdownOptions(endpoint, selectEl, labelKey) {
  if (!isLoggedIn()) return;

  let url = `${API_BASE}${endpoint}`;
  const items = [];

  while (url) {
    const response = await fetch(url, { headers: authHeaders() });
    if (response.status === 401) {
      await handleUnauthorized();
      return;
    }
    if (!response.ok) return;
    const body = await response.json();
    items.push(...(body.data || []));
    url = body.next_page_url || null;
  }

  items.forEach((item) => {
    const opt = document.createElement('option');
    opt.value = item.id;
    opt.textContent = item[labelKey];
    selectEl.appendChild(opt);
  });
}

function renderEntry(entry) {
  const card = document.createElement('div');
  card.className = 'entry-card';
  card.dataset.id = entry.id;

  const badges = [];
  if (entry.symbol) badges.push(`<span class="badge">${entry.symbol}</span>`);
  if (entry.backtest_run_id) badges.push(`<span class="badge">Backtest #${entry.backtest_run_id}</span>`);
  if (entry.custom_strategy_id) badges.push(`<span class="badge">Strategy #${entry.custom_strategy_id}</span>`);

  card.innerHTML = `
    <div class="entry-title">${entry.title}</div>
    <div class="entry-meta">${badges.join('')}${new Date(entry.created_at).toLocaleString()}</div>
    <div class="entry-body">${entry.body}</div>
    <div class="entry-actions">
      <button class="secondary edit-btn">Edit</button>
      <button class="danger delete-btn">Delete</button>
    </div>
  `;

  card.querySelector('.delete-btn').addEventListener('click', () => deleteEntry(entry.id, card));
  card.querySelector('.edit-btn').addEventListener('click', () => startEdit(entry));

  listEl.appendChild(card);
}

async function loadEntries(url, { reset }) {
  if (!isLoggedIn()) return;
  errorEl.style.display = 'none';

  try {
    const response = await fetch(url, { headers: authHeaders() });
    if (response.status === 401) {
      await handleUnauthorized();
      return;
    }
    const body = await response.json();

    if (!response.ok) {
      throw new Error(body.message || 'Failed to load journal entries');
    }

    if (reset) {
      listEl.innerHTML = '';
    }

    body.data.forEach(renderEntry);

    nextPageUrl = body.next_page_url;
    loadMoreButton.style.display = nextPageUrl ? 'block' : 'none';
    emptyEl.style.display = reset && body.data.length === 0 ? 'block' : 'none';
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
}

async function deleteEntry(id, cardEl) {
  // Matches history.js's deleteRun() convention exactly -- a destructive
  // action needs a confirm() gate, not an immediate fire.
  if (!confirm('Delete this journal entry? This cannot be undone.')) return;

  try {
    const response = await fetch(`${API_BASE}/journal-entries/${id}`, { method: 'DELETE', headers: authHeaders() });
    if (response.status === 401) {
      await handleUnauthorized();
      return;
    }
    if (!response.ok) throw new Error('Failed to delete entry');
    cardEl.remove();
    if (!listEl.children.length) emptyEl.style.display = 'block';
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
}

const cancelEditButton = document.getElementById('cancelEditButton');

function startEdit(entry) {
  editingId = entry.id;
  titleInput.value = entry.title;
  bodyInput.value = entry.body;
  symbolInput.value = entry.symbol || '';
  strategySelect.value = entry.custom_strategy_id || '';
  backtestSelect.value = entry.backtest_run_id || '';
  formTitleEl.textContent = 'Edit entry';
  saveButton.textContent = 'Update entry';
  cancelEditButton.style.display = 'inline-block';
  formCardEl.scrollIntoView({ behavior: 'smooth' });
}

function resetForm() {
  editingId = null;
  titleInput.value = '';
  bodyInput.value = '';
  symbolInput.value = '';
  strategySelect.value = '';
  backtestSelect.value = '';
  formTitleEl.textContent = 'New entry';
  saveButton.textContent = 'Save entry';
  cancelEditButton.style.display = 'none';
}

cancelEditButton.addEventListener('click', resetForm);

saveButton.addEventListener('click', async () => {
  formErrorEl.style.display = 'none';

  const payload = {
    title: titleInput.value,
    body: bodyInput.value,
    symbol: symbolInput.value || null,
    backtest_run_id: backtestSelect.value || null,
    custom_strategy_id: strategySelect.value || null,
  };

  try {
    const url = editingId ? `${API_BASE}/journal-entries/${editingId}` : `${API_BASE}/journal-entries`;
    const method = editingId ? 'PATCH' : 'POST';
    const response = await fetch(url, {
      method,
      headers: authHeaders(),
      body: JSON.stringify(payload),
    });
    if (response.status === 401) {
      await handleUnauthorized();
      return;
    }
    const body = await response.json();
    if (!response.ok) {
      throw new Error(body.error || Object.values(body.errors || {}).flat().join(' ') || 'Failed to save entry');
    }

    resetForm();
    listEl.innerHTML = '';
    loadEntries(`${API_BASE}/journal-entries`, { reset: true });
  } catch (err) {
    formErrorEl.textContent = err.message;
    formErrorEl.style.display = 'block';
  }
});

loadMoreButton.addEventListener('click', () => {
  if (nextPageUrl) loadEntries(nextPageUrl, { reset: false });
});

loadDropdownOptions('/strategies', strategySelect, 'name');
loadDropdownOptions('/backtests', backtestSelect, 'symbol').then(() => {
  const params = new URLSearchParams(window.location.search);
  const prefilledBacktestId = params.get('backtest_run_id');
  const prefilledSymbol = params.get('symbol');

  if (prefilledBacktestId && backtestSelect.querySelector(`option[value="${prefilledBacktestId}"]`)) {
    backtestSelect.value = prefilledBacktestId;
  }
  if (prefilledSymbol) {
    symbolInput.value = prefilledSymbol;
  }
});
loadEntries(`${API_BASE}/journal-entries`, { reset: true });
