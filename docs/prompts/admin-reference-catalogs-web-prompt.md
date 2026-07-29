# Промпт: админский CRUD всех справочников Aura Estate

Ты senior frontend-разработчик. Реализуй в существующем web-приложении Aura Estate полноценный раздел администрирования справочников. Основной сценарий — управление типами недвижимости, но архитектура должна покрывать все перечисленные ниже справочники.

Работай внутри существующей архитектуры проекта: переиспользуй текущие layout, авторизацию, API client, UI-kit, уведомления, модальные окна, таблицы, формы и соглашения по роутингу. Не создавай второй дизайн-системы и не заменяй используемые библиотеки без необходимости.

Backend уже существует:

```dotenv
NEXT_PUBLIC_API_URL=https://backend.aura.tj/api
```

Все пути ниже относительны к `NEXT_PUBLIC_API_URL`. Для защищённых запросов используй существующую Sanctum-аутентификацию проекта. Не передавай токен через query string.

## Цель

Создай раздел:

```text
/admin/directories
```

В нём администратор должен:

- видеть все доступные справочники по категориям;
- открывать конкретный справочник;
- искать, фильтровать и просматривать записи;
- создавать запись;
- редактировать запись;
- удалять запись после подтверждения;
- видеть серверные ошибки валидации у соответствующих полей;
- понимать, почему запись нельзя удалить;
- работать с разделом на desktop, tablet и mobile.

Не создавай fallback-названия вида `Тип #12`. Показывай реальные значения из API.

## Доступ

Показывай раздел управления справочниками только ролям:

```text
admin
superadmin
```

Для справочников клиентов backend также разрешает роль `marketing`, но marketing должен видеть только:

- типы клиентов;
- типы потребностей;
- статусы потребностей;
- источники клиентов в read-only режиме.

Для `branch-groups` backend дополнительно применяет branch scope. Не пытайся расширить scope на frontend. Показывай только записи, которые вернул API.

При ответе:

- `401` — запускай существующий session-expired flow;
- `403` — показывай страницу «Недостаточно прав», не пустую таблицу;
- `409` — показывай текст конфликта из `message`;
- `422` — раскладывай `errors` по полям формы;
- `429` — показывай уведомление о слишком частых запросах;
- `5xx` или network error — сохраняй текущие данные и давай повторить запрос.

Frontend-проверка роли нужна для UX, но не должна считаться защитой API.

## Информационная архитектура

На `/admin/directories` покажи карточки или компактный список категорий.

### Недвижимость

- Типы недвижимости
- Статусы недвижимости
- Типы здания
- Типы парковки
- Типы отопления
- Типы ремонта
- Типы договоров
- Типы документов
- Локации и районы

### Организация

- Филиалы
- Группы филиалов
- Роли

### Новостройки

- Застройщики
- Особенности
- Теги
- Материалы
- Этапы строительства

### Клиенты

- Типы клиентов
- Источники клиентов
- Типы потребностей
- Статусы потребностей

Для каждой карточки покажи название, краткое описание и количество записей, если оно уже известно после загрузки. Не выполняй отдельный запрос только ради счётчика при первом открытии главной страницы.

Маршрут конкретного справочника:

```text
/admin/directories/[catalog]
```

Примеры:

```text
/admin/directories/property-types
/admin/directories/repair-types
/admin/directories/branches
```

Сохраняй выбранный справочник в URL. Поиск, страница и фильтры также должны быть отражены в query parameters там, где это уместно.

## Архитектура frontend

Не создавай отдельный почти одинаковый компонент для каждого простого справочника.

Сделай типизированный registry:

```ts
type CatalogDefinition<TItem, TForm> = {
  key: string;
  title: string;
  description: string;
  endpoint: string;
  responseKind: "array" | "paginated";
  permissions: {
    read: string[];
    write: string[];
  };
  columns: CatalogColumn<TItem>[];
  fields: CatalogField<TForm>[];
  filters?: CatalogFilter[];
  capabilities: {
    create: boolean;
    update: boolean;
    delete: boolean;
  };
};
```

