import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

const advisory = 'https://github.com/advisories/GHSA-fc5q-39xc-vjpf';
const expiresAt = Date.parse('2026-11-25T00:00:00Z');
const reviewedLockDigest = '7ccbe23ce9bf961d65fac2000c70a340d7f00ab4154d89446b161737185c9e51';
const allowedCopies = new Map([
  ['node_modules/picomatch', '4.0.7'],
  ['node_modules/vite-plugin-full-reload/node_modules/picomatch', '2.3.2'],
]);
const blockingLevels = new Set(['moderate', 'high', 'critical']);

// This policy is deliberately tied to one reviewed lockfile and expires automatically.
// It permits the existing build-only copies, never a new or runtime dependency.
export function evaluateBuildAudit(report, lockBytes, now = Date.now()) {
  if (report?.error || report?.auditReportVersion !== 2 || !report.vulnerabilities || !report.metadata?.vulnerabilities) {
    throw new Error('Invalid or failed npm audit response');
  }
  const entries = report.vulnerabilities;
  const counts = { info: 0, low: 0, moderate: 0, high: 0, critical: 0 };
  for (const value of Object.values(entries)) {
    if (!(value.severity in counts)) throw new Error('Unknown audit severity');
    counts[value.severity]++;
  }
  for (const level of Object.keys(counts)) {
    if (counts[level] !== report.metadata.vulnerabilities[level]) throw new Error('Incomplete audit response');
  }
  const blocking = Object.keys(entries).filter(name => blockingLevels.has(entries[name].severity));
  if (!blocking.length) return { waived: [], blocked: [] };
  const lock = JSON.parse(lockBytes.toString());
  const exactLock = createHash('sha256').update(lockBytes).digest('hex') === reviewedLockDigest;
  function eligible(name, seen = new Set()) {
    const value = entries[name];
    if (!exactLock || !Number.isFinite(now) || now >= expiresAt || seen.has(name) || !value) return false;
    if (!Array.isArray(value.nodes) || !value.nodes.length || !Array.isArray(value.via) || !value.via.length) return false;
    if (!value.nodes.every(node => lock.packages?.[node]?.dev === true)) return false;
    const visited = new Set([...seen, name]);
    return value.via.every(cause => {
      if (typeof cause === 'string') return eligible(cause, visited);
      return name === 'picomatch' && cause?.name === 'picomatch' && cause?.url === advisory
        && value.nodes.every(node => allowedCopies.has(node) && lock.packages[node].version === allowedCopies.get(node));
    });
  }
  return { waived: blocking.filter(name => eligible(name)), blocked: blocking.filter(name => !eligible(name)) };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const audit = spawnSync('npm', ['audit', '--json'], { encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 });
    if (audit.error || audit.signal || ![0, 1].includes(audit.status)) throw new Error('npm audit could not complete');
    const result = evaluateBuildAudit(JSON.parse(audit.stdout), readFileSync(new URL('../package-lock.json', import.meta.url)));
    if (result.waived.length) console.warn(`Temporary build-only exception: ${advisory}; expires 2026-11-25 UTC; ${result.waived.join(', ')}`);
    if (result.blocked.length) throw new Error(`Audit blocked: ${result.blocked.join(', ')}`);
    console.log('Build dependency audit passed.');
  } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}
