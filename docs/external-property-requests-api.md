# API: внешние агенты и заявки на объявления

Документ описывает рабочий API-контракт backend-модуля внешних агентов.

## Роли

- `external_agent` - внешний агент. Работает только со своими заявками.
- Внутренние роли для очереди: `agent`, `manager`, `operator`, `mop`, `rop`, `branch_director`, `admin`, `superadmin`.
- `client` и `external_agent` не имеют доступа к старому внутреннему CRUD объявлений.

## Статусы заявки

- `draft` - черновик.
- `submitted` - отправлена.
- `assigned` - назначен ответственный.
- `in_review` - в проверке.
- `needs_info` - нужно уточнение.
- `duplicate` - найден возможный дубль.
- `rejected` - отклонена.
- `converted` - создано объявление.
- `archived` - архив.

`display_status` для внешнего агента может отличаться от технического статуса:

- `in_work` для `assigned` и `in_review`.
- `property_created`, `published`, `property_rejected`, `closed_deal` для сконвертированных заявок в зависимости от `properties.moderation_status`.

## API внешнего агента

Все endpoints требуют `auth:sanctum` и активного пользователя с ролью `external_agent`.

### `GET /api/external/property-requests`

Список моих заявок.

Query:

- `status`
- `q`
- `created_from`
- `created_to`
- `per_page`

Ответ: стандартная Laravel pagination-структура. Элементы списка возвращаются в безопасном внешнем формате без `internal_comment`, внутреннего агента и внутренних логов.

### `GET /api/external/property-requests/stats`

Счетчики для кабинета внешнего агента.

Query:

- `created_from`
- `created_to`

Ответ:

```json
{
  "total": 2,
  "by_status": {
    "draft": 0,
    "submitted": 1,
    "assigned": 0,
    "in_review": 0,
    "needs_info": 0,
    "duplicate": 0,
    "rejected": 0,
    "converted": 1,
    "archived": 0
  },
  "work_queue": {
    "new": 1,
    "assigned": 0,
    "in_review": 0,
    "needs_info": 0,
    "duplicates": 0
  },
  "converted": {
    "total": 1,
    "published": 1,
    "closed_deal": 0
  },
  "rejected": 0,
  "archived": 0
}
```

### `POST /api/external/property-requests`

Создать заявку.

Query:

- `draft=1` - создать черновик без обязательных полей.

Payload для отправленной заявки:

```json
{
  "offer_type": "sale",
  "type_id": 1,
  "location_id": 2,
  "district": "Сино",
  "address": "ул. ...",
  "landmark": "рядом с ...",
  "price": 85000,
  "currency": "USD",
  "rooms": 2,
  "total_area": 65,
  "living_area": 42,
  "land_size": null,
  "floor": 5,
  "total_floors": 12,
  "repair_type_id": 1,
  "condition": "хорошее",
  "owner_name": "Имя владельца",
  "owner_phone": "+992900000001",
  "external_comment": "Комментарий внешнего агента"
}
```

Backend всегда берет `external_agent_id`, `branch_id`, `branch_group_id` из авторизованного пользователя. Эти поля нельзя подменить payload'ом.

Если найден похожий объект, заявка создается со статусом `duplicate` и `duplicate_property_id`.

### `GET /api/external/property-requests/{id}`

Детальная карточка своей заявки.

Безопасный ответ содержит:

- данные объекта,
- фото,
- `property.id` и публичную ссылку, если объявление опубликовано,
- внешне релевантные логи.

В ответ не входят:

- `internal_comment`,
- внутренние приватные комментарии,
- данные чужих пользователей,
- CRM/финансы.

### `PATCH /api/external/property-requests/{id}`

Редактировать свою заявку.

Разрешено только в статусах:

- `draft`
- `submitted`
- `needs_info`

Если заявка была в `needs_info`, после обновления она возвращается в `submitted`.

### `POST /api/external/property-requests/{id}/submit`

Отправить черновик.

Для отправки обязательны:

- `offer_type`
- `type_id`
- `price`
- `currency`
- `owner_phone`

### `POST /api/external/property-requests/{id}/photos`

Загрузить фото заявки.

Payload multipart:

- `photos[]` - до 40 файлов.

Ограничения:

- `jpg`, `jpeg`, `png`, `webp`
- до 8 MB на файл

### `DELETE /api/external/property-requests/{id}/photos/{photo}`

Удалить фото своей заявки, пока заявка редактируема.

## API внутренней очереди

Все endpoints требуют `auth:sanctum`, активного пользователя и внутреннюю роль.

Scope:

- `admin`, `superadmin` - все заявки.
- `rop`, `branch_director` - свой `branch_id`.
- `mop` - свой `branch_group_id`.
- `agent`, `manager`, `operator` - назначенные заявки и заявки своей зоны.
- `external_agent`, `client`, `intern` - без доступа к внутренней очереди/конвертации.

### `GET /api/external-agent-requests`