Создай общие компоненты:

- `DirectoriesDashboard`;
- `CatalogPage`;
- `CatalogTable`;
- `CatalogToolbar`;
- `CatalogFormDrawer` или `CatalogFormDialog`;
- `DeleteCatalogItemDialog`;
- `CatalogEmptyState`;
- `CatalogErrorState`;
- `CatalogTableSkeleton`;
- `ServerValidationErrors`.

Для сложных сущностей используй custom form renderer:

- `BranchForm`;
- `BranchGroupForm`;
- `DeveloperForm`;
- `LocationForm`;
- `RoleForm`.

API client должен иметь единый интерфейс:

```ts
listCatalog(definition, params)
getCatalogItem(definition, id)
createCatalogItem(definition, payload)
updateCatalogItem(definition, id, payload)
deleteCatalogItem(definition, id)
```

Нормализуй два формата списка:

Обычный массив:

```json
[
  { "id": 1, "name": "Квартира" }
]
```

Laravel paginator:

```json
{
  "current_page": 1,
  "data": [],
  "last_page": 1,
  "per_page": 15,
  "total": 0
}
```

После нормализации UI должен получать:

```ts
type CatalogListResult<T> = {
  items: T[];
  pagination: {
    page: number;
    lastPage: number;
    perPage: number;
    total: number;
  } | null;
};
```

Не определяй формат ответа через ненадёжную проверку одного поля записи. Используй `responseKind` из registry и безопасную runtime-проверку ответа.

Если проект использует TanStack Query, применяй его. Иначе используй существующий механизм data fetching. Query key должен включать:

```ts
["admin-directory", catalogKey, search, filters, page, perPage]
```

После create/update/delete инвалидируй список текущего справочника и все зависимые select queries. Не делай optimistic delete: сервер может вернуть `409` из-за связанных записей.

## Общие требования к таблицам

В таблице должны быть:

- реальные колонки текущего справочника;
- поиск;
- количество записей;
- состояния loading, empty, error;
- кнопка «Добавить»;
- действия «Редактировать» и «Удалить»;
- серверная пагинация там, где API её возвращает;
- сохранение предыдущей страницы во время refetch;
- debounce поиска 300–500 мс;
- отмена устаревшего запроса через `AbortSignal`;
- адаптивное отображение строк карточками на узких экранах.

Не показывай `created_at` и `updated_at` как главные колонки, но их можно разместить во вторичной информации.

Удаление:

1. реализовать проверку использования и замену по отдельному промпту `docs/prompts/admin-reference-catalog-merge-web-prompt.md`;
2. открыть confirm dialog и вызвать `GET /admin/catalogs/{catalog}/{id}/usage`;
3. при отсутствии связей показать обычное удаление;
4. при наличии связей показать количество, breakdown и обязательный выбор замены;
5. заблокировать повторное нажатие во время запроса;
6. закрыть окно только после успеха;
7. при `409` оставить окно открытым и показать серверное объяснение.

## Формы

Используй существующие form-библиотеки проекта. Если уже используются React Hook Form и Zod — продолжай использовать их.

Общие правила:

- `name` обрезать через `trim`;
- пустые nullable-поля отправлять как `null`, а не как случайную пустую строку;
- slug хранить в lowercase kebab-case;
- при создании предлагать slug из name, но разрешать исправить его вручную;
- после ручного редактирования slug не перезаписывать его при изменении name;
- не изменять данные формы после серверной ошибки;
- серверные ошибки Laravel вида `errors.field[]` показывать под полем;
- на успешное действие показывать toast;
- предупреждать о несохранённых изменениях при закрытии формы.

## Реальные API и поля

### 1. Типы недвижимости

Endpoint:

```text
GET    /property-types
GET    /property-types/{id}
POST   /property-types
PATCH  /property-types/{id}
DELETE /property-types/{id}
```

Формат списка: `array`.

Поля:

