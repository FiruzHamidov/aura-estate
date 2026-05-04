# ТЗ: Модуль задач (CRM + Показы + KPI)

## 1. Цель
Сделать единый модуль задач, который:
- покрывает операционную работу CRM,
- связан с показами и объявлениями,
- используется для расчета KPI.

## 2. Справочник типов задач (`task_types`)
Типы делятся на группы:

### 2.1 KPI-типы
- `CALL` — звонок
- `LEAD_ACCEPT` — кабул
- `MEETING_OFFICE` — встреча в офисе
- `SHOWING` — показ
- `DEPOSIT` — залог
- `DEAL_CLOSED` — сделка
- `AD_PUBLICATION` — публикация рекламы

### 2.2 CRM-процесс
- `FOLLOW_UP`
- `DOCUMENT_REQUEST`
- `CONTRACT_PREP`
- `CLIENT_QUALIFICATION`
- `REASSIGNMENT`

### 2.3 Объявления
- `AD_CREATE`
- `AD_EDIT`
- `AD_PUBLISH`
- `AD_REFRESH`
- `AD_ARCHIVE`

### 2.4 Показ/объект
- `SHOWING_PLAN`
- `SHOWING_CONFIRM`
- `SHOWING_COMPLETE`
- `SHOWING_CANCEL`
- `POST_SHOWING_FEEDBACK`

## 3. Модель задачи
Обязательные поля задачи:
- `task_id`
- `task_type_id`
- `task_type_code`
- `title`
- `description`
- `assignee_id`
- `creator_id`
- `status` (`new|in_progress|done|canceled|overdue`)
- `result_code`
- `related_entity_type` (`lead|client|deal|property|ad|showing`)
- `related_entity_id`
- `due_at`
- `completed_at`
- `source` (`manual|system|integration`)

## 4. Обязательные связи задач с сущностями
- `SHOWING_*`, `SHOWING`, `MEETING_OFFICE` -> обязательно `related_entity_type=showing|property` + `related_entity_id`.
- `AD_*`, `AD_PUBLICATION` -> обязательно `related_entity_type=ad` + `related_entity_id`.
- `CALL`, `LEAD_ACCEPT`, `FOLLOW_UP`, `DOCUMENT_REQUEST` -> обязательно `related_entity_type=lead|client|deal` + `related_entity_id`.

## 5. Правила для KPI
В KPI учитываются только задачи нужного типа со `status=done`.

Ключевые правила:
- `Звонок` считается только по задачам `CALL`.
- Для `CALL` при `status=done` обязательны `result_code` и `completed_at`.
- `canceled` и невыполненные задачи в KPI не участвуют.

## 6. Примеры API

### 6.1 Получить типы задач
```http
GET /api/crm/task-types
Authorization: Bearer <token>
```

Ответ:
```json
[
  {"id":1,"code":"CALL","name":"Звонок","group":"kpi","is_kpi":true},
  {"id":8,"code":"FOLLOW_UP","name":"Фоллоу-ап","group":"crm","is_kpi":false}
]
```

### 6.2 Создать задачу звонка (валидный кейс)
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

### 6.3 Ошибка валидации по рекламе (неверная связь)
```http
POST /api/crm/tasks
Authorization: Bearer <token>
Content-Type: application/json

{
  "task_type_id": 12,
  "assignee_id": 25,
  "title": "Создать объявление",
  "related_entity_type": "lead",
  "related_entity_id": 999
}
```

Ожидаемо: `422` (для `AD_*` нужен `related_entity_type=ad`).

## 7. Фронтенд-требования
- Форма задачи должна быть динамической по `task_type_code`.
- Нельзя дать отправить форму, если не выполнены type-specific правила.
- В таблице задач показывать:
  - тип, статус, исполнитель,
  - связь с сущностью,
  - дедлайн/выполнение,
  - признак `участвует в KPI`.

## 8. Роли
- `admin`, `superadmin`: все задачи.
- `rop`, `branch_director`: задачи своего филиала.
- `mop`: задачи своей группы.
- `agent`: свои задачи.

## 9. Минимальные фронт-тесты
- Валидация `CALL + done` требует `result_code` и `completed_at`.
- Валидация `AD_*` требует `related_entity_type=ad`.
- Валидация `SHOWING_*` требует `showing|property`.
- Маппинг задачи в KPI-участие (только `done` + KPI-тип).
