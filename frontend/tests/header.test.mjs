// R-52: Roboto, the one shared header, the search and the responsive rules (run: npm test). No browser, no dependencies:
// the rendered behaviour is proven in a real browser by the R-52 harness (see docs/R52_TYPE_NAV_SEARCH.md).
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (f) => fs.readFileSync(path.join(root, f), 'utf8');
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/<!--[\s\S]*?-->/g, '').replace(/^\s*\/\/.*$/gm, '');
const walk = (dir) => fs.readdirSync(path.join(root, dir), { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(dir, e.name)) : [path.join(dir, e.name)]));
const html = fs.readdirSync(root).filter((f) => f.endsWith('.html'));
const appPages = ['index', 'home', 'backtest', 'history', 'journal', 'options', 'strategy-builder'].map((p) => `${p}.html`);
const hostSource = walk('src').filter((f) => /\.(js|css)$/.test(f) && !f.startsWith('src/dot-ui'));

test('there are nine pages and seven of them carry the one header mount', () => {
  assert.equal(html.length, 9);
  for (const f of appPages) {
    const src = read(f);
    assert.equal((src.match(/id="app-header"/g) || []).length, 1, f);
    assert.doesNotMatch(src, /<header\b|class="bar"|class="bar-logo"|<nav\b/, `${f}: no hand-copied header`);
  }
  for (const f of ['login.html', 'register.html']) assert.doesNotMatch(read(f), /id="app-header"|<header\b/, `${f} is a focused sign-in page`);
});

test('every page has a landmark to skip to, and the skip target exists', () => {
  for (const f of appPages) {
    const src = read(f);
    const target = (src.match(/data-skip="#([^"]+)"/) || [null, 'main'])[1];
    assert.match(src, new RegExp(`id="${target}"`), `${f} #${target}`);
  }
});

