# ТЗ: Фронтенд-модуль KPI и отчетности CRM

## 1. Цель
Сделать production-ready frontend-модуль для KPI, отчетов, задач CRM, заморозки периодов и корректировок.

## 2. Роли и доступ
- `admin`, `superadmin`: полный доступ ко всем данным.
- `rop`, `branch_director`: доступ только к своему филиалу.
- `mop`: доступ только к своей группе.
- `agent`: доступ только к своим данным.

UI должен скрывать недоступные действия (например lock/adjust).

## 3. Страницы
1. `KPI Reports`
- фильтры: `period_type`, `date_from`, `date_to`, `branch_id`, `branch_group_id`, `user_id`
- таблица: сотрудник, роль, KPI, статус, метрики (`fact/target/progress`), lock

2. `Daily Report Form`
- ручные поля: `ad_count`, `meetings_count`, `comment`
- если период locked: read-only

3. `KPI Lock & Adjustments`
- lock периода
- корректировки
- история корректировок

4. `CRM Tasks`
- задачи + типы задач + валидации по типу

5. `Early Risk`
- карточки/алерты сотрудников с риском KPI (2 дня подряд < 0.8)

## 4. Endpoints
- `GET /api/kpi-reports`
- `GET /api/daily-reports`
- `POST /api/daily-reports`
- `PUT/PATCH /api/daily-reports/{id}`
- `GET /api/crm/task-types`
- `GET /api/crm/tasks`
- `POST /api/crm/tasks`
- `PUT/PATCH /api/crm/tasks/{id}`
- `POST /api/kpi-period-locks`
- `POST /api/kpi-adjustments`
- `GET /api/kpi-adjustments`
- `GET /api/notifications`

## 5. Примеры запросов и ответов

### 5.1 KPI reports
**Request**
```http
GET /api/kpi-reports?period_type=week&date_from=2026-05-04&date_to=2026-05-10&user_id=25
Authorization: Bearer <token>
```

**Response**
```json
{
  "filters": {
    "period_type": "week",
    "date_from": "2026-05-04",
    "date_to": "2026-05-10",
    "branch_id": null,
    "branch_group_id": null,
    "user_id": 25
  },
  "data": [
    {
      "period_type": "week",
      "period_key": "2026-05-04",
      "period_start": "2026-05-04",
      "period_end": "2026-05-10",
      "user": {
        "id": 25,
        "name": "Agent A",
        "role_slug": "agent",
        "branch_id": 1,
        "branch_group_id": 3
      },
      "kpi_value": 0.86,
      "status": "control",
      "is_locked": true,
      "locked_at": "2026-05-10 09:00:00",
      "metrics": {
        "calls_count": {
          "label": "Звонок",
          "fact_value": 132,
          "target_value": 180,
          "progress_pct": 73.33
        },
        "ad_count": {
          "label": "Реклама",
          "fact_value": 95,
          "target_value": 120,
          "progress_pct": 79.17
        }
      }
    }
  ]
}
```

### 5.2 Daily report create
**Request**
```http
POST /api/daily-reports
Authorization: Bearer <token>
Content-Type: application/json

{
  "report_date": "2026-05-09",
  "ad_count": 18,
  "meetings_count": 2,
  "comment": "Публикации на внешних площадках завершены"
}
```

**Response**
```json
{
  "id": 402,
  "user_id": 25,
  "report_date": "2026-05-09",
  "ad_count": 18,
  "meetings_count": 2,
  "calls_count": 27,
  "shows_count": 3,
  "new_clients_count": 4,
  "deposits_count": 1,
  "deals_count": 0,
  "comment": "Публикации на внешних площадках завершены",
  "submitted_at": "2026-05-10T08:31:11+05:00"
}
```

### 5.3 Lock period
**Request**
```http
POST /api/kpi-period-locks
Authorization: Bearer <token>
Content-Type: application/json

{
  "period_type": "week",
  "period_key": "2026-05-04",
  "branch_id": 1
}
```

**Response**
```json
{
  "id": 12,
  "period_type": "week",
  "period_key": "2026-05-04",
  "branch_id": 1,
  "branch_group_id": null,
  "locked_by": 4,
  "locked_at": "2026-05-10T09:00:00+05:00"
}
```

