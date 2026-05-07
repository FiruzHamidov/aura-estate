# ТЗ: Контроль KPI сотрудников для МОП / РОП+ (на базе реализованного daily KPI API)

## 1. Цель
Построить управленческий контур, в котором:
- `mop` ежедневно контролирует KPI агентов своей группы;
- `rop/branch_director/admin/superadmin` контролируют агент + mop по своему scope;
- контроль приводит не только к просмотру цифр, но и к управленческим действиям: корректировка, комментарий, план на завтра, эскалация риска.

Основной принцип: "один день — один отчет — одно управленческое решение".

---

## 2. Что уже есть (as-is)
Реализовано в backend:
- strict daily KPI report (5 ключей):
  - `objects`, `shows`, `ads`, `calls`, `sales`
- self API:
  - `GET /api/kpi/daily/my-report`
  - `POST /api/kpi/daily/my-report`
- scope supervisor API:
  - `GET /api/kpi/daily/report?date&employee_id`
  - `PATCH /api/kpi/daily/report`
- RBAC/scope проверки и коды:
  - `KPI_FORBIDDEN_SCOPE`
  - `KPI_FORBIDDEN_ROLE_ACTION`
  - `KPI_FORBIDDEN_LOCKED_PERIOD`
  - `KPI_FORBIDDEN_DEADLINE_PASSED`
- audit supervisor edit:
  - `updated_by`, `updated_by_role`, `updated_reason`, `edit_source`, `updated_at`

---

## 3. Целевая модель контроля (to-be)

### 3.1 Уровни контроля
1. Операционный (MOP):
- ежедневный контроль своей группы;
- фокус на `ads/calls` дисциплину + комментарии/план на завтра.

2. Тактический (ROP/Branch Director):
- контроль branch-среза: агенты + mop;
- выявление отклонений тренда и кадровых/процессных рисков.

3. Административный (Admin/Superadmin):
- кросс-branch контроль качества данных и управленческой дисциплины;
- выборочный аудит изменений supervisor-правок.

### 3.2 Управленческий цикл (ежедневный)
1. Сбор фактов (до дедлайна).
2. Контроль отклонений (после сабмита/на утро следующего дня).
3. Корректирующее действие (supervisor edit + reason).
4. План/обязательство на завтра.
5. Эскалация (если риск повторяется N дней подряд).

---

## 4. Функциональные требования для контроля

### 4.1 Supervisor workspace (frontend module)
Экран "Контроль KPI" для `mop/rop+`:
- фильтры:
  - дата,
  - сотрудник,
  - роль сотрудника (`agent|mop`),
  - статус (`submitted`, `not_submitted`, `locked`, `needs_attention`)
- таблица сотрудников scope с колонками:
  - сотрудник,
  - роль,
  - objects/shows/ads/calls/sales (fact vs target),
  - submitted,
  - editable,
  - last update audit (кто/когда)
- drill-down карточка сотрудника:
  - `GET /api/kpi/daily/report`
  - manual блок + комментарий + план
  - история последней supervisor правки (минимум)

### 4.2 Supervisor edit policy
`PATCH /api/kpi/daily/report` разрешен только:
- MOP -> agent своей группы
- ROP+ -> agent + mop в scope

Обязательные правила:
- при ручной коррекции supervisor должен указывать `updated_reason` (в UI сделать required для supervisor режима);
- `edit_source` выставлять стабильно (например `manager_panel`).

### 4.3 Контроль качества данных
Нужны авто-флаги "needs_attention":
- `ads=0` и `calls=0` при наличии активности по auto-метрикам;
- пустой `plans_for_tomorrow` у сотрудника 2+ дня подряд;
- резкое падение `calls` или `shows` относительно медианы 7 дней;
- частые supervisor-правки одного сотрудника (сигнал проблем учета).

---

## 5. Нефункциональные требования

### 5.1 SLA
- MOP: закрывает контроль группы до `11:00` следующего дня.
- ROP: закрывает контроль филиала до `13:00`.

