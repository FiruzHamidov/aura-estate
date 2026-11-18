import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { evaluateBuildAudit } from './check-build-audit.mjs';

const lock = readFileSync(new URL('../package-lock.json', import.meta.url));
const now = Date.parse('2026-11-18T00:00:00Z');
const source = { name: 'picomatch', url: 'https://github.com/advisories/GHSA-fc5q-39xc-vjpf' };
const fixture = () => ({ auditReportVersion: 2, metadata: { vulnerabilities: { info: 0, low: 0, moderate: 0, high: 3, critical: 0 } }, vulnerabilities: {
  picomatch: { severity: 'high', nodes: ['node_modules/picomatch', 'node_modules/vite-plugin-full-reload/node_modules/picomatch'], via: [source] },
  tinyglobby: { severity: 'high', nodes: ['node_modules/tinyglobby'], via: ['picomatch'] },
  vite: { severity: 'high', nodes: ['node_modules/vite'], via: ['tinyglobby'] },
} });

test('permits only the known build copies and their transitive findings during the exception window', () => {
  assert.deepEqual(evaluateBuildAudit(fixture(), lock, now).blocked, []);
  assert.equal(evaluateBuildAudit(fixture(), lock, now).waived.length, 3);
});
test('expires automatically on November 25', () => {
  assert.equal(evaluateBuildAudit(fixture(), lock, Date.parse('2026-11-25T00:00:00Z')).blocked.length, 3);
});
test('does not waive another advisory on the same package', () => {
  const report = fixture(); report.vulnerabilities.picomatch.via.push({ name: 'picomatch', url: 'https://github.com/advisories/GHSA-other' });
  assert.equal(evaluateBuildAudit(report, lock, now).blocked.length, 3);
});
test('does not waive runtime package paths', () => {
  const report = fixture(); report.vulnerabilities.vite.nodes = ['node_modules/leaflet'];
  assert.deepEqual(evaluateBuildAudit(report, lock, now).blocked, ['vite']);
});
test('does not waive new lockfiles or new copies', () => {
  assert.equal(evaluateBuildAudit(fixture(), Buffer.concat([lock, Buffer.from('\n')]), now).blocked.length, 3);
  const report = fixture(); report.vulnerabilities.picomatch.nodes.push('node_modules/other/node_modules/picomatch');
  assert.equal(evaluateBuildAudit(report, lock, now).blocked.length, 3);
});
test('rejects failed, incomplete and cyclic audit data', () => {
  assert.throws(() => evaluateBuildAudit({ error: {} }, lock, now));
  const report = fixture(); report.metadata.vulnerabilities.high = 4;
  assert.throws(() => evaluateBuildAudit(report, lock, now));
  const cyclic = fixture(); cyclic.vulnerabilities.picomatch.via = ['vite'];
  assert.equal(evaluateBuildAudit(cyclic, lock, now).blocked.length, 3);
});
test('a clean audit still passes after the exception expires', () => {
  const report = fixture(); report.vulnerabilities = {}; report.metadata.vulnerabilities.high = 0;
  assert.deepEqual(evaluateBuildAudit(report, lock, expires()), { waived: [], blocked: [] });
  function expires() { return Date.parse('2026-12-01T00:00:00Z'); }
});
