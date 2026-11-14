// The ONE app header of Dot.Charts. Every page carries `<div id="app-header" data-page="...">` and imports this module; the
// header is printed by dot-ui's static renderer from the model below, so there is a single place to change the nav, the
// brand, the search, the switcher or the account menu (it used to be nine hand-copied blocks that had drifted apart).
//
//   data-page   which nav item is current (a key of PAGES in labels.js); omitted: none
//   data-skip   the id of the region the skip link jumps to (default #main)
//
// Signed in (a Sanctum token in localStorage): account menu with the name and "Log out", search over the account's own
// backtests, journal entries and strategies. Signed out: a "Log in" link, search over the platform's pages only, and the
// pill says so. No bell: Dot.Charts has no notifications, so there is nothing for one to show.
import './dot-ui/js/dot-search.js';
import './dot-ui/js/dot-switcher.js';
import './dot-ui/js/dot-nav.js';
import { mountHeader } from './dot-ui/js/dot-header-static.js';
import { mount as mountSwitcher } from './dot-ui/js/dot-switcher-static.js';
import { registry, INFODOT_PLATFORMS_URL } from './registry.js';
import { API_BASE } from './api-base.js';
import { isLoggedIn, getToken, clearToken, logout } from './auth.js';
import { navModel } from './labels.js';
import { createSearch } from './header-search.js';

const LOGOS = '/dot-ui/img/platforms';
const ME_KEY = 'chartsense_me';
const host = document.getElementById('app-header');

function stored() {
  try { return JSON.parse(window.sessionStorage.getItem(ME_KEY) || 'null'); } catch { return null; }
}
function remember(me) {
  try { window.sessionStorage.setItem(ME_KEY, JSON.stringify(me)); } catch { /* private mode: the name is fetched again next page */ }
}
function forget() {
  try { window.sessionStorage.removeItem(ME_KEY); } catch { /* nothing to forget */ }
}

const initials = (name) => String(name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('');

function logo() {
  const img = document.createElement('img');
  img.src = '/images/logo.png';
  img.alt = '';
  img.width = 1181;
  img.height = 886;
  return img;
}

function grid() {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('class', 'dsw-btn__icon');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  [4, 10, 16].forEach((y) => [4, 10, 16].forEach((x) => {
    const r = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
    r.setAttribute('x', x); r.setAttribute('y', y); r.setAttribute('width', 4); r.setAttribute('height', 4);
    r.setAttribute('rx', 1.2); r.setAttribute('fill', 'currentColor'); r.setAttribute('stroke', 'none');
    svg.appendChild(r);
  }));
  return svg;
}

// The switcher before (or without) the registry: the same plain link the no-JavaScript case of the package gets.
function switcherPlaceholder() {
  const root = document.createElement('div');
  root.className = 'dsw dsw--header';
  const a = document.createElement('a');
  a.className = 'dsw-fallback';
  a.href = INFODOT_PLATFORMS_URL;
  a.title = 'Switch Dot platform';
  a.appendChild(grid());
  const label = document.createElement('span');
  label.className = 'dsw-btn__label';
  label.textContent = 'Dot Apps';
  a.appendChild(label);
  root.appendChild(a);
  return root;
}

function render() {
  const page = host.dataset.page || '';
  const signedIn = isLoggedIn();
  const me = signedIn ? stored() : null;
  const search = createSearch({ signedIn, onUnauthorized: render });
  const login = Object.assign(document.createElement('a'), { className: 'dh-signin', href: '/login.html', textContent: 'Log in' });
  const switcherRoot = switcherPlaceholder();

  const model = {
    id: 'dh',
    skip: host.dataset.skip || '#main',
    brand: { href: signedIn ? '/home.html' : '/', label: 'Dot.Charts home', node: logo() },
    nav: navModel(page),
    navLabel: 'Dot.Charts',
    search: {
      name: 'q',
      placeholder: signedIn ? 'Search your runs, notes, strategies' : 'Search the pages and tools',
      label: signedIn ? 'Search your backtests, journal and strategies' : 'Search the pages and tools',
      hint: 'Ctrl K',
      action: '/home.html',
      results: search.results,
      loadingText: 'Searching your account...',
      escapeClears: true,
    },
    tools: signedIn ? [switcherRoot] : [switcherRoot, login],
    account: signedIn
      ? { name: me?.name || 'Your account', email: me?.email || '', initials: initials(me?.name) || '•', signOut: { action: '/login.html', method: 'get', label: 'Log out' } }
      : null,
    drawer: { title: 'Dot.Charts' },
    labels: { menu: 'Menu', search: 'Search', closeSearch: 'Close search', closeMenu: 'Close menu' },
  };

  mountHeader(host, model);
  search.attach(host);

  if (!signedIn) {
    // the drawer carries what the bar's "Log in" link shows on wider screens
    const body = host.querySelector('.dh-drawer__body');
    if (body) {
      const box = document.createElement('section');
      box.className = 'dh-dsignin';
      box.setAttribute('aria-label', 'Account');
      const note = document.createElement('p');
      note.textContent = 'Log in to see your backtests, strategies and notes.';
      const a = Object.assign(document.createElement('a'), { href: '/login.html', textContent: 'Log in' });
      const b = Object.assign(document.createElement('a'), { href: '/register.html', textContent: 'Create account' });
      box.append(note, a, b);
      body.appendChild(box);
    }
  }

  registry().then((m) => {
    if (!m || !switcherRoot.isConnected) return; // stays the plain link to InfoDot's Platforms page
    mountSwitcher(switcherRoot, m, { logos: LOGOS, buttonLabel: 'Dot platforms' });
  });

  if (signedIn && !me) hydrateAccount();
}

// The name for the account menu: one GET /api/me per browser session (cached), filled into the nodes already printed.
async function hydrateAccount() {
  try {
    const res = await fetch(`${API_BASE}/me`, { headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` }, credentials: 'omit' });
    if (res.status === 401) { clearToken(); forget(); render(); return; }
    if (!res.ok) return;
    const body = await res.json();
    if (!body || !body.name) return;
    remember({ name: body.name, email: body.email || '' });
    host.querySelectorAll('.dh-avatar').forEach((n) => { n.textContent = initials(body.name) || '•'; });
    host.querySelectorAll('.dh-menu__name').forEach((n) => { n.textContent = body.name; n.title = body.name; });
    host.querySelectorAll('.dh-menu__who, .dh-dacct__who').forEach((who) => {
      if (body.email && !who.querySelector('.dh-menu__sub')) {
        const sub = document.createElement('span');
        sub.className = 'dh-menu__sub';
        sub.textContent = body.email;
        sub.title = body.email;
        (who.querySelector('.dh-menu__name')?.parentNode || who).appendChild(sub);
      }
    });
    host.querySelectorAll('.dh-account__btn').forEach((b) => b.setAttribute('aria-label', `Account menu: ${body.name}`));
  } catch { /* offline: the menu keeps its generic name */ }
}

// "Log out" really logs out: the token is revoked on the server, then cleared (the form's plain action is the no-script fallback).
document.addEventListener('submit', async (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || !form.classList.contains('dh-signout')) return;
  event.preventDefault();
  await logout();
  forget();
  window.location.assign('/login.html');
});

if (host) render();

// Pages call this when a request told them the session is gone, so the bar stops offering the account.
export function refreshHeader() {
  if (host) render();
}
