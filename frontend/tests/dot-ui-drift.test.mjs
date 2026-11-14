// Drift guard for the shared dot-ui files in a static (vanilla JS) host. `dot-ui/sync.sh <repo> --stack static` copies the
// shared files and writes MANIFEST.sha256 (`sha256sum` format: sha256 and path of every copied file, plus the --src / --public folders it used). This
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
for (const line of fs.readFileSync(path.join(root, manifestPath), 'utf8').split(/\r?\n/)) {
  let m;
  if ((m = line.match(/^# version (\S+)$/))) manifest.version = m[1];
  else if ((m = line.match(/^# roots src=(\S+) public=(\S+)$/))) manifest.roots = { src: m[1], public: m[2] };
  else if ((m = line.match(/^([0-9a-f]{64})  (.+)$/))) manifest.files[m[2]] = m[1];
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
  const extra = [...walk(roots.src + '/dot-ui'), ...walk(roots.public + '/dot-ui')].filter((f) => !listed.has(f) && !f.endsWith('/MANIFEST.sha256'));
  assert.deepEqual(extra, [], 'Files in shared dot-ui folders that the manifest does not list');
});