### 5.2 Производительность
- список сотрудников по дате должен грузиться <= 2 сек для 200 сотрудников;
- пагинация обязательна для branch-wide списков.

### 5.3 Аудит/прозрачность
- любая supervisor правка трассируется audit-полями;
- для спорных кейсов должна быть доступна выборка: "кто чаще всего правит и кого".

---

## 6. RBAC и scope (обязательная матрица)

1. `agent`
- read: только self
- update: только self endpoint

2. `mop`
- read: агенты своей группы
- update: агенты своей группы
- read/update mop: запрещено

3. `rop`, `branch_director`
- read: agent + mop своего branch
- update: agent + mop своего branch

4. `admin`, `superadmin`
- read/update: agent + mop глобально

Нарушение scope -> `403 KPI_FORBIDDEN_SCOPE`.

---

## 7. UI/UX регламент контроля

### 7.1 Цветовая индикация
- Зеленый: >= 100% target по ключевым метрикам дня
- Желтый: 70-99%
- Красный: < 70% или нет сабмита

### 7.2 Action buttons
- `Просмотр` — всегда при доступе
- `Корректировать` — только если `editable=true`
- `Эскалировать` — если "красный" 2+ дня подряд

### 7.3 Обязательные поля при supervisor edit
- `ads`, `calls`
- `updated_reason` (required)
- `comment`/`plans_for_tomorrow` — рекомендовано, но не hard-required

---

## 8. Рекомендуемые API расширения (next iteration)

### 8.1 Список отчетов по scope за день
Добавить endpoint:
- `GET /api/kpi/daily/reports?date=YYYY-MM-DD&role=&submitted=&page=&per_page=`

Ответ:
- массив сотрудников scope c compact KPI и `editable`
- агрегаты по branch/group:
  - `% submitted`, avg progress, count risk

### 8.2 История корректировок
Добавить endpoint:
- `GET /api/kpi/daily/report/audit?employee_id=&date_from=&date_to=`

Чтобы руководитель видел историю правок и причины.

### 8.3 KPI alerts
Добавить endpoint или cron-driven notifications:
- напоминания руководителю о сотрудниках без сабмита
- алерты о повторном риске

---

## 9. Метрики эффективности самого контроля (manager KPI)

Для MOP/ROP считать отдельно:
- % сотрудников с submitted daily report до дедлайна;
- % отчетов без supervisor correction;
- среднее время закрытия контроля (от 00:00 следующего дня);
- доля сотрудников с улучшением week-over-week по calls/shows/sales;
- количество повторных "красных" кейсов.

---

## 10. Этапы внедрения

### Этап 1 (быстрый, 1 спринт)
- внедрить supervisor workspace на текущих endpoints;
- enforce `updated_reason` на UI;
- базовые фильтры и таблица контроля.

### Этап 2
- endpoint списка scope-отчетов за день;
- риск-флаги и простая эскалация;
- базовый аудит-экран.

### Этап 3
- продвинутая аналитика контроля MOP/ROP;
- автоматические уведомления и SLA monitoring.

---

## 11. Acceptance criteria
1. MOP видит и редактирует только агентов своей группы.
2. ROP+ видит и редактирует agent+mop только в своем scope.
3. При scope нарушении фронт стабильно получает `403 KPI_FORBIDDEN_SCOPE`.
4. При lock/deadline нарушении фронт получает соответствующие 403 коды.
5. Каждая supervisor правка содержит аудит (кто/роль/когда/причина/источник).
6. UI умеет корректно переключать режим редактирования по `editable`.
7. Контрольный процесс закрывается по SLA (MOP до 11:00, ROP до 13:00).

---

## 12. Риски
- Ручные правки без причины снижают доверие к данным (смягчение: required reason).
- Отсутствие списка scope-отчетов как отдельного endpoint усложняет масштабирование UI.
- Без risk-alert механизма руководитель может пропускать системные отклонения.

