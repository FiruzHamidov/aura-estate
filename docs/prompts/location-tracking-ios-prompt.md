# Готовый промпт для реализации iOS: местоположение агентов и МОПов

Скопируй весь текст ниже в отдельную задачу iOS-разработчику или coding agent.

---

Ты работаешь над существующим iOS-приложением Aura Estate. Реализуй production-ready модуль передачи местоположения сотрудников с Core Location, встроенный в текущую архитектуру, DI, auth/session, networking и persistence приложения. Сначала изучи проект и не меняй его архитектурный стиль без необходимости.

Backend-контракт описан в `docs/user-location-tracking-spec.md`. Base path: `/api/location-tracking`.

## Критическое правило ролей и App Review

Core Location разрешено запрашивать только после успешной авторизации пользователя с backend-ролью:

- `agent`;
- `mop`.

Для всех остальных ролей, включая `admin`, `superadmin`, `rop`, `branch_director`, `manager`, `operator`, `marketing`, `hr`, `client`, `external_agent`, приложение обязано:

- никогда не вызывать `requestWhenInUseAuthorization()`;
- никогда не вызывать `requestAlwaysAuthorization()`;
- не создавать активную background location session;
- не вызывать `startUpdatingLocation()`, significant-change или visit monitoring;
- скрыть UI включения отслеживания;
- остановить существующий tracking при смене роли;
- очистить/изолировать очередь при logout и смене пользователя.

Используй двойной fail-closed gate:

```swift
let roleEligible = ["agent", "mop"].contains(session.user.role.slug)
let mayEnterPermissionFlow =
    roleEligible &&
    policy.eligibleForLocationPermission &&
    policy.shouldRequestLocationPermission &&
    policy.trackingEnabled
```

Если роль неизвестна, auth/profile ещё загружается, policy не получена или network завершился ошибкой, ничего не запрашивать.

Нельзя показывать системный permission alert на launch screen, до login или автоматически в момент завершения login. После входа `agent|mop`:

1. получить `GET /api/location-tracking/me/policy`;
2. проверить role и policy gate;
3. показать обычный экран Aura Estate с объяснением;
4. дать действия «Продолжить» и «Не сейчас»;
5. вызвать системный запрос только после явного нажатия «Продолжить».

Apple рекомендует просить геолокацию непосредственно в контексте функции, которой она нужна, и предпочитать When In Use. Always запрашивай только если это действительно необходимо для согласованного рабочего background-сценария, и отдельным последующим шагом.

Официальные источники:

- https://developer.apple.com/documentation/corelocation/requesting-authorization-to-use-location-services
- https://developer.apple.com/documentation/bundleresources/choosing-the-location-services-authorization-to-request
- https://developer.apple.com/app-store/review/guidelines/

Не утверждай, что role gate сам по себе гарантирует прохождение App Review.

## Объяснение пользователю

Перед системным alert покажи понятный экран:

> Aura Estate использует местоположение, чтобы сохранять рабочую позицию и маршрут агента или МОПа и показывать их уполномоченному руководителю. Отслеживание выполняется только согласно рабочему расписанию. Если включён рабочий фоновый режим, местоположение может обновляться, когда приложение свёрнуто. Разрешение можно не выдавать или позже изменить в Настройках.

Экран должен соответствовать дизайну приложения, не имитировать системный alert, иметь отказ без блокировки всего приложения и ссылку на privacy policy.

## Permission flow

1. Сначала запроси `When In Use`.
2. После успешной выдачи зарегистрируй device/status на backend.
3. Начни foreground tracking только если `policy.shouldTrackNow=true`.
4. Если `policy.requireBackgroundPermission=true` и продукт действительно требует работу после завершения foreground-сессии, покажи отдельное объяснение Always.
5. `requestAlwaysAuthorization()` вызывай позже, в контексте включения рабочего фонового режима, а не одновременно с первым запросом.
6. Не повторяй системный запрос после denial. Покажи кнопку перехода в Settings только после явного действия пользователя.
7. Обработай:
   - `.notDetermined`;
   - `.authorizedWhenInUse`;
   - `.authorizedAlways`;
   - `.denied`;
   - `.restricted`;
   - reduced accuracy.
8. При изменении authorization вызови `/me/device` и `/me/status`.

Добавь корректные purpose strings, согласованные с реальным поведением:

- `NSLocationWhenInUseUsageDescription`;
- `NSLocationAlwaysAndWhenInUseUsageDescription` — только если Always действительно используется.

Если background tracking требуется, включи Background Modes → Location updates. Не включай лишние background modes. `showsBackgroundLocationIndicator` должен быть включён для прозрачности подходящего режима. Purpose strings не должны обещать то, чего приложение не делает.

## API

Реализуй Codable DTO и repository:

```text
GET  /api/location-tracking/me/policy
PUT  /api/location-tracking/me/device
POST /api/location-tracking/me/points
POST /api/location-tracking/me/status
GET  /api/location-tracking/me/current
```

Policy:

```json
{
  "eligible_for_location_permission": true,
  "should_request_location_permission": true,
  "tracking_enabled": true,
  "mode": "work_schedule",
  "timezone": "Asia/Dushanbe",
  "schedule": {},
  "should_track_now": true,
  "foreground_interval_sec": 30,
  "background_interval_sec": 120,
  "min_distance_m": 75,
  "require_background_permission": true,
  "policy_version": 1,
  "tracked_roles": ["agent", "mop"]
}
```

Используй существующую стратегию `keyDecodingStrategy` проекта; не вводи глобальное изменение JSONDecoder, способное сломать другие API.

## Архитектура

Адаптируй названия к стилю проекта:

- `LocationEligibilityGate`;
- `LocationTrackingCoordinator`;
- `LocationAuthorizationClient`;
- `LocationTrackingRepository`;
- `LocationPointStore`;
- `LocationPolicySynchronizer`.

Оберни `CLLocationManager` протоколом, чтобы unit-тесты могли доказать, что authorization API ни разу не вызывается для нецелевых ролей.

Пример состояний:

```swift
enum LocationTrackingState: Equatable {
    case ineligibleRole
    case policyDisabled
    case outsideSchedule
    case disclosureRequired
    case whenInUseRequired
    case alwaysRequired
    case tracking
    case degraded(LocationDegradedReason)
}
```

UI, coordinator, app lifecycle handlers и background restoration обязаны проверять один и тот же gate.

## Device

Создай UUID установки и храни в Keychain. Не используй IDFA или аппаратный fingerprint.

Отправляй:

```http
PUT /api/location-tracking/me/device
```

с:

- `platform=ios`;
- app version;
- iOS version;
- текущим permission status;
- наличием background permission;
- применённой policy version.

## Core Location

- `desiredAccuracy` и `distanceFilter` выводи из policy и доступной точности;
- серверный `min_distance_m` используй для `distanceFilter`;
- не обещай точный интервал: iOS не гарантирует периодический callback;
- при `should_track_now=false` останови location updates;
- при logout/inactive user/смене роли останови всё немедленно;
- при policy disabled останови updates и background session;
- для стандартных updates корректно выставляй `allowsBackgroundLocationUpdates`;
- не включай `pausesLocationUpdatesAutomatically=false` без доказанной необходимости;
- не используй significant-change/visits только ради обхода ограничений;
- при восстановлении процесса сначала восстанови auth и role/policy gate;
- не записывай CLLocation в console, analytics или crash breadcrumbs.

Для точки передавай:

- UUID `event_id`;
- coordinate;
- horizontalAccuracy;
- altitude;
- speed, если valid;
- course, если valid;
- `captured_at` из `CLLocation.timestamp` в UTC ISO 8601;
- `app_state`;
- battery, если доступна без нежелательных побочных эффектов;
- source `gps|network|unknown`.

Отбрасывай CLLocation с отрицательной horizontalAccuracy. Низкую точность не скрывай: сервер сам выставляет quality.

## Offline store

- максимум 2 000 точек или 72 часа;
- привязка к user ID и installation UUID;
- данные защищены iOS Data Protection и выбранным persistence проекта;
- batch максимум 50;
- удалять только `accepted` и `duplicates`;
- rejected обрабатывать по code без бесконечного retry;
- exponential backoff с jitter;
- очередь одного пользователя никогда не отправляется токеном другого;
- logout останавливает сбор до очистки/изоляции очереди.

## Ошибки

- `401`: остановить tracking и передать управление auth/session flow;
- `403 LOCATION_FORBIDDEN_ROLE`: остановить и сбросить eligibility;
- `403 LOCATION_TRACKING_DISABLED`: остановить до новой policy;
- `403 LOCATION_OUTSIDE_SCHEDULE`: остановить до следующей policy/schedule проверки;
- `409 LOCATION_DEVICE_NOT_REGISTERED`: один раз перерегистрировать device;
- `422`: обработать конкретные rejected events;
- `429`: backoff;
- offline/5xx: оставить точки локально.

## UI

Только для `agent|mop`:

- статус отслеживания;
- рабочее расписание;
- When In Use/Always status;
- precise/reduced accuracy;
- последняя синхронизация;
- количество точек в очереди;
- действие «Настроить геолокацию»;
- переход в Settings после denial.

Для остальных ролей location permission onboarding и настройки отсутствуют.

## App Store submission

Подготовь:

- точные purpose strings;
- App Privacy ответы о precise/coarse location и связи данных с пользователем;
- privacy policy внутри приложения и в App Store Connect;
- review notes, объясняющие рабочую функцию;
- тестовый аккаунт `agent` или `mop`;
- шаги, по которым reviewer открывает disclosure и permission flow;
- описание того, почему background mode необходим, если он включён.

Если background не является реально необходимой core-функцией поставки, предпочти When In Use и не включай Always/background mode.

## Тесты

1. `agent` + policy true → disclosure, затем When In Use только после нажатия.
2. `mop` + policy true → аналогично.
3. Каждая другая роль → ни один метод authorization не вызван.
4. nil/unknown role → authorization не вызван.
5. `agent` + backend eligible false → authorization не вызван.
6. Login сам по себе не показывает системный alert.
7. Denial не повторяет alert на каждом launch.
8. When In Use granted, Always denied → приложение остаётся рабочим и отправляет status.
9. Logout и `agent -> rop` останавливают updates/background session.
10. Relaunch/background restoration повторно проверяет gate.
11. Offline batch сохраняет исходные timestamps.
12. Очередь не пересекается между аккаунтами.
13. Reduced accuracy отображается и передаётся корректно.

Используй spy `LocationAuthorizationClient`, чтобы утверждать `requestWhenInUseCallCount == 0` и `requestAlwaysCallCount == 0` для всех нецелевых ролей.

## Definition of Done

- проект собирается;
- существующие и новые тесты проходят;
- системный permission невозможен до login;
- permission невозможен для ролей кроме `agent|mop`;
- login не вызывает системный alert автоматически;
- пользователь сначала видит disclosure и сам продолжает;
- When In Use и Always разделены;
- background indicator и purpose strings соответствуют фактической работе;
- координаты отсутствуют в логах;
- добавлен README с App Store Connect/reviewer checklist;
- итоговый отчёт содержит изменённые файлы, команды проверки и ограничения текущей версии iOS.

---