```ts
{
  name: string; // required, unique
  slug: string; // required, unique
}
```

Это основной справочник. Сделай его первым в категории и обеспечь полный CRUD.

### 2. Статусы недвижимости

Endpoint: `/property-statuses`.

Формат списка: `array`.

Поля:

```ts
{
  name: string; // required, unique
  slug: string; // required, unique
}
```

### 3. Типы здания

Endpoint: `/building-types`.

Формат списка: `array`.

Поля:

```ts
{ name: string }
```

На update backend требует `name`.

### 4. Типы парковки

Endpoint: `/parking-types`.

Формат списка: `array`.

Поля:

```ts
{ name: string }
```

### 5. Типы отопления

Endpoint: `/heating-types`.

Формат списка: `array`.

Поля:

```ts
{ name: string }
```

### 6. Типы ремонта

Endpoint: `/repair-types`.

Формат списка: `array`.

Поля:

```ts
{ name: string } // required, unique
```

### 7. Типы договоров

Endpoint: `/contract-types`.

Формат списка: `array`.

Поля:

```ts
{
  name: string;
  slug: string; // unique
}
```

### 8. Типы документов

Endpoint: `/document-types`.

Формат списка: `array`.

Поля:

```ts
{
  name: string; // max 255
  slug: string; // max 255, unique
}
```

### 9. Локации и районы

Endpoint:

```text
GET    /locations
GET    /locations/{id}
GET    /locations/{id}/districts
POST   /locations
PATCH  /locations/{id}
DELETE /locations/{id}
```

Формат списка: `array`.

Одна запись в базе представляет пару «город + район»:

```ts
{
  city: string;     // required
  district: string; // required
}
```

API списка дополнительно может возвращать:

```ts
{
  id: number;
  name: string;
  city: string;
  district: string;
  latitude: number | null;
  longitude: number | null;
  districts: unknown[];
}
```

Не создавай отдельный CRUD `/districts`: такого endpoint нет. В интерфейсе сгруппируй строки по городу, но редактируй каждую пару через `/locations/{id}`.

### 10. Филиалы

Endpoint: `/branches`.

Формат списка: `array`.

Поля:

```ts
{
  name: string;          // required, max 255
  lat: number | null;    // -90..90
  lng: number | null;    // -180..180
  landmark: string | null;
  photo: File | null;    // jpg/jpeg/png/webp, max 8 MB
}
```

Создание с фото отправляй как `multipart/form-data`.

При обновлении файла используй совместимый с Laravel method spoofing запрос:

```text
POST /branches/{id}
Content-Type: multipart/form-data
_method=PATCH
```

Не устанавливай `Content-Type` вручную: browser должен добавить boundary.

Для отображения фото используй существующий helper формирования public storage URL. Не склеивай домен в компонентах.

Удаление филиала с привязанными пользователями возвращает `409`.

### 11. Группы филиалов

Endpoint: `/branch-groups`.

Формат списка: `paginated`.

List filters:

```ts
{
  search?: string;
  name?: string;
  branch_id?: number;
  contact_visibility_mode?: "group_only" | "branch";
  per_page?: number; // 1..100
  page?: number;
}
```

Поля:

```ts
{
  branch_id: number;
  name: string; // unique внутри филиала
  description: string | null;
  contact_visibility_mode: "group_only" | "branch";
}
```

Подписи режимов:

```text
group_only → Контакты видны только группе
branch     → Контакты видны всему филиалу
```

В строке показывай `branch.name`, `users_count` и `clients_count`, если они пришли от API.

Удаление группы с пользователями или клиентами возвращает `409`.

Не загружай филиалы по одному: один раз используй `GET /branches`, затем находи их в локальном словаре.

### 12. Роли

Endpoint: `/roles`.

Формат списка: `array`.

List filter:

```ts
{ q?: string }
```

Поля:

```ts
{
  name: string;              // required, max 255
  slug: string;              // required, alpha_dash, unique
  description: string | null; // max 1000
}
```

