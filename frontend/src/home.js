// The signed-in landing (home.html). The page is complete without this
// script: the markup ships the logo, the headline, the one primary action
// and the empty states. This only fills in what the API knows about the
// person: their name and their own most recent records. Nothing here is
// computed or invented; every value shown is a field of a stored record.
import { getToken, clearToken, isLoggedIn, logout } from './auth.js';
import { API_BASE } from './api-base.js';

const SHOWN = 3;

// Display names for the server's strategy enum, as the Backtest page's own
// <select> words them.
const STRATEGY_NAMES = {
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

const welcomeEl = document.getElementById('welcome');
const authStateEl = document.getElementById('authState');

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text; // records are user text: never innerHTML
  return node;
}

function day(value) {
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? ''
    : date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

function showSignedOut(message) {
  authStateEl.replaceChildren(Object.assign(el('a', null, 'Log in'), { href: '/login.html' }));
  welcomeEl.textContent = message;
  ['runs', 'strategies', 'journal'].forEach((name) => {
    document.getElementById(`${name}List`).hidden = true;
    const empty = document.getElementById(`${name}Empty`);
    empty.hidden = false;
    empty.textContent = 'Log in to see what is saved on your account.';
  });
}

function showSignedIn() {
  const link = Object.assign(el('a', null, 'Log out'), { href: '#' });
  link.addEventListener('click', async (event) => {
    event.preventDefault();
    await logout();
    window.location.href = '/login.html';
  });
  authStateEl.replaceChildren(link);
}

async function get(path) {
  const response = await fetch(`${API_BASE}${path}`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
  });
  if (response.status === 401) throw Object.assign(new Error('unauthenticated'), { status: 401 });
  if (!response.ok) throw Object.assign(new Error('unavailable'), { status: response.status });
  return response.json();
}

// Fills one panel from one paginated list. An empty list leaves the
// shipped empty state in place; a failed request says so instead of
// pretending there is nothing.
function fill(name, result, renderItem) {
  const list = document.getElementById(`${name}List`);
  const empty = document.getElementById(`${name}Empty`);

  if (result.status === 'rejected') {
    empty.textContent = 'This could not be loaded just now. Reload the page to try again.';
    return;
  }

  const items = (result.value.data || []).slice(0, SHOWN);
  if (items.length === 0) return;

  list.replaceChildren(...items.map(renderItem));
  list.hidden = false;
  empty.hidden = true;
}

function runItem(run) {
  const item = el('li');
  const head = el('div', 'landing-item-title mono');
  const pair = run.strategy === 'pairs_trading' && run.params?.symbol_b ? ` vs. ${run.params.symbol_b}` : '';
  head.append(el('span', null, `${run.symbol}${pair}`), el('span', `status ${run.status}`, run.status));
  const period = [day(run.start_date), day(run.end_date)].filter(Boolean).join(' to ');
  item.append(
    head,
    el('div', 'landing-item-meta', [STRATEGY_NAMES[run.strategy] || run.strategy, period].filter(Boolean).join(', ')),
    el('div', 'landing-item-meta', `Run on ${day(run.created_at)}`),
  );
  return item;
}

function strategyItem(strategy) {
  const item = el('li');
  item.append(el('div', 'landing-item-title', strategy.name));
  if (strategy.description) item.append(el('div', 'landing-item-meta', strategy.description));
  item.append(el('div', 'landing-item-meta', `Saved on ${day(strategy.created_at)}`));
  return item;
}

function journalItem(entry) {
  const item = el('li');
  item.append(
    el('div', 'landing-item-title', entry.title),
    el('div', 'landing-item-meta', [entry.symbol, `Written on ${day(entry.created_at)}`].filter(Boolean).join(', ')),
  );
  return item;
}

async function load() {
  if (!isLoggedIn()) {
    showSignedOut('You are not logged in.');
    return;
  }

  showSignedIn();

  const [me, runs, strategies, journal] = await Promise.allSettled([
    get('/me'),
    get('/backtests'),
    get('/strategies'),
    get('/journal-entries'),
  ]);

  // A stale token is handled the way the other pages handle it: drop the
  // local copy and show the logged-out state.
  if ([me, runs, strategies, journal].some((r) => r.status === 'rejected' && r.reason.status === 401)) {
    clearToken();
    showSignedOut('Your session has expired.');
    return;
  }

  if (me.status === 'fulfilled' && me.value.name) {
    const firstName = String(me.value.name).trim().split(/\s+/)[0];
    if (firstName) welcomeEl.textContent = `Welcome, ${firstName}.`;
  }

  fill('runs', runs, runItem);
  fill('strategies', strategies, strategyItem);
  fill('journal', journal, journalItem);

  // The only count on the page: the account's own total, as the API reports it.
  if (runs.status === 'fulfilled' && runs.value.total > SHOWN) {
    document.getElementById('runsLink').textContent = `All ${runs.value.total} runs`;
  }
}

load();
