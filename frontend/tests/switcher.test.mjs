// Frontend checks for the shared Dot switcher (run: npm test, which is node --test). No browser, no dependencies.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (f) => fs.readFileSync(path.join(root, f), 'utf8');
const walk = (dir) => fs.readdirSync(path.join(root, dir), { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(dir, e.name)) : [path.join(dir, e.name)]));
const pages = ['index', 'home', 'backtest', 'history', 'journal', 'options', 'strategy-builder'];

test('every page with an app bar imports the one switcher mount module', () => {
  for (const p of pages) {
    assert.match(read(`${p === 'index' ? 'main' : p}.js`.replace(/^/, 'src/')), /import '\.\/dot-switcher-mount\.js';/, p);
    assert.match(read(`${p}.html`), /<header class="bar">/, p);
  }
});

test('no platform list is duplicated in the frontend: hosts appear only in registry.js (one fallback link)', () => {
  const files = [...walk('src').filter((f) => /\.(js|css)$/.test(f) && !f.startsWith('src/dot-ui')), ...fs.readdirSync(root).filter((f) => f.endsWith('.html'))];
  const hits = files.filter((f) => /\.infodot\.co\.za|https:\/\/infodot\.co\.za/.test(read(f)));
  assert.deepEqual(hits, ['src/registry.js']);
  for (const f of files) assert.doesNotMatch(read(f), /['"`]Dot\.(Agents|Analytics|Auction|Mines|Sheet|Tasks)['"`]/, f);
  assert.ok(!fs.existsSync(path.join(root, 'src/ecosystem.js')) || !/PLATFORMS\s*=/.test(read('src/ecosystem.js')));
  // the old strip with the in-JS list is gone, the old two-link nav on the landing too
  assert.doesNotMatch(read('home.html'), /aria-label="Dot ecosystem"|infodot\.co\.za/);
});

test('the mount module and the skin write no style attribute and load nothing external', () => {
  for (const f of ['src/dot-switcher-mount.js', 'src/registry.js', 'src/dot-switcher-skin.css', 'src/dot-ui/js/dot-switcher-static.js', 'src/dot-ui/js/dot-switcher.js', 'src/dot-ui/css/dot-switcher.css']) {
    const src = read(f).replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
    assert.doesNotMatch(src, /\.style\b|\sstyle\s*=|setAttribute\(\s*['"]style/, f);
    assert.doesNotMatch(src.replace(/https:\/\/infodot\.co\.za\/platforms|http:\/\/www\.w3\.org\/2000\/svg|https:\/\/infodot\.co\.za/g, ''), /https?:\/\/|@import\s+url/, f);
  }
});

// The copied dot-ui files are pinned by tests/dot-ui-drift.test.mjs (shipped by dot-ui/sync.sh --stack static).

test('the renderer builds the Charts model into a degraded, honest control', async () => {
  const S = await import('../src/dot-ui/js/dot-switcher-static.js');
  const model = S.buildModel({
    state: 'degraded', current: 'charts', infodot_url: 'https://infodot.co.za', notice: 'Could not check your platforms from here',
    platforms: [{ key: 'infodot', name: 'InfoDot' }, { key: 'files', name: 'Dot.Files' }, { key: 'docs', name: 'Dot.docs' }, { key: 'tasks', name: 'Dot.Tasks' }, { key: 'finance', name: 'Dot.Finance' }, { key: 'charts', name: 'Dot.Charts' }, { key: 'sheet', name: 'Dot.Sheet' }],
  });
  const html = S.renderInner(model, { logos: '/dot-ui/img/platforms' });
  assert.match(html, /Could not check your platforms from here\./);
  assert.match(html, /href="https:\/\/infodot\.co\.za\/go\/files"/);
  assert.doesNotMatch(html, /company=|Dot\.Sheet|Active/);
  assert.doesNotMatch(html, /\sstyle\s*=/);
});

test('every logo the switcher can print exists in the public path', () => {
  const m = JSON.parse(read('public/dot-ui/img/platforms/manifest.json')).platforms;
  for (const v of Object.values(m)) assert.ok(fs.existsSync(path.join(root, 'public/dot-ui/img/platforms', v.file)), v.file);
});

test('the skin carries no override of the shared filter focus (dot-ui guards it itself)', () => {
  assert.doesNotMatch(read('src/dot-switcher-skin.css'), /dsw-filter/);
});

test('the registry model passes the Charts wording and the renderer prints it', async () => {
  const S = await import('../src/dot-ui/js/dot-switcher-static.js');
  const model = S.buildModel({ state: 'degraded', current: 'charts', infodot_url: 'https://infodot.co.za', notice: 'Could not check your platforms from here', notice_detail: 'Dot.Charts has its own sign-in.', platforms: [{ key: 'charts', name: 'Dot.Charts' }] });
  const html = S.renderInner(model, { logos: '/dot-ui/img/platforms' });
  assert.match(html, /<strong>Could not check your platforms from here\.<\/strong> <span>Dot\.Charts has its own sign-in\.<\/span>/);
  assert.match(html, /aria-current="page"/);
});