Удаление роли с пользователями возвращает `409`.

Отдели этот справочник визуально как потенциально опасный. Покажи предупреждение, что slug роли влияет на права доступа. Не переименовывай системные slug автоматически.

### 13. Застройщики

Endpoint: `/developers`.

Формат списка: `paginated`.

List params:

```ts
{
  search?: string;
  status?: "pending" | "approved" | "rejected" | "draft" | "deleted";
  sort?: "name" | "created_at";
  dir?: "asc" | "desc";
  per_page?: number;
  page?: number;
}
```

Поля:

```ts
{
  name: string;
  phone: string | null;
  under_construction_count: number | null;
  built_count: number | null;
  founded_year: number | null; // 1800..current year
  total_projects: number | null;
  moderation_status:
    | "pending"
    | "approved"
    | "rejected"
    | "draft"
    | "deleted"
    | null;
  website: string | null;
  facebook: string | null;
  instagram: string | null;
  telegram: string | null;
  description: string | null;
  logo: File | null; // jpg/jpeg/png/webp/svg, max 5 MB
}
```

Create с файлом отправляй через `FormData`. Update файла выполняй через method spoofing `_method=PATCH`.

### 14. Особенности

Endpoint: `/features`.

Формат списка: `paginated`.

List params: `search`, `per_page`, `page`.

Поля:

```ts
{
  name: string;
  slug?: string;
  icon?: string | null; // только a-z, A-Z, 0-9, _ и -
}
```

Если slug не задан при создании, backend создаёт его сам.

### 15. Теги

Endpoint: `/tags`.

Формат списка: `paginated`.

List params: `search`, `per_page`, `page`.

Поля:

```ts
{
  name: string;        // max 100
  slug?: string;       // max 120, lowercase kebab-case
  color?: string | null; // #RRGGBB
}
```

Добавь color picker и текстовое поле HEX, синхронизированные между собой.

### 16. Материалы

Endpoint: `/materials`.

Формат списка: `paginated`.

List params: `search`, `per_page`, `page`.

Поля:

```ts
{
  name: string;
  slug?: string;
}
```

### 17. Этапы строительства

Endpoint: `/construction-stages`.

Формат списка: `paginated`.

List params:

```ts
{
  active?: boolean;
  per_page?: number;
  page?: number;
}
```

Поля:

```ts
{
  name: string;
  slug?: string;
  sort_order?: number; // min 0
  is_active?: boolean;
}
```

Сортируй визуально по `sort_order`, как возвращает backend. На данном этапе не отправляй выдуманный bulk reorder endpoint.

### 18. Типы клиентов

Endpoint: `/client-types`.

Формат списка: `array`.

Поля:

```ts
{
  name: string;       // unique
  slug: string;       // unique
  is_business?: boolean;
  sort_order?: number; // min 0
  is_active?: boolean;
}
```

### 19. Источники клиентов

Доступен только:

```text
GET /client-sources?active_only=false
```

Формат списка: `array`.

Ожидаемые поля:

```ts
{
  id: number;
  name: string;
  slug: string;
  sort_order: number;
  is_active: boolean;
}
```

Сейчас backend не предоставляет POST/PATCH и resource-specific DELETE для `client-sources`. Покажи справочник без создания и редактирования. Безопасное удаление или объединение доступно только через generic admin endpoint из `docs/prompts/admin-reference-catalog-merge-web-prompt.md`.

### 20. Типы потребностей клиентов

Endpoint: `/client-need-types`.

Формат списка: `array`.

Поля:

```ts
{
  name: string;       // unique
  slug: string;       // unique
  sort_order?: number;
  is_active?: boolean;
}
```

### 21. Статусы потребностей клиентов

Endpoint: `/client-need-statuses`.

Формат списка: `array`.

Поля:

```ts
{
  name: string;       // unique
  slug: string;       // unique
  is_closed?: boolean;
  sort_order?: number;
  is_active?: boolean;
}
```

