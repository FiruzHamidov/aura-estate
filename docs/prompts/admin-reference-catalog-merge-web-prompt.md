# Промпт: безопасная замена и удаление записей справочников

Ты senior frontend-разработчик Aura Estate. Доработай существующий админский CRUD справочников: перед удалением показывай, сколько объектов используют запись, и предлагай переназначить их на другую запись того же справочника.

Не меняй существующую архитектуру, UI-kit, API client, авторизацию и data-fetching библиотеки проекта. Интегрируй функцию в текущий `DeleteCatalogItemDialog`.

Backend уже реализован. Все пути относительны к:

```dotenv
NEXT_PUBLIC_API_URL=https://backend.aura.tj/api
```

## API проверки использования

```http
GET /admin/catalogs/{catalog}/{item}/usage
Authorization: Sanctum session
```

Пример:

```http
GET /admin/catalogs/property-types/15/usage
```

Успешный ответ:

```json
{
  "data": {
    "catalog": "property-types",
    "item": {
      "id": 15,
      "name": "Старый тип",
      "slug": "old-type"
    },
    "usage": {
      "total": 200,
      "breakdown": [
        {
          "entity": "properties",
          "label": "Объекты недвижимости",
          "count": 185
        },
        {
          "entity": "client_needs",
          "label": "Потребности клиентов",
          "count": 15
        }
      ]
    },
    "can_delete_directly": false,
    "replacement_required": true,
    "merge_allowed": true,
    "replacement_options": [
      {
        "id": 3,
        "name": "Квартира",
        "slug": "apartment"
      }
    ]
  }
}
```

`usage.total` считает уникальные бизнес-объекты внутри каждой категории. Одна и та же потребность клиента, присутствующая одновременно в legacy foreign key и pivot-таблице, не должна отображаться дважды.

`replacement_options` уже:

- принадлежат тому же справочнику;
- не содержат удаляемую запись;
- содержат максимум 500 вариантов;
- могут содержать `slug` и `is_active`.

Не загружай варианты замены отдельным запросом, если они уже пришли в `replacement_options`.

## API переноса и удаления

```http
POST /admin/catalogs/{catalog}/{item}/merge
Content-Type: application/json
```

Payload:

```json
{
  "replacement_id": 3,
  "expected_usage_count": 200
}
```

`expected_usage_count` обязательно отправляй из последнего успешного ответа `usage`. Не вычисляй его на frontend.

Успешный ответ:

```json
{
  "data": {
    "catalog": "property-types",
    "source": {
      "id": 15,
      "name": "Старый тип",
      "slug": "old-type"
    },
    "replacement": {
      "id": 3,
      "name": "Квартира",
      "slug": "apartment"
    },
    "reassigned": {
      "total": 200,
      "breakdown": [
        {
          "entity": "properties",
          "label": "Объекты недвижимости",
          "count": 185
        },
        {
          "entity": "client_needs",
          "label": "Потребности клиентов",
          "count": 15
        }
      ]
    },
    "source_deleted": true
  }
}
```

Backend выполняет перенос всех foreign key и pivot-связей, удаление исходной записи и audit log одной транзакцией. Не выполняй отдельные запросы для каждого связанного объекта.

## Поддерживаемые catalog key

Используй ровно эти значения:

```ts
type MergeableCatalogKey =
  | "property-types"
  | "property-statuses"
  | "building-types"
  | "parking-types"
  | "heating-types"
  | "repair-types"
  | "contract-types"
  | "document-types"
  | "locations"
  | "branches"
  | "branch-groups"
  | "roles"
  | "developers"
  | "features"
  | "tags"
  | "materials"
  | "construction-stages"
  | "client-types"
  | "client-sources"
  | "client-need-types"
  | "client-need-statuses";
```

Не передавай имя таблицы или произвольную строку вместо catalog key.

## UX удаления

При нажатии «Удалить»:

1. открой dialog;
2. покажи skeleton;
3. вызови `usage`;
4. после ответа выбери один из сценариев ниже.

### Сценарий A: запись нигде не используется

Условия:

```ts
can_delete_directly === true
usage.total === 0
```

Покажи обычное подтверждение:

```text
Удалить «Старый тип»?
Это действие нельзя отменить.
```

После подтверждения используй защищённый generic endpoint:

```http
DELETE /admin/catalogs/{catalog}/{id}
```

Этот endpoint повторно проверяет использование внутри транзакции. Не вызывай `merge`, когда связей нет, и не используй старые resource-specific DELETE endpoint для нового диалога.

### Сценарий B: запись используется

Условия:

```ts
replacement_required === true
merge_allowed === true
```

Покажи:

```text
«Старый тип» используется в 200 записях

Объекты недвижимости       185
Потребности клиентов        15
```

Ниже обязательный combobox:

```text
Заменить на *
[ Выберите значение ]
```

В варианте показывай:

- `name`;
- `slug`, если он есть;
- badge «Неактивен», если `is_active === false`.

Не выбирай replacement автоматически. Администратор должен сделать осознанный выбор.

Основная кнопка:

```text
Переназначить 200 записей и удалить
```

Кнопка disabled, пока replacement не выбран или запрос выполняется.

Перед отправкой покажи финальное резюме:

```text
200 записей будут переназначены:
«Старый тип» → «Квартира»
```

### Сценарий C: запись защищена

Условие:

```ts
merge_allowed === false
```