test('Roboto is the typeface: declared, served from the origin, mapped to the platform tokens, on every page', () => {
  const roboto = read('src/dot-ui/css/dot-roboto.css');
  assert.match(roboto, /@font-face\s*\{[^}]*font-family:\s*'?Roboto'?/);
  for (const m of roboto.matchAll(/url\(([^)]+)\)/g)) {
    const url = m[1].replace(/['"]/g, '');
    assert.doesNotMatch(url, /^(https?:)?\/\//, url);
    assert.ok(fs.existsSync(path.join(root, 'src/dot-ui/css', url.split('?')[0])), url);
  }
  const css = read('src/instrument.css');
  // dot-ui 0.6.1: one minified stylesheet carries roboto, type, tokens, search, switcher and header
  assert.match(css, /@import '\.\/dot-ui\/css\/dot-ui\.min\.css';/);
  assert.ok(fs.existsSync(path.join(root, 'src/dot-ui/css/dot-ui.min.css')));
  assert.match(css, /--font-display:\s*var\(--dot-font-sans\)/);
  assert.match(css, /--font-body:\s*var\(--dot-font-sans\)/);
  assert.match(css, /--font-mono:\s*var\(--dot-font-mono\)/);
  for (const f of html) assert.match(read(f), /<html lang="en" class="dot-roboto">/, f);
});

test('no other typeface is named or loaded, and nothing is fetched from a font CDN', () => {
  const files = [...hostSource, ...html];
  for (const f of files) {
    const src = strip(read(f));
    assert.doesNotMatch(src, /fonts\.googleapis\.com|fonts\.gstatic\.com|cdn\.jsdelivr|unpkg\.com|cdnjs/i, f);
    assert.doesNotMatch(src, /IBM Plex|Archivo|Big Shoulders/i, f);
  }
  // the optional Archivo / stencil sheets the package still ships are not linked or imported by the host
  for (const f of files) assert.doesNotMatch(read(f), /dot-fonts\.css|dot-switcher-fonts\.css/, f);
});

test('the pages carry no inline style, script or event handler', () => {
  for (const f of html) {
    const src = strip(read(f));
    assert.doesNotMatch(src, /\sstyle\s*=/i, `${f}: style attribute`);
    assert.doesNotMatch(src, /<style\b/i, `${f}: <style>`);
    assert.doesNotMatch(src, /<script(?![^>]*\ssrc=)/i, `${f}: inline script`);
    assert.doesNotMatch(src, /\son[a-z]+\s*=/i, `${f}: inline handler`);
  }
  for (const f of ['src/main.js', 'src/app-header.js', 'src/header-search.js']) {
    assert.doesNotMatch(strip(read(f)), /\sstyle\s*=\s*["'\\]|setAttribute\(\s*['"]style/, `${f}: markup with a style attribute`);
  }
});

test('the app header: one module, the shared static renderer, the shared scripts, a switcher, no bell', () => {
  const src = strip(read('src/app-header.js'));
  assert.match(src, /import \{ mountHeader \} from '\.\/dot-ui\/js\/dot-header-static\.min\.js'/);
  for (const f of ['dot-nav.min.js', 'dot-search.min.js', 'dot-switcher.min.js']) assert.match(src, new RegExp(`import '\\./dot-ui/js/${f.replace(/\./g, '\\.')}'`));
  assert.doesNotMatch(src, /\bbell\(/, 'Dot.Charts has no notifications: no bell');
  assert.match(src, /skip: host\.dataset\.skip/);
  assert.match(src, /navModel\(page\)/);
  // the account menu exists only for a signed-in person; signed out there is a Log in link and a drawer sign-in section
  assert.match(src, /account: signedIn\s*\?/);
  assert.match(src, /className: 'dh-signin'/);
  // Log out really logs out (token revoked server-side), it is not a plain link
  assert.match(src, /await logout\(\)/);
});

test('the nav model: Home, Chart read, then two named groups, seven distinct routes', async () => {
  const { navModel, PAGES } = await import('../src/labels.js');
  const nav = navModel('backtest');
  assert.deepEqual(nav.map((i) => i.label), ['Home', 'Chart read', 'Tools', 'Records']);
  assert.deepEqual(nav[2].items.map((i) => i.label), ['Backtest', 'Strategy Builder', 'Options Vol']);
  assert.deepEqual(nav[3].items.map((i) => i.label), ['History', 'Journal']);
  assert.equal(nav[2].items.filter((i) => i.current).length, 1);
  assert.equal(new Set(PAGES.map((p) => p.href)).size, PAGES.length);
});

test('the strategy names match the server\'s (one list in labels.js, one in SearchController)', async () => {
  const { STRATEGY_NAMES } = await import('../src/labels.js');
  const php = fs.readFileSync(path.join(root, '..', 'backend/app/Http/Controllers/SearchController.php'), 'utf8');
  const block = php.slice(php.indexOf('STRATEGY_NAMES = ['), php.indexOf('];', php.indexOf('STRATEGY_NAMES = [')));
  const server = Object.fromEntries([...block.matchAll(/'([a-z0-9_]+)' => '([^']+)'/g)].map((m) => [m[1], m[2]]));
  assert.deepEqual(server, STRATEGY_NAMES);
});

test('the search: signed in it asks /api/search for the account only, signed out it asks nothing', () => {
  const src = strip(read('src/header-search.js'));
  assert.match(src, /\$\{API_BASE\}\/search\?q=\$\{encodeURIComponent\(q\)\}/);
  // R-54: the account search carries the session cookie (same origin) instead of omitting credentials; auth.js adds the token or CSRF header.
  assert.match(src, /credentials: 'same-origin'/);
  assert.match(src, /headers: authHeaders\(\)/);
  // the request is reachable only from the signed-in branch, after the two-character minimum
  const onInput = src.slice(src.indexOf('function onInput()'), src.indexOf('/* ---- keyboard'));
  assert.ok(onInput.indexOf('if (q.length < MIN)') < onInput.indexOf('if (!signedIn)'));
  assert.ok(onInput.indexOf('if (!signedIn)') < onInput.indexOf('ask(q)'));
  assert.match(onInput, /if \(!signedIn\) \{ renderTyped\(q, null, 'pages'\); return; \}/);
  // InfoDot's states: empty box, typed, loading line, no match, plus honest failure lines
  for (const text of ['Go to', 'Searching your account', 'Nothing matches', 'could not be searched just now', 'Your session has expired', 'to search your backtests, journal entries and strategies']) {
    assert.ok(read('src/header-search.js').includes(text) || read('src/app-header.js').includes(text), text);
  }
  // results are built as nodes: records are user text
  assert.doesNotMatch(src, /innerHTML/);
});

test('the runs table keeps every column on a phone: stacked, labelled cards instead of display:none', () => {
  const css = strip(read('src/home.css'));
  assert.doesNotMatch(css, /\.(col-win|col-dd|col-n|c-win|c-dd|c-n)[^{]*\{[^}]*display:\s*none/);
  assert.match(css, /td\[data-label\]::before/);
  assert.match(read('src/home.js'), /td\.dataset\.label = LABELS\[k\]/);
});

test('the equity curve of the module pages scales with its panel and needs no redraw', () => {
  const src = strip(read('src/results-renderer.js'));
  assert.match(src, /viewBox/);
  assert.match(src, /preserveAspectRatio/);
  assert.doesNotMatch(src, /clientWidth/);
});

test('the image lightbox traps nothing it cannot release: scroll lock, Escape, focus returns', () => {
  const src = read('src/main.js');
  assert.match(src, /dot-scroll-locked/);
  assert.match(src, /e\.key === 'Escape' && imageModal\.classList\.contains\('open'\)/);
  assert.match(src, /modalReturn\.focus\(\)/);
});

test('form controls are 16px on a phone (iOS does not zoom) and buttons are 44px tall', () => {
  const css = strip(read('src/instrument.css'));
  assert.match(css, /@media\(max-width:560px\)\{[\s\S]*input,select,textarea\{font-size:16px\}/);
  assert.match(css, /min-height:44px/);
});

test('the shared header and the search results use the instrument tokens (dark only)', () => {
  const css = strip(read('src/instrument.css'));
  for (const t of ['--dh-bg:var(--bg)', '--dh-accent:var(--signal)', '--dsr-panel:var(--panel)', '--dh-panel:var(--panel)']) assert.ok(css.includes(t), t);
  assert.doesNotMatch(css, /prefers-color-scheme:\s*light/);
});
