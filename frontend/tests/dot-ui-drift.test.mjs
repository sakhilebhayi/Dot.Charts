// Drift guard for the shared dot-ui files in a static (vanilla JS) host. `dot-ui/sync.sh <repo> --stack static` copies the
// shared files and writes MANIFEST.sha256 (`sha256sum` format: only "<hash>  <path>" lines) and ROOTS (the --src / --public folders it used) next to it. This
// test recomputes the hashes from the host's files and fails when one differs, is missing, or when an unlisted file
// sits in a shared folder. Fix a failure by running sync.sh again, never by editing a shared file here.
// Copied by sync.sh to <repo>/tests/dot-ui-drift.test.mjs (not hashed, so a formatter may touch it). Run: node --test.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const find = () => {
  for (const candidate of ['src/dot-ui/MANIFEST.sha256', 'dot-ui/MANIFEST.sha256', 'public/dot-ui/MANIFEST.sha256']) {
    if (fs.existsSync(path.join(root, candidate))) return candidate;
  }
  assert.fail('dot-ui MANIFEST.sha256 is missing: run dot-ui/sync.sh for this repository (--stack static).');
};
const manifestPath = find();
const manifest = { version: '', files: {}, roots: null };
const dir = path.dirname(manifestPath);
const sibling = (name) => (fs.existsSync(path.join(root, dir, name)) ? fs.readFileSync(path.join(root, dir, name), 'utf8').split(/\r?\n/) : []);
{
  const [commit = '', pkg = ''] = sibling('VERSION');
  manifest.version = (pkg + ' ' + commit).trim();
  const roots = Object.fromEntries(sibling('ROOTS').filter((l) => l.includes('=')).map((l) => l.split('=')));
  if (roots.src && roots.public) manifest.roots = { src: roots.src, public: roots.public };
}
for (const line of fs.readFileSync(path.join(root, manifestPath), 'utf8').split(/\r?\n/)) {
  const m = line.match(/^([0-9a-f]{64})  (.+)$/);
  if (m) manifest.files[m[2]] = m[1];
}
const walk = (dir) => (fs.existsSync(path.join(root, dir))
  ? fs.readdirSync(path.join(root, dir), { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(dir, e.name)) : [path.join(dir, e.name).split(path.sep).join('/')]))
  : []);

test('the manifest lists files', () => {
  assert.ok(Object.keys(manifest.files || {}).length > 0, 'MANIFEST.sha256 lists no files');
});

test('every shared file matches the manifest', () => {
  const drift = [];
  for (const [file, expected] of Object.entries(manifest.files)) {
    const abs = path.join(root, file);
    if (!fs.existsSync(abs)) { drift.push('missing: ' + file); continue; }
    if (crypto.createHash('sha256').update(fs.readFileSync(abs)).digest('hex') !== expected) drift.push('changed: ' + file);
  }
  assert.deepEqual(drift, [], 'Shared dot-ui files drifted from dot-ui ' + manifest.version + '. Re-run sync.sh.');
});

test('no unlisted file sits in a shared folder', () => {
  const listed = new Set(Object.keys(manifest.files));
  const roots = manifest.roots || { src: 'src', public: 'public' };
  const extra = [...walk(roots.src + '/dot-ui'), ...walk(roots.public + '/dot-ui')].filter((f) => !listed.has(f) && !/\/(MANIFEST\.sha256|VERSION|ROOTS)$/.test(f));
  assert.deepEqual(extra, [], 'Files in shared dot-ui folders that the manifest does not list');
});

test('VERSION names the commit and the package version, and the manifest is clean sha256sum input', () => {
  const [commit, pkg] = fs.readFileSync(path.join(root, path.dirname(manifestPath), 'VERSION'), 'utf8').split(/\r?\n/);
  assert.match(commit, /^([0-9a-f]{40}|unknown)$/);
  assert.match(pkg, /^\d+\.\d+\.\d+$/);
  for (const line of fs.readFileSync(path.join(root, manifestPath), 'utf8').trim().split(/\r?\n/)) assert.match(line, /^[0-9a-f]{64}  \S.*$/);
});
