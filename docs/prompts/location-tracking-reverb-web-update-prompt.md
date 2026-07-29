# Delta-промпт: обновление web-карты для Laravel Reverb

Скопируй текст ниже в задачу web-команде после реализации основной карты.

---

В web-приложении Aura Estate уже должна быть карта сотрудников по промпту:

```text
docs/prompts/location-tracking-web-map-prompt.md
```

Обнови её: текущие позиции должны приходить преимущественно через Laravel Reverb WebSocket. HTTP остаётся для первоначальной загрузки, истории, watchlist и восстановления после reconnect.

## Зависимости

Установи в frontend-проект:

```bash
npm install laravel-echo pusher-js
```

Reverb использует Pusher protocol, но подключение идёт к нашему Reverb host.

## Переменные frontend

```dotenv
VITE_REVERB_APP_KEY=<public-app-key>
VITE_REVERB_HOST=realtime.example.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
VITE_API_URL=https://api.example.com
```

В frontend разрешён только public `REVERB_APP_KEY`. Secret запрещён.

## Echo client

Создай один singleton Echo client на авторизованную сессию:

```ts
import Echo from "laravel-echo";
import Pusher from "pusher-js";

window.Pusher = Pusher;

const echo = new Echo({
  broadcaster: "reverb",
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
  wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
  forceTLS: import.meta.env.VITE_REVERB_SCHEME === "https",
  enabledTransports: ["ws", "wss"],
  authEndpoint: `${import.meta.env.VITE_API_URL}/api/broadcasting/auth`,
  auth: {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json"
    }
  }
});
```

Адаптируй token provider к текущей auth-архитектуре. Не создавай новый Echo instance на каждый marker/render.

При смене token/logout:

- disconnect старый client;
- leave все location channels;
- очистить location query cache;
- после новой авторизации создать новый client.

## Initial hydration

При открытии карты:

1. вызвать `GET /api/location-tracking/map`;
2. сохранить snapshot позиций;
3. определить видимые/выбранные user IDs;
4. подписаться на приватные каналы;
5. отображать индикатор `live connection`.

Не ждать WebSocket-события для первоначального состояния.

## Подписки

Для каждого отображаемого пользователя:

```ts
echo
  .private(`location.user.${userId}`)
  .listen(".location.updated", event => {
    applyLocationUpdate(event);
  });
```

При удалении пользователя из видимых:

```ts
echo.leave(`location.user.${userId}`);
```

Различай persisted watchlist и активные подписки. Не оставляй подписки после смены scope или logout.

Ограничь массовые операции: если выбрано много пользователей, подписывайся пакетно/постепенно и не блокируй main thread.

## Обновление marker state

Применять событие только если:

```ts
incoming.point_id > current.point_id
```

или, если старый snapshot не содержит point ID:

```ts
Date.parse(incoming.captured_at) > Date.parse(current.captured_at)
```

Старое/повторное событие игнорировать.

После принятия:

- обновить координаты marker;
- обновить accuracy/quality/time;
- пересчитать status `live`;
- не выполнять fit bounds на каждое событие;
- не перемещать viewport без действия пользователя;
- не показывать резкий прыжок старой точки как плавное живое движение.

Если открыт сегодняшний history route выбранного пользователя:

- добавить новую точку в конец линии;
- только при `point_id` новее;
- не добавлять точку в прошлую дату;
- не выполнять road snapping.

## Reconnect

Отслеживай connection state:

- `connecting`;
- `connected`;
- `unavailable`;
- `failed`;
- `disconnected`.

После каждого успешного reconnect:

1. выполнить `GET /api/location-tracking/map`;
2. заменить current snapshot только более свежими точками;
3. пересоздать необходимые подписки;
4. вернуть статус `online`.

WebSocket может пропустить события, поэтому reconnect без HTTP reconciliation запрещён.

## Fallback polling

Измени старый polling:

- при подключённом WebSocket — reconciliation раз в 3–5 минут;
- при `unavailable/failed` — polling каждые 30 секунд;
- при восстановлении WebSocket — вернуть редкий polling;
- скрытая вкладка — остановить частый polling;
- при возврате вкладки — немедленный `/map`.

Не запускай одновременно несколько polling timers.

## Ошибки

- auth 401 → session-expired flow и disconnect;
- channel 403 → leave channel, обновить available-users/watchlist;
- socket unavailable → показать ненавязчивый статус «Обновление с задержкой»;
- ошибка одного канала не отключает остальные;
- история продолжает работать по HTTP при недоступном Reverb.

## UI

Добавь небольшой индикатор:

- зелёный: «Онлайн»;
- жёлтый: «Переподключение»;
- серый: «Резервное обновление»;
- tooltip с временем последней успешной синхронизации.

Не показывай технические слова Reverb/Pusher обычному пользователю.

## Тесты

1. Один Echo instance на auth-сессию.
2. Selected user создаёт private subscription.
3. Снятие выбора вызывает leave.
4. Старый `point_id` игнорируется.
5. Новый event обновляет marker.
6. Event другого user ID не меняет marker.
7. Reconnect вызывает `/map`.
8. WebSocket online → редкий polling.
9. WebSocket failed → polling 30 секунд.
10. Logout disconnect/leave/clear cache.
11. 403 удаляет недоступную подписку.
12. Сегодняшняя линия дополняется новой точкой.
13. Прошлый маршрут не изменяется realtime-событием.
14. Координаты не попадают в console/analytics/localStorage.

Используй fake Echo adapter и fake clock.

## Definition of Done

- карта сначала гидратируется HTTP snapshot;
- selected users получают private WebSocket updates;
- marker обновляется без полной перезагрузки;
- reconnect выполняет HTTP reconciliation;
- fallback polling работает;
- нет утечек подписок после logout;
- history остаётся HTTP;
- тесты проходят;
- приложен screenshot индикаторов online/fallback.

---
