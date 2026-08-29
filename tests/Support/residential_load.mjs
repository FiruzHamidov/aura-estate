// Read-only, loopback-only reproducible HTTP benchmark. Run against the isolated load fixture.
import { performance } from 'node:perf_hooks';
import { randomUUID } from 'node:crypto';
const base = new URL(process.argv[2] || 'http://127.0.0.1:8001/api/');
if (!['127.0.0.1', 'localhost'].includes(base.hostname) || base.username || base.password || base.protocol !== 'http:') throw new Error('Only a local isolated HTTP API is permitted.');
const profiles = [
  ['catalog', 'new-buildings?per_page=15'],
  ['filtered_catalog', 'new-buildings?rooms[]=2&price_max=900000&area_min=50&per_page=15'],
  ['units', 'new-buildings/1/units?rooms[]=2&price_max=900000&per_page=20'],
  ['detail', 'new-buildings/1?inventory=paginated'],
];
const results = [];
const auditRequests = [];
for (const [name, path] of profiles) {
  const sample = async () => {
    const traceId = randomUUID();
    const url = new URL(path, base);
    const start = performance.now();
    const response = await fetch(url, { signal: AbortSignal.timeout(15000), headers: { 'X-Trace-Id': traceId } });
    const body = await response.arrayBuffer();
    auditRequests.push({ trace_id: traceId, path: url.pathname.replace(/^\//, '') });
    if (response.headers.get('X-Trace-Id') !== traceId) throw new Error('Response trace does not match the benchmark request.');
    return { ms: performance.now() - start, bytes: body.byteLength, status: response.status };
  };
  await Promise.all(Array.from({ length: 20 }, sample));
  const samples = [];
  for (let batch = 0; batch < 5; batch++) samples.push(...await Promise.all(Array.from({ length: 20 }, sample)));
  const times = samples.map(row => row.ms).sort((a, b) => a - b);
  results.push({ profile: name, concurrency: 20, requests: samples.length, p50_ms: +times[49].toFixed(2), p95_ms: +times[94].toFixed(2), max_ms: +times[99].toFixed(2), max_bytes: Math.max(...samples.map(row => row.bytes)), failures: samples.filter(row => row.status !== 200).length });
}
console.log(JSON.stringify({ at: new Date().toISOString(), base: base.href, results, audit_requests: auditRequests }, null, 2));
if (results.some(row => row.failures || row.p95_ms > 500 || row.profile === 'detail' && row.max_bytes > 200 * 1024)) process.exitCode = 1;
