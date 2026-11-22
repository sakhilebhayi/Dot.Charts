// R-54: the browser side of the InfoDot sign-in. No browser and no dependencies: auth.js and the login screens are evaluated
// against small fakes of document, localStorage, window and fetch. The same flow is proven in a real browser by the local
// end-to-end run (r54/BUILD_ROLLOUT_L.md).
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const src = (f) => fs.readFileSync(path.join(root, 'src', f), 'utf8');

// Evaluate auth.js (and optionally a page script that imports from it) in one scope, with the fakes as the globals.
function load({ cookie = '', path: pathname = '/home.html', search = '', config = { sso: true, local_login: false }, responses = {}, storage = {}, page = null, elements = {} } = {}) {
  const calls = [];
  const jar = new Map(cookie ? cookie.split('; ').map((c) => c.split('=')) : []);
  const document = {
    get cookie() { return [...jar].map(([k, v]) => `${k}=${v}`).join('; '); },
    set cookie(v) {
      const [pair, ...attrs] = v.split('; ');
      const [k, val] = pair.split('=');
      if (attrs.some((a) => /^Max-Age=0$/i.test(a))) jar.delete(k); else jar.set(k, val);
    },
    getElementById: (id) => elements[id] || (elements[id] = { hidden: false, textContent: '', style: {}, href: '', addEventListener() {}, value: '' }),
    createElement: () => ({}),
  };
  const store = { ...storage };
  const localStorage = { getItem: (k) => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); }, removeItem: (k) => { delete store[k]; } };
  const location = { pathname, search, protocol: 'https:', replaced: null, assigned: null, replace(u) { this.replaced = u; }, assign(u) { this.assigned = u; } };
  const fetch = async (url, opts = {}) => {
    calls.push({ url, opts });
    const queued = responses[url];
    const r = typeof queued === 'function' ? queued(calls.filter((c) => c.url === url).length) : queued;
    if (url.endsWith('/auth/config')) return { ok: true, status: 200, json: async () => config };
    if (url.endsWith('/sanctum/csrf-cookie')) { jar.set('XSRF-TOKEN', 'fresh%3Dtoken'); return { ok: true, status: 204 }; }
    return r ? { ok: r.status < 400, status: r.status, json: async () => r.body ?? {} } : { ok: true, status: 200, json: async () => ({}) };
  };
  const strip = (s) => s.replace(/^import .*$/gm, '').replace(/^export (async )?(function|const)/gm, '$1$2');
  const base = "const API_BASE = '/api';\n";
  let code = base + strip(src('auth.js'));
  if (page) code += '\n' + strip(src(page));
  const names = 'hasSession,getToken,setToken,clearToken,isLoggedIn,authHeaders,apiFetch,authConfig,signInUrl,ssoStartUrl,returnPath,logout';
  const run = new Function('document', 'localStorage', 'window', 'fetch', 'URLSearchParams', `${code}\nreturn { ${names} };`);
  const window = { location };
  const api = run(document, localStorage, window, fetch, URLSearchParams);
  return { api, calls, jar, store, location, elements };
}

test('with no session the legacy token is used, as before', () => {
  const { api } = load({ storage: { chartsense_token: 'abc' }, cookie: '' });
  assert.equal(api.isLoggedIn(), true);
  assert.equal(api.authHeaders().Authorization, 'Bearer abc');
});

test('a session replaces any token left in the browser: it is removed and never sent', () => {
  const { api, store } = load({ cookie: 'charts_signed_in=1; XSRF-TOKEN=xs%3D', storage: { chartsense_token: 'old-never-expiring' } });
  assert.equal(store.chartsense_token, undefined, 'the never-expiring token is gone from localStorage');
  assert.equal(api.getToken(), null);
  const h = api.authHeaders({ 'Content-Type': 'application/json' });
  assert.equal(h.Authorization, undefined);
  assert.equal(h['X-XSRF-TOKEN'], 'xs=');
  assert.equal(h['Content-Type'], 'application/json');
  assert.equal(api.isLoggedIn(), true);
});

test('nothing stores a token for a session, and clearing forgets the flag', () => {
  const { api, store, jar } = load({ cookie: 'charts_signed_in=1' });
  assert.equal(Object.keys(store).length, 0);
  api.clearToken();
  assert.equal(jar.has('charts_signed_in'), false);
  assert.equal(api.isLoggedIn(), false);
});

test('login.html hands over to /sso/login carrying only a safe in-app next', () => {
  const ok = load({ search: '?next=%2Fjournal.html%3Fentry%3D7' });
  assert.equal(ok.api.ssoStartUrl('login'), '/sso/login?next=%2Fjournal.html%3Fentry%3D7');
  assert.equal(ok.api.ssoStartUrl('register'), '/sso/login?screen=register&next=%2Fjournal.html%3Fentry%3D7');
  for (const evil of ['//evil.test', 'https://evil.test', 'javascript:alert(1)', '/\\evil.test', 'journal.html', '/' + 'a'.repeat(400)]) {
    const t = load({ search: `?next=${encodeURIComponent(evil)}` });
    assert.equal(t.api.ssoStartUrl('login'), '/sso/login', evil);
  }
  assert.equal(load({ search: '' }).api.ssoStartUrl('login'), '/sso/login');
});

