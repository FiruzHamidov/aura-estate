# Delta-промпт: что обновить в iOS после добавления Reverb

Скопируй текст ниже в iOS-задачу.

---

Backend Aura Estate теперь отправляет текущие позиции web-карте через Laravel Reverb. iOS-приложение не должно подключаться к Reverb и не должно отправлять CLLocation через WebSocket.

Проведи аудит существующего iOS location-модуля:

1. Координаты отправляются только через:

```http
POST /api/location-tracking/me/points
```

2. Policy/device/status:

```text
GET  /api/location-tracking/me/policy
PUT  /api/location-tracking/me/device
POST /api/location-tracking/me/status
```

3. Не добавлять:

- Pusher/Reverb/Echo client;
- постоянное socket connection;
- Reverb credentials;
- прямую публикацию событий;
- зависимость Core Location от состояния WebSocket.

4. После HTTP accepted/duplicate удалить точку из offline store. WebSocket acknowledgement не ожидается.

5. Недоступность Reverb не должна останавливать Core Location или HTTP sync.

6. Сохраняются прежние требования:

- permission только `agent|mop`;
- disclosure до системного запроса;
- When In Use перед Always;
- background tracking только по policy;
- idempotent event UUID;
- offline store максимум 2 000 точек/72 часа;
- batch максимум 50;
- logout/role change останавливает tracking.

7. Не помещать Reverb public key в iOS, потому что приложение его не использует.

Добавь тесты:

- networking repository не зависит от WebSocket;
- accepted/duplicate удаляются из offline store;
- network/5xx остаются для retry;
- неизвестная роль не вызывает authorization;
- logout останавливает location updates.

В итоговом отчёте явно напиши: «WebSocket используется только web-наблюдателем; iOS передаёт координаты через защищённый HTTP API».

---
