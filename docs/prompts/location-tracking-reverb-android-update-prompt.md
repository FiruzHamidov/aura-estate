# Delta-промпт: что обновить в Android после добавления Reverb

Скопируй текст ниже в Android-задачу.

---

Backend Aura Estate теперь отправляет текущие позиции web-карте через Laravel Reverb. Android-приложение не должно подключаться к Reverb и не должно отправлять координаты через WebSocket.

Проведи аудит существующего Android location-модуля и зафиксируй:

1. Координаты по-прежнему отправляются только:

```http
POST /api/location-tracking/me/points
```

2. Device/status работают через:

```text
PUT  /api/location-tracking/me/device
POST /api/location-tracking/me/status
GET  /api/location-tracking/me/policy
```

3. Не добавлять:

- `laravel-echo`;
- Pusher/Reverb SDK;
- постоянное WebSocket-соединение;
- прямую отправку в `location.user.{id}`;
- realtime secret/key;
- зависимость location tracking от доступности Reverb.

4. Успешный HTTP-ответ означает, что точка сохранена. Android не ждёт подтверждения WebSocket.

5. При недоступном Reverb HTTP ingestion должен продолжать работать без изменений.

6. Offline queue, batch до 50, idempotent `event_id`, role gate `agent|mop` и permission flow остаются обязательными.

7. Для batch сортируй точки по `captured_at ASC`, но считай server response источником accepted/duplicate/rejected.

8. Не пытайся воспроизводить WebSocket retry в мобильном приложении.

Добавь тест:

- HTTP ingestion работает без WebSocket dependency;
- в dependency graph отсутствует Reverb/Pusher;
- role не `agent|mop` не запускает location permission;
- offline batch успешно подтверждается backend;
- logout останавливает сбор.

В итоговом отчёте явно напиши: «WebSocket используется только web-наблюдателем; Android передаёт координаты через защищённый HTTP API».

---
