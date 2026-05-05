# Backend Sync Prompts For KPI v2

Ниже готовые промпты для backend-команды. Каждый блок можно отправлять отдельно.

## Prompt 1: Daily KPI Contract (must-have)
Нужно реализовать/подтвердить контракт `GET /api/kpi/daily?v=2` и `POST /api/kpi/daily?v=2` для KPI v2.

Требования:
- Метрики только: `objects`, `shows`, `ads`, `calls`, `sales`.
- Для каждой метрики в `metrics` вернуть:
  - `fact_value`
  - `manual_value`
  - `final_value`
  - `target_value`
  - `progress_pct`
  - `source` (`system|manual|mixed`)
  - `source_error`
- В ответе вернуть `meta`:
  - `locked` (bool)
  - `quality` (object)
  - при наличии `period_type`, `period_key`, `date_from`, `date_to`.
- `POST /api/kpi/daily` должен быть идемпотентным по `date + employee_id`.
- Ошибки в формате:
```json
{ "code": "...", "message": "...", "details": {}, "trace_id": "..." }
```
- Коды ошибок:
  - `KPI_FORBIDDEN_SCOPE`
  - `KPI_FORBIDDEN_LOCKED_PERIOD`
  - `KPI_FORBIDDEN_DEADLINE_PASSED`
  - `KPI_FINALIZED_EDIT_FORBIDDEN`
  - `KPI_VALIDATION_FAILED`

## Prompt 2: Weekly/Monthly KPI Contract + fractional sales
Нужно реализовать/подтвердить `GET /api/kpi/weekly?v=2` и `GET /api/kpi/monthly?v=2`.

Требования:
- Агрегация по метрикам `objects/shows/ads/calls/sales`.
- Для `sales` вернуть:
  - `sales_count_raw` (decimal точный)
  - `sales_count_display` (округленный для UI)
- `average_kpi_percent` считать после суммирования по периоду.
- В `meta` вернуть `locked`, `quality`.
- Опционально `breakdown_by_day` с `metrics` в том же формате, что day.

## Prompt 3: Personal KPI plans with periods
Нужно реализовать персональные планы KPI:
- `GET /api/kpi/plans?user_id={id}&date=YYYY-MM-DD`
- `PATCH /api/kpi/plans/{user_id}`

Требования:
- План хранится по сотруднику, не по роли.
- Поля:
  - `user_id`
  - `metric` (`objects|shows|ads|calls|sales`)
  - `daily_plan`
  - `weight`
  - `effective_from`
  - `effective_to`
  - `comment`
- При конфликте периодов вернуть `409 KPI_PLAN_PERIOD_CONFLICT`.
- При отсутствии персонального плана можно вернуть fallback-план, но явно помечайте источник.

## Prompt 4: Sales attribution (1..3 agents)
Нужно реализовать в KPI расчет продаж по атрибуции продавцов.

Требования:
- Источник №1: `property_agent_sales`.
- Fallback №2: `properties.agent_id` только если по объекту нет записей в `property_agent_sales`.
- Ограничения:
  - max 3 агента
  - unique `agent_id`
  - >3 -> `422`
- Расчет вклада:
  - 1 агент: `1.0`
  - 2 агента: `0.5`
  - 3 агента: `0.3333...`
- Продажа учитывается по `sold_at` и статусам `sold|sold_by_owner|rented`.
- При изменении состава агентов нужен идемпотентный пересчет KPI за дату `sold_at`.

## Prompt 5: Adjustments/locked period alignment
Нужно синхронизировать корректировки KPI под v2-метрики.

Требования:
- `field_name` принимать только:
  - `objects`
  - `shows`
  - `ads`
  - `calls`
  - `sales`
- Корректировки в locked/finalized периодах логировать с `reason`, `changed_by`, `changed_at`.
- Чтение истории корректировок должно поддерживать фильтры `period_type`, `period_key`, `entity_id`.

## Prompt 6: Finalized/SLA enforcement
Нужно применить правила доступа к редактированию отчета:
- Дедлайн сабмита: `23:59 Asia/Dushanbe`.
- После дедлайна агент и MOP не редактируют.
- ROP/branch director/admin/superadmin редактируют в своем scope.
- Если `is_finalized=true`, ограничить редактирование по ролям и вернуть `KPI_FINALIZED_EDIT_FORBIDDEN`.

## Prompt 7: Daily Reports manual fields alignment (critical contract gap)
Нужно синхронизировать `daily-reports` с новым KPI ТЗ по ручным метрикам.

Проблема сейчас:
- Текущий контракт `daily-reports` использует ручные поля `ad_count` и `meetings_count`.
- По новому ТЗ ручные поля должны быть: `ads` и `calls`.

Требуется:
- Для `GET /api/daily-reports/status` и `GET /api/daily-reports/my/{date}` вернуть manual-поля в v2-формате:
  - `ads_count` (или `ads`)
  - `calls_count` (или `calls`)
  - `comment`
  - `plans_for_tomorrow` (если остается в продукте)
- Для `POST /api/daily-reports` принимать ручные поля `ads` и `calls` (либо `ads_count`/`calls_count`) как канонический формат v2.
- Если нужен переходный период, можно временно поддержать alias (`ad_count`, `meetings_count`), но в ответах v2 отдавать только канонический формат.
- Добавить бизнес-валидацию и ошибки в едином формате с `code/message/details/trace_id`.

## Prompt 8: KPI filters must use branch_group_id (scope consistency)
Нужно унифицировать фильтрацию KPI API по группе через `branch_group_id` (id), а не через имя группы.

Требования:
- Поддержать `branch_group_id` в:
  - `GET /api/kpi/daily?v=2`
  - `GET /api/kpi/weekly?v=2`
  - `GET /api/kpi/monthly?v=2`
  - `GET /api/kpi/dashboard?v=2`
- Если пока используется старый `group` (string name), оставить его как временный alias, но приоритет у `branch_group_id`.
- Проверка RBAC scope должна идти по id группы/филиала.

## Prompt 9: KPI OPS metric_key normalization
Нужно унифицировать `metric_key` в KPI Ops/Quality/Contract endpoint'ах под KPI v2.

Требования:
- В endpoint'ах:
  - `/api/kpi/period-contract`
  - `/api/kpi/quality/issues`
  - `/api/kpi/early-risk-alerts`
  - `/api/kpi/acceptance-runs` (если возвращает metric breakdown)
- Использовать только ключи:
  - `objects`
  - `shows`
  - `ads`
  - `calls`
  - `sales`
- Если backend временно хранит legacy ключи, вернуть в API v2 уже нормализованные значения.
- Добавить признак версии/совместимости в ответ (`v=2`/header), чтобы frontend мог безопасно отключать legacy fallback.

## Prompt 10: Strict KPI v2 contract (remove legacy aliases)
Нужно подготовить backend к strict-режиму фронта (`NEXT_PUBLIC_KPI_STRICT_V2=true`).

Требования:
- В ответах KPI v2 не использовать legacy alias-поля метрик:
  - запретить выдачу только как `kabul/show/call/deal/advertisement/deposit/lead`
  - выдавать канонические поля `objects/shows/ads/calls/sales`
- Внутри `metrics` использовать канонические ключи и структуру v2.
- Для sales отдавать `sales_count_raw` + `sales_count_display` в weekly/monthly/dashboard.
- После включения strict-режима фронт должен работать без fallback-маппинга.