test('the Log in / Create account links return to the page the visitor was on, only for account pages', () => {
  assert.equal(load({ path: '/journal.html', search: '?x=1' }).api.signInUrl('login'), '/login.html?next=%2Fjournal.html%3Fx%3D1');
  assert.equal(load({ path: '/strategy-builder.html' }).api.signInUrl('register'), '/register.html?next=%2Fstrategy-builder.html');
  assert.equal(load({ path: '/' }).api.signInUrl('login'), '/login.html');
  assert.equal(load({ path: '/login.html' }).api.signInUrl('login'), '/login.html');
});

test('login.js redirects to the backend when InfoDot is in charge, and shows the form otherwise', async () => {
  const on = load({ page: 'login.js', search: '?next=%2Fhistory.html', config: { sso: true, local_login: false } });
  await new Promise((r) => setTimeout(r, 5));
  assert.equal(on.location.replaced, '/sso/login?next=%2Fhistory.html');

  const off = load({ page: 'login.js', config: { sso: false, local_login: true } });
  await new Promise((r) => setTimeout(r, 5));
  assert.equal(off.location.replaced, null);
  assert.equal(off.elements.legacyForm.hidden, false);
  assert.equal(off.elements.handover.hidden, true);

  const glass = load({ page: 'login.js', search: '?local=1', config: { sso: true, local_login: true } });
  await new Promise((r) => setTimeout(r, 5));
  assert.equal(glass.location.replaced, null, 'break-glass with ?local=1 shows the form');

  const noGlass = load({ page: 'login.js', search: '?local=1', config: { sso: true, local_login: false } });
  await new Promise((r) => setTimeout(r, 5));
  assert.equal(noGlass.location.replaced, '/sso/login', '?local=1 alone never opens the form');
});

test('register.js hands over with the register screen', async () => {
  const on = load({ page: 'register.js', config: { sso: true, local_login: false } });
  await new Promise((r) => setTimeout(r, 5));
  assert.equal(on.location.replaced, '/sso/login?screen=register');
});

test('session logout posts with the CSRF header and goes to the InfoDot end-session address', async () => {
  const t = load({ cookie: 'charts_signed_in=1; XSRF-TOKEN=tok', responses: { '/api/auth/sso-logout': { status: 200, body: { redirect: 'https://infodot.co.za/oauth/end-session?x=1' } } } });
  const dest = await t.api.logout();
  assert.equal(dest, 'https://infodot.co.za/oauth/end-session?x=1');
  const post = t.calls.find((c) => c.url === '/api/auth/sso-logout');
  assert.equal(post.opts.method, 'POST');
  assert.equal(post.opts.headers['X-XSRF-TOKEN'], 'tok');
  assert.equal(t.jar.has('charts_signed_in'), false, 'the flag is cleared');
});

test('session logout still ends locally when the backend is unreachable', async () => {
  const t = load({ cookie: 'charts_signed_in=1; XSRF-TOKEN=tok', responses: { '/api/auth/sso-logout': () => { throw new Error('down'); } } });
  assert.equal(await t.api.logout(), '/');
  assert.equal(t.jar.has('charts_signed_in'), false);
});

test('legacy logout revokes the token, then goes to the console when InfoDot is in charge', async () => {
  const t = load({ storage: { chartsense_token: 'abc' }, config: { sso: true, local_login: false } });
  assert.equal(await t.api.logout(), '/');
  assert.equal(t.calls.find((c) => c.url === '/api/logout').opts.headers.Authorization, 'Bearer abc');
  assert.equal(t.store.chartsense_token, undefined);
  const off = load({ storage: { chartsense_token: 'abc' }, config: { sso: false, local_login: true } });
  assert.equal(await off.api.logout(), '/login.html');
});

test('a session write that fails CSRF (419) refreshes the cookie and retries once', async () => {
  let n = 0;
  const t = load({ cookie: 'charts_signed_in=1; XSRF-TOKEN=stale', responses: { '/api/journal-entries': () => (++n === 1 ? { status: 419 } : { status: 201 }) } });
  const r = await t.api.apiFetch('/api/journal-entries', { method: 'POST', headers: {} });
  assert.equal(r.status, 201);
  assert.equal(t.calls.filter((c) => c.url === '/api/journal-entries').length, 2);
  assert.ok(t.calls.some((c) => c.url === '/sanctum/csrf-cookie'));
  assert.equal(t.calls.at(-1).opts.credentials, 'same-origin');
});

test('no page script sends a bearer header by hand or reads the token itself any more', () => {
  for (const f of fs.readdirSync(path.join(root, 'src')).filter((x) => x.endsWith('.js') && x !== 'auth.js')) {
    const s = src(f);
    assert.doesNotMatch(s, /Bearer \$\{/, `${f} builds its own bearer header`);
    assert.doesNotMatch(s, /getToken/, `${f} reads the token`);
  }
});

test('the guest links on every page go through signInUrl, so the return page is carried', () => {
  for (const f of ['app-header.js', 'home.js', 'header-search.js', 'journal.js']) {
    const s = src(f);
    assert.doesNotMatch(s.replace(/\/\/.*$/gm, ''), /href:\s*'\/(login|register)\.html'|\.href = '\/(login|register)\.html'/, f);
    assert.match(s, /signInUrl\(/, f);
  }
});
