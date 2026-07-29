# Готовый промпт для реализации Android: местоположение агентов и МОПов

Скопируй весь текст ниже в отдельную задачу Android-разработчику или coding agent.

---

Ты работаешь над существующим Android-приложением Aura Estate. Реализуй production-ready модуль передачи местоположения сотрудников, интегрированный с текущей архитектурой приложения. Сначала изучи проект, его DI, навигацию, auth/session storage, Retrofit/OkHttp, Room и background-work паттерны. Не заменяй существующую архитектуру без необходимости.

Backend-контракт описан в `docs/user-location-tracking-spec.md`. Base path: `/api/location-tracking`.

## Критическое правило ролей и публикации в Google Play

Геолокация требуется только пользователям с backend-ролью:

- `agent`;
- `mop`.

Для любой другой роли, включая `admin`, `superadmin`, `rop`, `branch_director`, `manager`, `operator`, `marketing`, `hr`, `client`, `external_agent`, приложение обязано:

- никогда не вызывать Android runtime location permission launcher;
- никогда не показывать системный location permission dialog;
- не создавать и не запускать location foreground service;
- не планировать location worker;
- не обращаться к `FusedLocationProviderClient` за координатами;
- скрыть экран включения отслеживания;
- остановить существующий tracking, если роль изменилась;
- удалить неотправленные точки предыдущего пользователя при logout или смене аккаунта.

Проверка должна быть двухступенчатой и fail-closed:

```kotlin
val roleEligible = session.user.role.slug in setOf("agent", "mop")
val serverEligible = policy.eligibleForLocationPermission
val mayEnterPermissionFlow =
    roleEligible &&
    serverEligible &&
    policy.shouldRequestLocationPermission &&
    policy.trackingEnabled
```

Если auth role отсутствует, неизвестна, ещё загружается или backend policy недоступна, считать пользователя не eligible и ничего не запрашивать.

Нельзя просить permission прямо на splash screen, до авторизации или автоматически одновременно с успешным login. После входа `agent|mop`:

1. получить `GET /api/location-tracking/me/policy`;
2. проверить оба role/policy gate;
3. в контексте рабочего раздела показать собственный prominent disclosure;
4. дать кнопки «Согласиться и продолжить» и «Не сейчас»;
5. только после явного нажатия «Согласиться и продолжить» вызвать системный permission dialog.

Текст disclosure должен быть видим целиком до системного диалога и объяснять:

- какие данные собираются: точное местоположение;
- зачем: отображение рабочей позиции и маршрута уполномоченным руководителям;
- когда: только для ролей агент/МОП и согласно рабочему расписанию;
- что background-сбор может продолжаться, когда приложение свёрнуто или не используется;
- что пользователь может отказать и позже изменить разрешение в настройках.

Пример текста для согласования с владельцем продукта:

> Aura Estate собирает данные о местоположении, чтобы сохранять рабочую позицию и маршрут агента или МОПа и показывать их уполномоченному руководителю. Во время включённого рабочего отслеживания данные могут собираться, когда приложение свёрнуто или не используется. Отслеживание действует только по рабочему расписанию. Разрешение можно не выдавать или изменить позже в настройках устройства.

Не копируй дизайн системного permission dialog. Disclosure должен выглядеть как обычный экран Aura Estate.

## Permission flow

Запрашивай минимально необходимый доступ поэтапно:

1. Сначала foreground location:
   - `ACCESS_COARSE_LOCATION`;
   - `ACCESS_FINE_LOCATION`.
2. Обработай approximate-only без цикла повторных запросов.
3. Background location запрашивай отдельным шагом только если:
   - role/policy gate всё ещё true;
   - `policy.requireBackgroundPermission=true`;
   - foreground permission уже выдан;
   - пользователь видел отдельное объяснение и сам нажал продолжить.
4. Для версий Android, где background permission выдаётся через Settings, покажи корректный educational UI и открой нужный системный экран.
5. Не запрашивай foreground и background в одном системном запросе.
6. При отказе сохрани статус, отправь `/me/status`, продолжай работу приложения без tracking и не показывай permission при каждом запуске.
7. Повторный flow запускается только явным действием пользователя «Настроить геолокацию».

До использования background location проверь актуальные требования target SDK и Google Play. Background location должна быть заявлена как core functionality, описана в store listing, Permissions Declaration Form, Data Safety и privacy policy. Подготовь reviewer flow и тестовые credentials для роли `agent` или `mop`. Не утверждай, что одна ролевая проверка гарантирует одобрение Google Play.

Официальные требования:

- https://support.google.com/googleplay/android-developer/answer/9799150
- https://support.google.com/googleplay/android-developer/answer/11150561
- https://developer.android.com/develop/sensors-and-location/location/permissions
- https://developer.android.com/develop/sensors-and-location/location/permissions/background

## API

Реализуй DTO, repository и обработку ошибок для:

```text
GET  /api/location-tracking/me/policy
PUT  /api/location-tracking/me/device
POST /api/location-tracking/me/points
POST /api/location-tracking/me/status
GET  /api/location-tracking/me/current
```

Policy содержит:

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

Используй `snake_case` mapping согласно текущему JSON-конвертеру проекта.

