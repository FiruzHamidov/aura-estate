/*
 * Usage:
 *   KPI_BASE_URL=https://crm.example.com KPI_TOKEN=... node scripts/kpi-load-test.mjs
 * Optional: KPI_BRANCH_IDS=1,2,3 KPI_CONCURRENCY=8 KPI_REQUESTS=80
 */
const baseUrl = process.env.KPI_BASE_URL;
const token = process.env.KPI_TOKEN;
if (!baseUrl || !token) throw new Error('KPI_BASE_URL and KPI_TOKEN are required');

const branches = (process.env.KPI_BRANCH_IDS || '1').split(',').map(Number);
const concurrency = Number(process.env.KPI_CONCURRENCY || 8);
const requests = Number(process.env.KPI_REQUESTS || 80);
const today = new Date();
const year = today.getUTCFullYear();
const month = today.getUTCMonth() + 1;
const week = Math.max(1, Math.ceil((((today - new Date(Date.UTC(year, 0, 1))) / 86400000) + 1) / 7));
const cases = branches.flatMap((branchId) => [
  `/api/kpi/weekly?year=${year}&week=${week}&branch_id=${branchId}&v=2&per_page=50&include_breakdown=0`,
  `/api/kpi/monthly?year=${year}&month=${month}&branch_id=${branchId}&v=2&per_page=50&include_breakdown=0`,
]);

const samples = [];
let cursor = 0;
async function worker() {
  while (cursor < requests) {
    const path = cases[cursor++ % cases.length];
    const started = performance.now();
    const response = await fetch(baseUrl + path, {headers: {Authorization: `Bearer ${token}`}});
    const body = await response.arrayBuffer();
    samples.push({path: path.split('?')[0], status: response.status, ms: performance.now() - started, bytes: body.byteLength});
  }
}
await Promise.all(Array.from({length: concurrency}, worker));
for (const [path, rows] of Object.entries(Object.groupBy(samples, row => row.path))) {
  const ms = rows.map(row => row.ms).sort((a, b) => a - b);
  const pct = (n) => ms[Math.min(ms.length - 1, Math.ceil(ms.length * n) - 1)];
  console.log(JSON.stringify({path, requests: rows.length, p50_ms: +pct(.50).toFixed(1), p95_ms: +pct(.95).toFixed(1), max_ms: +pct(1).toFixed(1), avg_bytes: Math.round(rows.reduce((sum, row) => sum + row.bytes, 0) / rows.length), errors: rows.filter(row => row.status >= 400).length}));
}