Внутренний список заявок.

Query:

- `status`
- `external_agent_id`
- `assigned_agent_id`
- `branch_id`
- `branch_group_id`
- `created_from`
- `created_to`
- `has_duplicate`
- `q`
- `per_page`

### `GET /api/external-agent-requests/stats`

Статистика внутренней очереди с теми же фильтрами, что список.

Ответ аналогичен внешнему stats, но считается по доступной зоне внутреннего пользователя.

### `GET /api/external-agent-requests/leaderboard`

Рейтинг внешних агентов в зоне доступа внутреннего пользователя.

Query:

- `status`
- `external_agent_id`
- `assigned_agent_id`
- `branch_id`
- `branch_group_id`
- `created_from`
- `created_to`
- `has_duplicate`
- `q`
- `limit` - от 1 до 100, default 50.

Ответ:

```json
{
  "data": [
    {
      "external_agent_id": 45,
      "external_agent_name": "Партнер",
      "total": 12,
      "converted": 5,
      "published": 4,
      "closed_deal": 1,
      "duplicates": 2,
      "rejected": 1,
      "conversion_rate": 0.4167
    }
  ]
}
```

Сортировка:

1. больше `converted`,
2. больше `total`,
3. больше `closed_deal`.

### `GET /api/external-agent-requests/{id}`

Детальная внутренняя карточка заявки.

Включает:

- внешнего агента,
- ответственного,
- филиал/группу,
- фото,
- возможный дубль,
- property после конвертации,
- полную историю логов.

### `PATCH /api/external-agent-requests/{id}/assign`

Назначить ответственного.

Payload:

```json
{
  "assigned_agent_id": 12
}
```

Назначить можно только внутреннего сотрудника подходящей роли и в зоне заявки.

### `PATCH /api/external-agent-requests/{id}/status`

Сменить статус.

Payload:

```json
{
  "status": "needs_info",
  "comment": "Нужно уточнить этаж и прислать фото кухни"
}
```

Нельзя менять статус уже сконвертированной заявки.

### `GET /api/external-agent-requests/{id}/prefill`

Получить payload для формы создания объявления.

Ответ:

```json
{
  "property_payload": {
    "title": "2-комн., квартира, Сино",
    "description": "Комментарий внешнего агента",
    "offer_type": "sale",
    "type_id": 1,
    "status_id": 1,
    "price": 85000,
    "currency": "USD",
    "rooms": 2,
    "district": "Сино",
    "owner_name": "Имя владельца",
    "owner_phone": "+992900000001",
    "agent_id": 12,
    "moderation_status": "pending",
    "listing_type": "regular"
  },
  "photos": [
    {
      "id": 1,
      "url": "/storage/external-property-requests/1/photo.jpg",
      "position": 0
    }
  ],
  "source": {
    "external_agent_id": 45,
    "external_agent_name": "..."
  }
}
```

### `POST /api/external-agent-requests/{id}/convert`

Создать обычное объявление `Property` из заявки.

Payload:

- принимает поля объявления из `property_payload`,
- `copy_photos` boolean, default `true`,
- `force` boolean, default `false`.

Правила:

- повторная конвертация запрещена;
- `rejected` и `archived` нельзя конвертировать;
- `duplicate` заявка требует `force=true`;
- `created_by` ставится текущим внутренним пользователем;
- `external_agent_id`, `external_property_request_id`, `source_type=external_agent` проставляются автоматически;
- владелец создается/находится в `clients` как seller;
- фото копируются в `property_photos`;
- заявка получает `status=converted`, `property_id`, `converted_at`.

## Интеграция с объявлениями

### Фильтры списка объявлений

`GET /api/properties`

Дополнительные query:

- `source_type=external_agent`
- `external_agent_id=45`

### Карточка объявления

Для авторизованных внутренних пользователей в `GET /api/properties/{property}` добавляется:

```json
{
  "external_source": {
    "source_type": "external_agent",
    "external_agent_id": 45,
    "external_agent_name": "...",
    "external_property_request_id": 12,
    "external_property_request_status": "converted",
    "external_property_request_display_status": "published",
    "submitted_at": "2026-06-18T..."
  }
}
```

Публичный гость `external_source` не получает.

## Проверенное поведение

Покрыто feature-тестами:

- внешний агент создает и видит только свои заявки;
- внешний агент не подменяет филиал/группу;
- внешний ответ скрывает внутренние комментарии;
- черновик нельзя отправить без обязательных полей;
- внутренний агент конвертирует заявку в `Property`;
- дубль требует `force=true`;
- нельзя назначить ответственным внешнего агента;
- внешний агент не может напрямую создать `Property`;
- список объявлений фильтруется по `source_type=external_agent`;
- `external_source` виден только авторизованному пользователю;
- stats внешнего агента scoped только на свои заявки;
- internal stats уважает scope и фильтры.
- leaderboard внутренних ролей уважает scope и сортировку.