## Архитектура

Создай компоненты с названиями, адаптированными к проекту:

- `LocationEligibilityGate`;
- `LocationTrackingCoordinator`;
- `LocationPermissionController`;
- `LocationTrackingRepository`;
- `LocationPointQueue`;
- `LocationForegroundService`;
- `LocationPolicySynchronizer`.

`LocationEligibilityGate` — единственная точка принятия решения о permission flow и запуске tracking. UI, Service, Worker и receiver обязаны повторно проверять gate, а не доверять только скрытой кнопке.

Состояния:

```kotlin
sealed interface LocationTrackingState {
    data object IneligibleRole : LocationTrackingState
    data object PolicyDisabled : LocationTrackingState
    data object OutsideSchedule : LocationTrackingState
    data object DisclosureRequired : LocationTrackingState
    data object ForegroundPermissionRequired : LocationTrackingState
    data object BackgroundPermissionRequired : LocationTrackingState
    data object Tracking : LocationTrackingState
    data class Degraded(val reason: Reason) : LocationTrackingState
}
```

## Device registration

Создай стабильный `device_uuid` на установку и храни в защищённом storage. Не используй рекламный ID, IMEI, серийный номер или другой аппаратный идентификатор.

После выдачи/изменения permission отправляй:

```http
PUT /api/location-tracking/me/device
```

с platform `android`, app/os version, permission status, background permission и применённой policy version.

## Сбор координат

- используй `FusedLocationProviderClient`;
- интервалы и minimum distance бери только из policy;
- сбор запускается только при `should_track_now=true`;
- при переходе policy в false немедленно останови updates и foreground service;
- foreground service должен иметь видимое постоянное уведомление и корректный service type `location`;
- не скрывай уведомление;
- при logout, inactive session или смене роли немедленно останови сервис;
- receiver/worker после перезапуска процесса обязан повторно загрузить сессию и проверить роль/policy;
- не запускай бесконечный worker вместо допустимого location-механизма ОС.

Каждой точке присваивай UUID `event_id`. Передавай UTC `captured_at`, accuracy, source, app state, battery при доступности и mock flag, если API устройства его предоставляет.

## Offline queue

- локальная очередь максимум 2 000 точек или 72 часа;
- записи привязаны к user ID и device UUID;
- пакет отправки максимум 50;
- удалять только `accepted` и `duplicates`;
- `rejected` сохранять/удалять согласно коду без бесконечного retry;
- retry с exponential backoff и jitter;
- очередь предыдущего пользователя нельзя отправить от имени новой сессии;
- не писать координаты в Logcat, Crashlytics breadcrumbs или analytics.

## Обработка ошибок

- `401`: остановить tracking, инициировать штатный session-expired flow;
- `403 LOCATION_FORBIDDEN_ROLE`: остановить и пометить роль не eligible;
- `403 LOCATION_TRACKING_DISABLED`: остановить до новой policy;
- `403 LOCATION_OUTSIDE_SCHEDULE`: остановить до следующего рассчитанного интервала/policy refresh;
- `409 LOCATION_DEVICE_NOT_REGISTERED`: повторить device registration один раз;
- `422` для отдельной точки: не блокировать остальные;
- `429`: backoff;
- network/5xx: оставить пакет в очереди.

## UI

Для `agent|mop` добавь экран состояния:

- включено/выключено;
- время рабочего отслеживания;
- текущий permission;
- background permission;
- последняя отправка;
- размер офлайн-очереди;
- кнопка «Настроить геолокацию»;
- кнопка перехода в системные настройки после permanent denial.

Для остальных ролей этот экран и permission CTA не показывать.

## Тесты

Обязательные unit/UI/integration сценарии:

1. `agent` + policy true → disclosure доступен, системный запрос только после нажатия.
2. `mop` + policy true → аналогично.
3. Каждая другая роль → permission launcher не вызывается ни разу.
4. Неизвестная роль/null role → permission launcher не вызывается.
5. `agent` + server eligible false → permission launcher не вызывается.
6. Logout во время tracking → сервис остановлен, очередь не переходит другому пользователю.
7. Смена роли `agent -> rop` → tracking остановлен.
8. Foreground granted, background denied → приложение продолжает работать и отправляет технический статус.
9. Offline → точки синхронизируются с исходным `captured_at`.
10. Duplicate подтверждается и удаляется из очереди.
11. Process restart/boot receiver не запускает tracking без повторного gate.
12. Permission denial не вызывает повторный диалог на каждом старте.

Добавь fake permission gateway, fake location provider и fake policy repository, чтобы тестировать отсутствие системного вызова для нецелевых ролей.

## Definition of Done

- код компилируется;
- существующие тесты проходят;
- добавленные тесты проходят;
- runtime permission невозможен до авторизации и невозможен для ролей кроме `agent|mop`;
- prominent disclosure показан перед системным запросом;
- foreground/background permission разделены;
- background service видим пользователю;
- координаты не попадают в логи;
- подготовлен короткий README с Play Console checklist, reviewer credentials flow и сценарием видео;
- в отчёте перечислены изменённые файлы, команды проверки и ограничения конкретной версии Android.

---
