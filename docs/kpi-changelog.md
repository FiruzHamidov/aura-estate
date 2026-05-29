# KPI Backend Changelog (2026-05-04)

## Implemented routes
- `GET /api/kpi-plans`
- `PATCH /api/kpi-plans`
- `GET /api/kpi/daily`
- `POST /api/kpi/daily`
- `GET /api/kpi/weekly`
- `GET /api/kpi/monthly`
- `GET /api/kpi/dashboard`
- `GET /api/kpi/metric-mapping`
- `GET /api/kpi/integrations/status`
- `GET /api/kpi/telegram-reports/config`
- `PATCH /api/kpi/telegram-reports/config`
- `GET /api/kpi/quality/issues`
- `GET /api/kpi/early-risk-alerts`
- `PATCH /api/kpi/early-risk-alerts/status`
- `GET /api/kpi/period-contract`
- `GET /api/kpi/acceptance-runs`
- `GET /api/kpi/adjustments`
- `POST /api/kpi/adjustments`
- `GET /api/crm/tasks/kpi-daily-summary`
- `GET /api/crm/tasks/kpi-weekly-summary`

## Fallback/legacy mapping
- `GET /api/kpi/ops/integrations/status` -> `GET /api/kpi/integrations/status`
- `GET /api/kpi/ops/telegram/config` -> `GET /api/kpi/telegram-reports/config`
- `PATCH /api/kpi/ops/telegram/config` -> `PATCH /api/kpi/telegram-reports/config`
- `GET /api/kpi/ops/quality/issues` -> `GET /api/kpi/quality/issues`
- `GET /api/kpi/ops/early-risk-alerts` -> `GET /api/kpi/early-risk-alerts`
- `PATCH /api/kpi/ops/early-risk-alerts/status` -> `PATCH /api/kpi/early-risk-alerts/status`
- `GET /api/kpi/ops/period-contract` -> `GET /api/kpi/period-contract`
- `GET /api/kpi/ops/acceptance-runs` -> `GET /api/kpi/acceptance-runs`
- `GET /api/kpi-adjustments` -> `GET /api/kpi/adjustments`
- `POST /api/kpi-adjustments` -> `POST /api/kpi/adjustments`

## Validation + error format
- Validation: `422` with `{ message, errors, trace_id }`
- Auth: `401` with `{ message, trace_id }`
- Access: `403` with `{ message, trace_id }`
- Not found: `404` with `{ message, trace_id }`
- Server: `500` with `{ message, trace_id }`

## Traceability
- Added API middleware: `X-Trace-Id` input/output propagation.

## DB additions
- `kpi_plans`
- `kpi_integration_statuses`
- `kpi_telegram_report_configs`
- `kpi_quality_issues`
- `kpi_early_risk_alerts`
- `kpi_acceptance_runs`

## Seeds
- Added `KpiModuleSeeder` to `DatabaseSeeder`.

## OpenAPI
- Added `/docs/kpi-openapi.yaml`.

## KPI v2 contract (2026-05-05)
- Added opt-in contract by query/header: `?v=2` or `X-KPI-Version: 2` for:
- `GET /api/kpi/daily`
- `GET /api/kpi/weekly`
- `GET /api/kpi/monthly`
- New `metrics` keys: `advertisement`, `call`, `kabul`, `show`, `lead`, `deposit`, `deal`.
- Each metric now includes: `fact_value`, `manual_value`, `final_value`, `target_value`, `progress_pct`, `source`, `source_error`.
- Added `meta.quality` with: `duplicate_check_passed`, `completeness_pct`, `source_error`.
- Added `breakdown_by_day` for weekly/monthly `v2` response.

## KPI monthly formula update (2026-05-29)
- Formula for `kpi_percent` in `/api/kpi/monthly?v=2` is now explicit:
- Metrics: `objects`, `shows`, `ads`, `calls`, `sales`.
- Base score: `SUM(metric_progress * weight)` where `metric_progress = final_value / target_value`.
- Per-metric cap is enabled: `metric_progress` is capped at `100%` before applying weight (`cap_metric_progress_at_100 = true`).
- Hard gate is enabled for critical metrics: if `calls` or `ads` are below configured thresholds, final KPI is capped by `hard_gate.max_kpi_percent`.
- Status mapping remains threshold-based (`done/control/weak/risk/urgent`), with additional guard: critical underperformance (`calls`/`ads`) cannot return `done`.
- Added debug-friendly breakdown fields to each metric:
- `target_value`, `final_value`, `progress_pct`, `weight_used`, `contribution_pct`.
- Added row-level trace payload: `kpi_trace` with formula version, cap/gate info, per-metric contributions, raw/final KPI percent.
