import { API_BASE } from './api-base.js';

// Two ways of being signed in, never mixed:
//
//  1. Session (R-54, when the platform signs people in through InfoDot): the browser holds an HttpOnly Laravel session cookie
//     that scripts cannot read. All that scripts can see is `charts_signed_in`, a plain flag the server sets at sign-in; it
//     grants nothing (a forged one just makes the API answer 401, which clears it). Writes carry the XSRF-TOKEN cookie back as
//     the X-XSRF-TOKEN header. No token is ever stored.
//  2. Legacy bearer token in localStorage (the old local login, still honoured until the owner ends it): used only when there is
//     no session, and removed the moment a session exists.

const STORAGE_KEY = 'chartsense_token';
const HINT = 'charts_signed_in';

function readCookie(name) {
  const hit = document.cookie.split('; ').find((part) => part.startsWith(`${name}=`));
  return hit ? decodeURIComponent(hit.slice(name.length + 1)) : null;
}

function dropCookie(name) {
  const secure = window.location.protocol === 'https:' ? '; Secure' : '';
  document.cookie = `${name}=; Max-Age=0; Path=/; SameSite=Lax${secure}`;
}

export function hasSession() {
  return readCookie(HINT) !== null;
}

// A session replaces any token left in the browser from before the account was linked (the server has revoked it).
if (hasSession()) {
  try { localStorage.removeItem(STORAGE_KEY); } catch { /* storage blocked: nothing to remove */ }
}

export function getToken() {
  if (hasSession()) return null;
  try { return localStorage.getItem(STORAGE_KEY); } catch { return null; }
}

export function setToken(token) {
  localStorage.setItem(STORAGE_KEY, token);
}

// Called when the API says "not signed in" (401): forgets the token and the session flag, so the page shows the signed-out state.
export function clearToken() {
  try { localStorage.removeItem(STORAGE_KEY); } catch { /* storage blocked */ }
  dropCookie(HINT);
}

export function isLoggedIn() {
  return hasSession() || getToken() !== null;
}

// The Laravel CSRF cookie for session writes. Fetched once if it is missing (for example after the cookie expired).
function csrfCookieUrl() {
  return `${API_BASE.replace(/\/api\/?$/, '')}/sanctum/csrf-cookie`;
}

export async function ensureCsrf(force = false) {
  if (!hasSession()) return;
  if (!force && readCookie('XSRF-TOKEN')) return;
  try {
    await fetch(csrfCookieUrl(), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
  } catch { /* offline: the write will fail on its own and say so */ }
}

if (hasSession()) ensureCsrf();

// Headers for an API call: the bearer token (legacy) or the CSRF header (session), plus what the caller adds.
export function authHeaders(extra = {}) {
  const headers = { Accept: 'application/json', ...extra };
  const token = getToken();
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  } else if (hasSession()) {
    const xsrf = readCookie('XSRF-TOKEN');
    if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;
  }
  return headers;
}

// fetch() with the right credentials. A session write that fails CSRF (419) refreshes the cookie and is retried once.
export async function apiFetch(url, options = {}) {
  const run = () => fetch(url, { ...options, credentials: 'same-origin', headers: authHeaders(options.headers || {}) });
  let response = await run();
  if (response.status === 419 && hasSession()) {
    await ensureCsrf(true);
    response = await run();
  }
  return response;
}

// ---- Which sign-in the platform is using -------------------------------------------------------------------------------

let configPromise = null;

// { sso: boolean, local_login: boolean }. If the answer cannot be had, the legacy form stays available (it is what the page was).
export function authConfig() {
  configPromise ??= fetch(`${API_BASE}/auth/config`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    .then((r) => (r.ok ? r.json() : { sso: false, local_login: true }))
    .catch(() => ({ sso: false, local_login: true }));
  return configPromise;
}

// The in-app page to come back to after signing in. Only the pages that need an account are worth returning to; the server
// validates it again and falls back to /home.html for anything else.
const RETURNABLE = /^\/(home|backtest|history|journal|options|strategy-builder)\.html$/;

export function returnPath() {
  const { pathname, search } = window.location;
  return RETURNABLE.test(pathname) ? `${pathname}${search}` : null;
}

// The link every "Log in" / "Create account" button uses. login.html and register.html hand over to InfoDot (or show the
// legacy form when the platform is not using InfoDot).
export function signInUrl(screen = 'login') {
  const page = screen === 'register' ? '/register.html' : '/login.html';
  const next = returnPath();
  return next ? `${page}?next=${encodeURIComponent(next)}` : page;
}

// Where login.html / register.html send a visitor when InfoDot is in charge.
export function ssoStartUrl(screen = 'login') {
  const params = new URLSearchParams(window.location.search);
  const next = params.get('next');
  const out = new URLSearchParams();
  if (screen === 'register') out.set('screen', 'register');
  if (next && next.startsWith('/') && !next.startsWith('//') && !next.includes('\\') && next.length <= 300) out.set('next', next);
  const qs = out.toString();
  return `/sso/login${qs ? `?${qs}` : ''}`;
}

// Logs out for real and says where to go next.
//  - Session: ends the session here, then the browser goes to InfoDot's end-session address, which signs the person out of
//    every Dot platform and returns to the console.
//  - Legacy token: revokes it on the server and clears the local copy. Best-effort: a dead backend must never trap the user
//    in a logged-in UI.
export async function logout() {
  if (hasSession()) {
    let destination = '/';
    try {
      await ensureCsrf();
      const response = await fetch(`${API_BASE}/auth/sso-logout`, {
        method: 'POST', credentials: 'same-origin', headers: authHeaders(),
      });
      if (response.ok) {
        const body = await response.json();
        if (typeof body.redirect === 'string') destination = body.redirect;
      }
    } catch {
      // Unreachable backend: the local state still ends.
    }
    clearToken();
    return destination;
  }

  const token = getToken();
  if (token) {
    try {
      await fetch(`${API_BASE}/logout`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
    } catch {
      // Unreachable backend: the local session still ends.
    }
  }
  clearToken();
  const config = await authConfig();
  return config.sso ? '/' : '/login.html';
}