Покажи предупреждение:

```text
Системную запись нельзя объединить или удалить.
```

Не показывай активную кнопку удаления. Это применяется, в частности, к системным ролям.

### Сценарий D: нет вариантов замены

Если:

```ts
usage.total > 0
replacement_options.length === 0
merge_allowed === true
```

Покажи:

```text
Сначала создайте другое значение этого справочника, затем повторите удаление.
```

Добавь ссылку или кнопку «Создать значение», открывающую существующую create form текущего справочника. После успешного создания повторно вызови `usage`.

## Ошибки

### Изменилось количество связанных объектов

Backend может вернуть:

```json
{
  "code": "REFERENCE_USAGE_CHANGED",
  "message": "Количество связанных записей изменилось. Обновите данные и подтвердите перенос повторно.",
  "details": {
    "expected_usage_count": 200,
    "actual_usage_count": 203,
    "usage": {
      "total": 203,
      "breakdown": []
    }
  }
}
```

При этой ошибке:

1. не закрывай dialog;
2. сбрось выбранное подтверждение;
3. повторно загрузи `usage`;
4. покажи уведомление, что данные изменились;
5. потребуй новое подтверждение уже для актуального количества.

Никогда не повторяй `merge` автоматически.

### Конфликт переноса

Коды:

```text
REFERENCE_MERGE_CONFLICT
REFERENCE_MERGE_INCOMPLETE
```

Покажи серверный `message`, оставь dialog открытым и предложи повторить после исправления связанных данных. Backend уже откатил всю транзакцию.

### Другие коды

```text
REFERENCE_CATALOG_FORBIDDEN             → нет прав
REFERENCE_CATALOG_NOT_FOUND             → справочник не поддерживается
REFERENCE_CATALOG_ITEM_NOT_FOUND        → запись уже удалена или изменена
REFERENCE_REPLACEMENT_SAME_AS_SOURCE    → неверный replacement
REFERENCE_CATALOG_ITEM_PROTECTED        → системная запись защищена
REFERENCE_CATALOG_IN_USE                → появились связи, нужно выбрать замену
REFERENCE_DELETE_CONFLICT               → найдена связь, отсутствующая в безопасном registry
```

Общие HTTP:

- `401` — session-expired flow;
- `403` — недостаточно прав;
- `404` — refetch списка и закрытие dialog после уведомления;
- `409` — показать серверный конфликт;
- `422` — показать validation errors;
- `5xx/network` — оставить данные и кнопку повторной попытки.

## Состояние и cache

Создай hooks в принятом проектом data-fetching стеке:

```ts
useCatalogUsage(catalog, itemId, enabled)
useMergeCatalogItem()
```

Query key:

```ts
["admin-catalog-usage", catalog, itemId]
```

После успешного merge:

- закрыть dialog;
- удалить usage query исходной записи;
- инвалидировать список текущего справочника;
- инвалидировать зависимые select queries;
- инвалидировать экраны сущностей из `reassigned.breakdown`, если такие queries активны;
- показать toast:

```text
200 записей переназначены. «Старый тип» удалён.
```

Не удаляй строку optimistic до ответа backend.

## TypeScript-типы

```ts
type CatalogUsageBreakdown = {
  entity: string;
  label: string;
  count: number;
};

type CatalogReplacementOption = {
  id: number;
  name: string;
  slug?: string;
  is_active?: boolean;
};

type CatalogUsageResponse = {
  data: {
    catalog: MergeableCatalogKey;
    item: CatalogReplacementOption;
    usage: {
      total: number;
      breakdown: CatalogUsageBreakdown[];
    };
    can_delete_directly: boolean;
    replacement_required: boolean;
    merge_allowed: boolean;
    replacement_options: CatalogReplacementOption[];
  };
};

type MergeCatalogPayload = {
  replacement_id: number;
  expected_usage_count: number;
};
```

Добавь runtime validation ответа существующим способом проекта. Не доверяй `unknown` response напрямую.

## Тесты

Добавь тесты:

1. При открытии delete dialog вызывается `usage`.
2. При `total=0` показывается обычное удаление.
3. При `total>0` показываются количество и breakdown.
4. Merge нельзя отправить без replacement.
5. Исходная запись отсутствует в replacement options.
6. Payload содержит `replacement_id` и последний `expected_usage_count`.
7. Успешный merge закрывает dialog и инвалидирует cache.
8. `REFERENCE_USAGE_CHANGED` обновляет данные и требует повторного подтверждения.
9. `REFERENCE_MERGE_CONFLICT` не удаляет строку из UI.
10. Защищённую запись нельзя удалить.
11. При отсутствии вариантов предлагается создать новую запись.
12. Двойной клик не создаёт два merge-запроса.
13. Dialog доступен с клавиатуры и корректно возвращает focus.

## Definition of Done

- перед любым удалением выполняется usage check;
- администратор видит реальное количество и разбивку;
- replacement выбирается явно;
- frontend делает ровно один merge-запрос независимо от количества объектов;
- stale usage безопасно обрабатывается;
- защищённые записи нельзя удалить;
- прямое удаление используется только при `can_delete_directly=true`;
- после merge списки и select cache обновляются;
- ошибки не приводят к ложному исчезновению записи;
- typecheck, lint и тесты проходят.

В конце перечисли изменённые файлы, реализованные состояния, cache invalidation и выполненные тесты.