### 5.4 KPI adjustment
**Request**
```http
POST /api/kpi-adjustments
Authorization: Bearer <token>
Content-Type: application/json

{
  "period_type": "week",
  "period_key": "2026-05-04",
  "entity_id": 25,
  "field_name": "calls_count",
  "new_value": 150,
  "distribution_mode": "distribute_evenly",
  "reason": "Корректировка после сверки CRM"
}
```

**Response**
```json
{
  "id": 77,
  "period_type": "week",
  "period_key": "2026-05-04",
  "entity_id": 25,
  "field_name": "calls_count",
  "old_value": "132.0000",
  "new_value": "150.0000",
  "reason": "Корректировка после сверки CRM; mode=distribute_evenly; rows=6",
  "changed_by": 4,
  "changed_at": "2026-05-10T09:12:44+05:00"
}
```

### 5.5 Adjustment history
**Request**
```http
GET /api/kpi-adjustments?period_type=week&period_key=2026-05-04
Authorization: Bearer <token>
```

**Response**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 77,
      "period_type": "week",
      "period_key": "2026-05-04",
      "entity_id": 25,
      "field_name": "calls_count",
      "old_value": "132.0000",
      "new_value": "150.0000",
      "reason": "Корректировка после сверки CRM; mode=distribute_evenly; rows=6",
      "changed_by": 4,
      "changed_at": "2026-05-10T09:12:44+05:00"
    }
  ],
  "total": 1
}
```

### 5.6 Task types
**Request**
```http
GET /api/crm/task-types?is_kpi=1
Authorization: Bearer <token>
```

**Response**
```json
[
  { "id": 1, "code": "CALL", "name": "Звонок", "group": "kpi", "is_kpi": true },
  { "id": 2, "code": "LEAD_ACCEPT", "name": "Кабул", "group": "kpi", "is_kpi": true },
  { "id": 3, "code": "MEETING_OFFICE", "name": "Встреча в офисе", "group": "kpi", "is_kpi": true }
]
```

### 5.7 Create CRM task
**Request**
```http
POST /api/crm/tasks
Authorization: Bearer <token>
Content-Type: application/json

{
  "task_type_id": 1,
  "assignee_id": 25,
  "title": "Перезвонить клиенту",
  "status": "done",
  "result_code": "connected",
  "related_entity_type": "lead",
  "related_entity_id": 501,
  "completed_at": "2026-05-10T10:00:00+05:00"
}
```

**Response**
```json
{
  "id": 903,
  "task_type_id": 1,
  "assignee_id": 25,
  "title": "Перезвонить клиенту",
  "status": "done",
  "result_code": "connected",
  "related_entity_type": "lead",
  "related_entity_id": 501,
  "completed_at": "2026-05-10T10:00:00+05:00"
}
```

### 5.8 Notifications (early risk)
**Request**
```http
GET /api/notifications
Authorization: Bearer <token>
```

**Response fragment**
```json
{
  "data": [
    {
      "type": "kpi_early_risk",
      "title": "Ранний риск KPI",
      "body": "Ранний риск KPI: Agent A — два рабочих дня подряд ниже 0.8 (0.71 и 0.76).",
      "action_url": "/kpi-reports?period_type=day&user_id=25",
      "data": {
        "agent_id": 25,
        "kpi_day_latest": 0.71,
        "kpi_day_previous": 0.76
      }
    }
  ]
}
```

## 6. UX требования
- Skeleton loading для таблиц.
- Empty/error states.
- Progress bar по `progress_pct`.
- Цветовые статусы KPI.
- Read-only формы для locked period.

## 7. Архитектура фронта
- `api/` единый слой запросов.
- `modules/kpi/` страницы KPI.
- `modules/tasks/` страницы CRM задач.
- `modules/locks/` lock/adjustments.
- `shared/guards/roleGuard`.
- `shared/types/` интерфейсы ответов API.

## 8. Минимальные тесты
- Маппинг KPI response -> UI model.
- Валидация формы task по type-code.
- Поведение read-only при `is_locked=true`.

## 9. Определения
- `fact_value`: фактическое значение.
- `target_value`: плановое значение.
- `progress_pct`: процент выполнения (`fact / target * 100`).
- `distribution_mode`:
  - `set_first_day`
  - `distribute_evenly`
