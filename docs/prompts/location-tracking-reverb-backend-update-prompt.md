# Delta-промпт: backend и инфраструктура Laravel Reverb

Скопируй текст ниже в задачу backend/DevOps-команде.

---

В Aura Estate уже реализованы:

- HTTP ingestion геопозиций;
- `UserLocationUpdated`;
- приватный канал `location.user.{userId}`;
- авторизация `POST /api/broadcasting/auth`;
- Laravel Reverb;
- отправка одного realtime-события на один ingestion batch.

Не переписывай location-модуль. Доведи существующую Reverb-интеграцию до production deployment.

## Проверить код

Ключевые файлы:

```text
app/Events/UserLocationUpdated.php
app/Services/LocationTracking/LocationIngestionService.php
app/Services/LocationTracking/LocationAccessService.php
routes/channels.php
routes/api.php
config/broadcasting.php
config/reverb.php
bootstrap/app.php
```

Событие:

```text
.location.updated
private-location.user.{userId}
```

Payload:

```json
{
  "user_id": 15,
  "point_id": 912341,
  "latitude": 38.5598,
  "longitude": 68.787,
  "accuracy_m": 18.5,
  "quality": "good",
  "captured_at": "2026-07-29T11:42:15Z",
  "received_at": "2026-07-29T11:42:17Z"
}
```

Не добавляй телефон, email, branch data, полную модель User или историю в WebSocket payload.

## Переменные production

Настроить реальные значения:

```dotenv
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis

REVERB_APP_ID=aura-estate-production
REVERB_APP_KEY=<public-random-key>
REVERB_APP_SECRET=<strong-secret>

REVERB_HOST=realtime.example.com
REVERB_PORT=443
REVERB_SCHEME=https

REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_ALLOWED_ORIGINS=https://crm.example.com
```

Точные домены взять из инфраструктуры проекта. Не использовать `*` для production origins. Не передавать `REVERB_APP_SECRET` во frontend.

## Процессы

Под process manager должны постоянно работать:

```bash
php artisan queue:work --sleep=1 --tries=3 --timeout=60
php artisan reverb:start --host=0.0.0.0 --port=8080
```

Настроить:

- автоматический restart;
- запуск после reboot;
- отдельные stdout/stderr logs;
- log rotation;
- graceful restart при deploy;
- health/monitoring;
- лимит открытых файлов;
- Redis при нескольких Reverb-инстансах.

После deploy:

```bash
php artisan config:cache
php artisan route:cache
php artisan queue:restart
php artisan reverb:restart
```

## Reverse proxy

Настроить WSS:

- внешний `wss://realtime.example.com`;
- внутренний Reverb `127.0.0.1:8080`;
- Upgrade/Connection headers;
- корректный `Host`;
- TLS certificate;
- proxy read timeout больше WebSocket idle interval;
- rate limiting handshake без блокировки постоянного соединения.

Не открывать внутренний порт Reverb всему интернету.

## Авторизация

Web использует:

```http
POST /api/broadcasting/auth
Authorization: Bearer <sanctum-token>
```

Channel:

```text
private-location.user.{targetUserId}
```

`LocationAccessService` остаётся единственным источником scope:

- agent → self;
- mop → self + агенты группы;
- rop/director → agent+mop филиала;
- admin/superadmin → глобальный разрешённый scope.

Нельзя делать channel public. Presence channel не нужен.

## Надёжность

- событие отправляется только после сохранения точки;
- один batch из 50 точек → максимум одно событие;
- broadcast failure не должен откатывать сохранённые координаты;
- очередь должна повторить временную ошибку Reverb;
- после reconnect frontend восстанавливается через `GET /api/location-tracking/map`;
- WebSocket не является хранилищем и источником истины.

## Тесты и smoke check

Проверить:

1. Разрешённый наблюдатель получает `auth`.
2. Чужой scope получает 403.
3. Неавторизованный запрос получает 401.
4. После ingestion появляется один queued broadcast job.
5. Offline batch не создаёт 50 событий.
6. Event payload не раскрывает лишние поля.
7. Reverb restart не влияет на HTTP ingestion.
8. Web reconnect получает дальнейшие события.
9. CORS/allowed origins отклоняют посторонний origin.

Запустить:

```bash
php artisan test tests/Feature/LocationTrackingFeatureTest.php
php artisan route:list --path=broadcasting
php artisan channel:list
```

## Definition of Done

- Reverb и queue worker работают под process manager;
- WSS доступен с production web domain;
- private auth работает через Sanctum;
- чужие каналы возвращают 403;
- reconnect восстанавливается;
- секрет отсутствует во frontend;
- origins ограничены;
- мониторинг и инструкция rollback добавлены;
- приложены команды deploy и результаты smoke test.

---
