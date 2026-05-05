# Промпт для Frontend: KPI модуль (что и когда дергать)

Используй этот документ как рабочий prompt/контракт при интеграции KPI-экранов.

## 1) Базовые правила
- Все маршруты ниже под `auth:sanctum`.
- Таймзона бизнес-логики: `Asia/Dushanbe`.
- Для KPI v2 всегда передавай `?v=2` (или `X-KPI-Version: 2`).
- Для ошибок ориентируйся на JSON:
```json
{
  "code": "ERROR_CODE",
  "message": "...",
  "details": {},
  "trace_id": "..."
}
```

## 2) Ежедневный сценарий сотрудника (агент/стажер)

### Шаг A. Проверить статус обязательного отчета
`GET /api/daily-reports/status`

Когда дергать:
- при входе в CRM;
- при открытии KPI экрана;
- после успешного сабмита отчета.

Что использовать из ответа:
- `daily_report_required`
- `missing_report_date`
- `report_date`
- `submitted`
- `auto` (автополя)
- `manual` (сохраненные ручные поля)

### Шаг B. Показать форму на дату
`GET /api/daily-reports/my/{date}`

Когда дергать:
- при открытии формы за конкретный день;
- при переключении даты.

### Шаг C. Сохранить отчет
`POST /api/daily-reports`

Пример body:
```json
{
  "report_date": "2026-05-05",
  "ad_count": 12,
  "meetings_count": 2,
  "comment": "Отработал звонки",
  "plans_for_tomorrow": "5 показов"
}
```

Важно:
- `calls/shows/deals/sales` подтягиваются сервером автоматически;
- фронт отправляет только ручные поля + комментарии.

## 3) Редактирование отчета руководителем
`PUT /api/daily-reports/{id}` или `PATCH /api/daily-reports/{id}`

Когда дергать:
- при ручной корректировке отчета менеджером/ROP/админом.

Ожидаемые ошибки:
- `403 KPI_FORBIDDEN_LOCKED_PERIOD`
- `403 KPI_FORBIDDEN_DEADLINE_PASSED`
- `403 KPI_FINALIZED_EDIT_FORBIDDEN`

UI-реакция:
- показать read-only режим + текст причины из `message`.

## 4) KPI таблицы/дашборды

### KPI день
`GET /api/kpi/daily?date=2026-05-05&v=2`

### KPI неделя
`GET /api/kpi/weekly?year=2026&week=19&v=2`

### KPI месяц
`GET /api/kpi/monthly?year=2026&month=5&v=2`

Когда дергать:
- day/week/month tabs;
- при смене фильтров (branch/group/employee).

Что брать из ответа v2:
- `data[].metrics`
- `data[].kpi_percent`
- `data[].status`
- `data[].locked`
- `meta.quality`

## 5) KPI прогресс для “Мой KPI” виджета
`GET /api/kpi/daily/my-progress?date=2026-05-05`

Когда дергать:
- карточка на home/dashboard сотрудника.

## 6) KPI планы

### Получить планы
`GET /api/kpi-plans?role=agent`

### Обновить планы
`PATCH /api/kpi-plans`

Пример body:
```json
{
  "role": "agent",
  "items": [
    { "metric_key": "calls_count", "daily_plan": 30, "weight": 0.2, "comment": "calls" }
  ]
}
```

Когда дергать:
- только на экране управления KPI-планами (ROP/директор/admin/superadmin).

## 7) Продажа объекта с агентами-продавцами
`POST /api/properties/{property}/deal`

Ключевой body-фрагмент:
```json
{
  "moderation_status": "sold",
  "agents": [
    { "agent_id": 11, "role": "main" },
    { "agent_id": 15, "role": "assistant" }
  ]
}
```

Правила:
- максимум 3 агента;
- `agent_id` уникальные;
- если 3 агента — KPI продажа считается по `0.33` каждому (на backend).

Ожидаемые ошибки:
- `422` при `agents > 3` или невалидном payload.

## 8) Локи и корректировки

### Поставить lock периода
`POST /api/kpi-period-locks`

### Корректировки
- `POST /api/kpi-adjustments`
- `GET /api/kpi-adjustments`

Когда дергать:
- админские/управленческие экраны контроля KPI.

## 9) Рекомендованный порядок запросов для KPI страницы
1. `GET /api/daily-reports/status`
2. `GET /api/kpi/daily?date=...&v=2`
3. `GET /api/kpi/daily/my-progress?date=...` (для личного виджета)
4. По вкладкам: `weekly/monthly` v2
5. По действию “Сохранить отчет”: `POST /api/daily-reports` и затем refetch п.1+п.2

## 10) Что показывать в UI при ошибках
- `403 KPI_FORBIDDEN_SCOPE`: "Нет доступа к данным этого сотрудника/группы/филиала".
- `403 KPI_FORBIDDEN_LOCKED_PERIOD`: "Период закрыт для редактирования".
- `403 KPI_FORBIDDEN_DEADLINE_PASSED`: "Дедлайн сабмита за этот день истек".
- `403 KPI_FINALIZED_EDIT_FORBIDDEN`: "Данные финализированы и недоступны для вашей роли".
- `422 KPI_VALIDATION_FAILED`/валидация: подсветить конкретные поля формы.
- `409 KPI_PLAN_PERIOD_CONFLICT`: показать конфликт периода и предложить изменить даты.
