# CRM Clients API

## GET `/api/clients`

Поддерживаемые фильтры по источнику клиента:

- `source_id` — одиночный id источника.
- `source_ids` — множественный фильтр (приоритетнее `source_id`).

Форматы `source_ids`:

- repeated: `source_ids[]=1&source_ids[]=2`
- csv: `source_ids=1,2`

Правила приоритета:

- если переданы и `source_ids`, и `source_id`, используется `source_ids`.
- если `source_ids` пустой (`source_ids=`), фильтр по источнику не применяется.

В ответе клиента возвращаются:

- `source_id`
- `source` объект (`id`, `code`, `name`, `is_active`, `sort_order`)

## POST `/api/clients` и PUT/PATCH `/api/clients/{id}`

Поля источника:

- `source_id`: `nullable|integer|exists:client_sources,id` + источник должен быть активным.
- `source_comment`: `nullable|string`.

Если передан неактивный источник, API возвращает `422`.

## GET `/api/client-sources`

Возвращает справочник источников клиентов.

- по умолчанию только активные записи (`active_only=true`)
- сортировка: `sort_order`, затем `id`
- можно передать `active_only=0`, чтобы получить все записи.

