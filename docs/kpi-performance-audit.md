# KPI performance audit

## Root cause

The v2 weekly/monthly path paginated users, but then executed `autoMetrics()` for every user and every date in the period. One invocation performs independent lookups for tasks, bookings, clients, properties, and sales. For a page of 50 users this becomes approximately 2,100 source queries for a week and approximately 9,300 for a 31-day month, plus per-user plan and lock queries. This is a classic N+1-by-user-and-day pattern, not an external-service wait.

`include_breakdown=0` does not call the breakdown builder; before this change it still paid the unrelated auto-metric N+1 cost.

## Before → after query shape

| Concern | Before | After |
| --- | --- | --- |
| Auto facts | 5–6 SQL queries × employees × days | Up to four bounded range queries for the page, grouped by user/date in PHP; sales remains one batch query |
| KPI plans | 2–4 queries × employee | One active-plan query for all employees on the page |
| Period locks | one `exists` query × employee | one scoped-lock query |
| Daily reports | one page query | one page query (unchanged) |
| Data returned | all reports for only paginated employees | unchanged, pagination stays mandatory (`per_page`, max 200) |

The new source queries use `WHERE user_id IN (...) AND timestamp BETWEEN ...`; timestamp columns are not wrapped in SQL functions, preserving index use. Timezone conversion is done after retrieval.

## Added indexes

Migration `2026_07_17_120000_add_kpi_report_performance_indexes.php` adds indexes for KPI date/user reports, completed CRM tasks, booking start times, client/property creators and agents by creation time, sold properties, and plan lookup scopes.

## Observability and measurement

`/api/kpi/daily`, `/api/kpi/weekly`, and `/api/kpi/monthly` now log `kpi.performance` with total time, SQL time, non-SQL time, SQL query count, response bytes, and every SQL statement's duration. Use the request `trace_id` to correlate the log with the existing API request log.

Run the reproducible load measurement after deploying indexes:

```sh
KPI_BASE_URL=https://crm.example.com KPI_TOKEN=... KPI_BRANCH_IDS=1,2,3 KPI_CONCURRENCY=8 KPI_REQUESTS=80 node scripts/kpi-load-test.mjs
```

The script reports p50/p95, errors, and payload bytes for week and month across multiple branches. Record a baseline before deploying and the same run after deployment; accept normal branch/period pages when p95 is below 1,000 ms. This workspace has no PHP executable or configured database, so local wall-clock figures cannot be truthfully produced here.