Под `is_closed` покажи понятный switch «Закрывающий статус».

## HTTP методы

Для стандартного endpoint `{endpoint}` используй:

```text
GET    {endpoint}
GET    {endpoint}/{id}
POST   {endpoint}
PATCH  {endpoint}/{id}
DELETE {endpoint}/{id}
```

Учитывай исключения, явно описанные выше.

Для кнопки удаления в новом админском разделе не вызывай resource-specific `DELETE {endpoint}/{id}`. Сначала выполняй usage check, а затем используй:

```text
DELETE /admin/catalogs/{catalog}/{id}       — если связей нет
POST   /admin/catalogs/{catalog}/{id}/merge — если выбрана замена
```

Успешное удаление может вернуть:

- `200` с `{message}`;
- `204 No Content`.

API client не должен пытаться всегда парсить JSON у `204`.

Создание обычно возвращает `201`, но некоторые старые контроллеры возвращают `200`. Считай успешным любой корректный `2xx`.

## Визуальные требования

Интерфейс должен выглядеть как рабочая CRM Aura Estate:

- спокойная профессиональная визуальная иерархия;
- понятный заголовок и breadcrumb;
- фиксированная панель действий на desktop;
- крупные кликабельные области;
- плотная, но читаемая таблица;
- status badges для boolean/status полей;
- dropdown действий на mobile;
- без горизонтального overflow всей страницы;
- подтверждение опасных действий;
- клавиатурная навигация и видимый focus;
- корректные label и `aria` для dialog, inputs, switches и color picker.

Не добавляй декоративную карту, графики или dashboard-метрики, не связанные с управлением справочниками.

## Состояния

Реализуй и проверь:

- первоначальная загрузка;
- refetch без мигания;
- пустой справочник;
- поиск без результатов;
- ошибка списка;
- создание;
- редактирование;
- удаление;
- `409` при связанных данных;
- `422` с несколькими ошибками;
- `403`;
- read-only справочник;
- медленное соединение;
- повторное нажатие submit;
- закрытие формы с несохранёнными изменениями.

## Тесты

Добавь тесты в принятом проектом стеке.

Минимальное покрытие:

1. Registry содержит все 21 справочник.
2. `property-types` загружается как массив.
3. `developers`, `features`, `tags`, `materials`, `construction-stages` и `branch-groups` нормализуют paginator.
4. Создание типа недвижимости отправляет `name` и `slug`.
5. Редактирование инвалидирует правильный query key.
6. Удаление корректно обрабатывает `200` и `204`.
7. `409` отображает серверное сообщение и не удаляет строку из UI.
8. Ошибки `422` отображаются у правильных полей.
9. `client-sources` не показывает create/edit/delete.
10. Форма филиала отправляет `FormData`.
11. Форма группы использует реальные филиалы из `/branches`.
12. Пользователь без разрешённой роли не видит раздел.
13. Branch scope не расширяется frontend-фильтрами.
14. Повторное нажатие не создаёт дубликаты.
15. Поиск использует debounce и отменяет устаревший запрос.

## Definition of Done

Работа завершена, когда:

- `/admin/directories` доступен из админской навигации;
- реализован полный CRUD типов недвижимости;
- через общий registry работают все простые CRUD-справочники;
- сложные справочники имеют корректные отдельные формы;
- raw arrays и paginator обрабатываются одинаково стабильно;
- реальные backend validation errors видны пользователю;
- `client-sources` честно работает read-only;
- нет выдуманных API;
- нет N+1 запросов филиалов для групп;
- нет копипаста одинаковых CRUD-страниц;
- права и branch scope не расширяются;
- интерфейс адаптивен и доступен с клавиатуры;
- lint, typecheck и тесты проекта проходят.

В конце предоставь:

1. список созданных и изменённых файлов;
2. таблицу реализованных справочников и доступных действий;
3. описание обработки массивов и paginator;
4. список выполненных тестов;
5. отдельно перечисли backend-ограничения, которые нельзя решить на frontend.
