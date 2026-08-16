# Техническое задание: интеграция ZKTeco SpeedFace-V5L-RFID (ZAM230) с Aura

## 1. Назначение

Разработать в backend Aura модуль посещаемости, который принимает события успешной идентификации сотрудников с терминала ZKTeco SpeedFace-V5L-RFID на платформе ZAM230.

Терминал должен отправлять события напрямую в Aura через `TA PUSH / ADMS` по HTTPS. В системе необходимо отображать время прихода, последний проход, способ идентификации, филиал и рассчитанный статус рабочего дня.

Биометрические шаблоны лиц и RFID-ключи в backend Aura не передаются и не хранятся.

## 2. Выбранный вариант интеграции

Используется прямое соединение:

```text
SpeedFace-V5L-RFID → TA PUSH / HTTPS → backend.aura.tj/iclock/* → backend Aura
```

Для учёта посещаемости используется `TA PUSH`, а не `AC PUSH` или `BEST / ZKBio Zlink`.

Используется существующий адрес backend:

```text
https://backend.aura.tj
```

Терминал самостоятельно обращается к служебным маршрутам `/iclock/*`. В настройках устройства нельзя добавлять `/api` или `/iclock/cdata` к адресу сервера.

## 3. Ограничение определения входа и выхода

Один терминал без информации о направлении прохода достоверно сообщает только факт распознавания сотрудника.

Для точного определения входа и выхода необходим один из вариантов:

- выбор сотрудником статуса `Вход` или `Выход`;
- автоматическое переключение статуса терминала по времени;
- отдельные устройства или считыватели для входа и выхода;
- использование первого и последнего прохода за день без утверждения, что сотрудник сейчас находится в офисе.

Для первого этапа применяется следующая логика:

- первый проход за рабочий день — время прихода;
- последний проход — предполагаемое время ухода;
- промежуточные проходы сохраняются;
- статус «сейчас в офисе» не считается достоверным без направления прохода.

## 4. Функциональные требования

Система должна предоставлять:

- приём событий с нескольких терминалов;
- регистрацию и отключение устройств;
- сопоставление пользователя терминала с пользователем Aura;
- журнал всех исходных событий;
- защиту от повторного сохранения;
- определение первого и последнего прохода;
- расчёт опоздания и продолжительности рабочего дня;
- обработку событий, доставленных после восстановления связи;
- фильтры по датам, сотрудникам, филиалам, группам и устройствам;
- просмотр собственных посещений;
- просмотр посещений команды согласно RBAC;
- экспорт CSV/Excel;
- мониторинг доступности терминала;
- журнал административных изменений.

## 5. Структура базы данных

### 5.1. `attendance_devices`

Поля:

- `id`;
- `name`;
- `serial_number`, уникальный;
- `branch_id`;
- `branch_group_id`, nullable;
- `protocol`, значение `ta_push`;
- `timezone`, по умолчанию `Asia/Dushanbe`;
- `firmware_version`;
- `platform`, значение `ZAM230`;
- `device_model`;
- `communication_key`, nullable, хранится зашифрованным;
- `is_active`;
- `last_seen_at`;
- `last_event_at`;
- `clock_drift_seconds`;
- `offline_notified_at`;
- `last_ip`;
- `last_error`;
- `created_at`;
- `updated_at`.

### 5.2. `attendance_device_users`

Поля:

- `id`;
- `device_id`;
- `device_user_id`;
- `user_id`;
- `card_number`, nullable;
- `is_active`;
- `mapped_by`;
- `mapped_at`;
- `created_at`;
- `updated_at`.

Ограничения:

```text
unique(device_id, device_user_id)
```

Рекомендуется использовать одинаковые идентификаторы:

```text
Device User ID = users.id в Aura
```

Отдельная таблица сопоставления обязательна даже при совпадающих ID.

### 5.3. `attendance_work_schedules`

Индивидуальные графики сотрудников.

Поля:

- `user_id`, уникальный;
- `timezone`;
- `schedule`, JSON с днями недели, началом, окончанием и допустимым опозданием;
- `holidays`, JSON-массив дат;
- `configured_by`;
- `change_reason`;
- `created_at`;
- `updated_at`.

### 5.4. `attendance_ingest_requests`

Неизменяемый журнал полных HTTP-запросов ATTLOG до запуска парсера. Он позволяет расследовать несовместимость конкретной прошивки, включая полностью отклонённые запросы.

Поля:

- `device_id`;
- `payload_hash`;
- `raw_payload`;
- `request_meta` с удалёнными секретами;
- `source_ip`;
- `received_at`;
- `processing_status`;
- счётчики принятых, повторных, несопоставленных и отклонённых строк.

### 5.5. `attendance_raw_events`

Неизменяемый журнал входящих событий.

Поля:

- `id`;
- `device_id`;
- `ingest_request_id`;
- `event_hash`, уникальный;
- `device_user_id`;
- `occurred_at_local`;
- `occurred_at_utc`;
- `attendance_status`;
- `verify_mode`;
- `work_code`;
- `raw_payload`;
- `source_ip`;
- `received_at`;
- `processing_status`;
- `processing_error`;
- `created_at`;
- `updated_at`.

`event_hash` вычисляется из серийного номера, ID пользователя, времени события, статуса и способа идентификации.

### 5.6. `attendance_events`

Нормализованные события.

Поля:

- `id`;
- `raw_event_id`;
- `user_id`;
- `device_id`;
- `branch_id`;
- `branch_group_id`;
- `event_type`: `check_in`, `check_out`, `punch`, `unknown`;
- `occurred_at`;
- `verification_method`;
- `direction`;
- `is_duplicate`;
- `meta`;
- `created_at`;
- `updated_at`.

### 5.7. `attendance_daily_summaries`

Поля:

- `id`;
- `user_id`;
- `work_date`;
- `first_in_at`;
- `last_out_at`;
- `first_event_id`;
- `last_event_id`;
- `events_count`;
- `device_ids`, список терминалов, участвовавших в расчёте дня;
- `worked_minutes`;
- `late_minutes`;
- `status`: `present`, `late`, `absent`, `incomplete`;
- `created_at`;
- `updated_at`.

Ограничение:

```text
unique(user_id, work_date)
```

## 6. Device API

Публичные маршруты совместимости с TA PUSH:

```http
GET|POST /iclock/cdata
GET      /iclock/getrequest
POST     /iclock/devicecmd
GET|POST /iclock/registry
GET      /iclock/ping
```

Основной маршрут первого этапа:

```http
POST /iclock/cdata?SN={serial_number}&table=ATTLOG
Content-Type: text/plain
```

Требования:

- маршруты не используют Sanctum и CSRF;
- устройство определяется по `SN`;
- неизвестный или отключённый серийный номер отклоняется;
- исходное тело сохраняется до разбора;
- поддерживается несколько строк событий в одном запросе;
- максимальный размер запроса ограничивается, например 256 КБ;
- ответ терминалу возвращается как `text/plain`;
- обработка является идемпотентной;
- точный формат полей фиксируется после получения реального запроса от ZAM230.

Парсер реализуется через отдельный протокольный адаптер:

```php
interface AttendanceDeviceProtocol
{
    public function parse(string $payload, array $query): array;
}
```

```php
final class ZktecoTaPushProtocol implements AttendanceDeviceProtocol
{
    // Парсер формата, подтверждённого реальным запросом ZAM230.
}
```

## 7. Внутренний API Aura

```http
GET    /api/attendance/devices
POST   /api/attendance/devices
PATCH  /api/attendance/devices/{device}

GET    /api/attendance/device-users
PUT    /api/attendance/device-users

GET    /api/attendance/events
GET    /api/attendance/daily
GET    /api/attendance/me
GET    /api/attendance/users/{user}/daily

POST   /api/attendance/events/reprocess
GET    /api/attendance/unmapped-events
GET    /api/attendance/export

GET    /api/attendance/users/{user}/schedule
PUT    /api/attendance/users/{user}/schedule
```

Фильтры:

- `date_from`;
- `date_to`;
- `user_id`;
- `branch_id`;
- `branch_group_id`;
- `device_id`;
- `status`;
- `late`;
- `unmapped`.

## 8. RBAC

Используется существующая ролевая и филиальная модель Aura:

- `agent`, `intern` — только собственные посещения;
- `mop` — собственные посещения и посещения агентов своей группы;
- `rop`, `branch_director` — сотрудники своего филиала;
- `hr` — доступ согласно текущим полномочиям HR;
- `admin`, `superadmin`, `owner` — полный доступ;
- создание устройств и изменение сопоставлений — только административные роли;
- все изменения сопоставлений записываются в аудит.

## 9. Правила обработки рабочего дня

Настройки:

- часовой пояс `Asia/Dushanbe`;
- начало и окончание рабочего дня;
- допустимое опоздание;
- рабочие дни;
- праздничные дни;
- индивидуальный график;
- интервал определения дубля, например 10 секунд.

Алгоритм:

1. Сохранить исходное событие.
2. Проверить точный дубль по `event_hash`.
3. Сопоставить `device_user_id` с `users.id`.
4. Преобразовать локальное время устройства в UTC.
5. Определить тип события по статусу терминала.
6. Если статус отсутствует, сохранить событие как `punch`.
7. Пересчитать дневную сводку.
8. Первый действительный проход считать приходом.
9. Последний действительный проход считать предполагаемым уходом.
10. Позднее офлайн-событие должно пересчитать прошлую сводку.

## 10. Безопасность

- использовать существующий домен `backend.aura.tj` и изолировать device API префиксом `/iclock/*`;
- использовать HTTPS и TLS 1.2+;
- установить полный certificate chain;
- принимать запросы только от зарегистрированных серийных номеров;
- использовать communication key, если он поддерживается прошивкой;
- при наличии статического IP офиса добавить IP allowlist;
- настроить rate limit с учётом пакетной доставки после офлайна;
- не сохранять биометрические шаблоны;
- отключить фотографии событий на первом этапе;
- ограничить срок хранения raw payload;
- вести аудит административных операций;
- уведомлять администратора, если устройство не выходило на связь более 10 минут;
- контролировать расхождение часов устройства и сервера.

## 11. План разработки

### Этап 1. Проверка протокола

- зарегистрировать терминал в backend;
- добавить временные `/iclock/*` endpoints;
- включить TA PUSH;
- получить запрос регистрации или heartbeat;
- выполнить одно распознавание;
- сохранить method, query, headers и raw body;
- создать тестовый fixture реального формата ZAM230.

### Этап 2. Приём событий

- создать миграции и модели;
- реализовать публичный TA PUSH controller;
- добавить проверку устройства;
- реализовать raw-журнал;
- реализовать адаптер протокола;
- добавить дедупликацию;
- добавить очередь нормализации;
- обновлять `last_seen_at`.

### Этап 3. Сопоставление сотрудников

- реализовать сопоставление Device User ID с Aura User;
- добавить список несопоставленных событий;
- добавить ручное назначение сотрудника;
- добавить аудит изменений.

### Этап 4. Посещаемость

- реализовать первый и последний проход;
- реализовать опоздания;
- реализовать неполный рабочий день;
- добавить графики работы;
- реализовать пересчёт поздних событий.

### Этап 5. Интерфейс

- экран «Мои посещения»;
- экран «Посещения команды»;
- фильтры филиала и группы;
- состояние терминалов;
- список несопоставленных событий;
- экспорт CSV/Excel.

### Этап 6. Пилот

- подключить один терминал;
- зарегистрировать 2–3 тестовых агента;
- проверить онлайн-доставку;
- проверить офлайн-накопление;
- восстановить сеть;
- убедиться, что события доставлены без дублей;
- после успешного пилота зарегистрировать остальных агентов.

## 12. Инструкция по настройке терминала

Настройку Cloud Server необходимо выполнять после публикации `/iclock/*` на сервере.

### 12.1. Сеть

На терминале открыть:

```text
Главное меню → COMM. → Ethernet
```

Настроить:

- статический IP или DHCP reservation;
- Subnet Mask;
- Gateway;
- DNS;
- `Display in Status Bar` — включить.

Терминал должен разрешать DNS-имя `backend.aura.tj` и иметь исходящий доступ на TCP 443.

### 12.2. Время

```text
Timezone: GMT+05:00 / Asia-Dushanbe
DST: Off
NTP: On
```

Перед включением HTTPS необходимо проверить дату и время терминала.

### 12.3. Протокол

В настройках платформы или протокола выбрать:

```text
TA PUSH
```

Не выбирать:

```text
AC PUSH
BEST / ZKBio Zlink
```

Название пункта может отличаться между сборками ZAM230. Если переключатель отсутствует, необходимо запросить у поставщика прошивку с активированным TA PUSH.

### 12.4. Cloud Server Setting

Открыть:

```text
Главное меню → COMM. → Cloud Server Setting
```

Установить:

```text
Server Mode: ADMS
Enable Domain Name: On
Server Address: backend.aura.tj
Server Port: 443
HTTPS: On
Proxy Server: Off
Display in Status Bar: On
```

Если поле требует полный URL:

```text
https://backend.aura.tj
```

Путь `/api` или `/iclock/cdata` не вводится.

### 12.5. Регистрация агентов

Для каждого агента:

```text
User ID = users.id в Aura
Name = имя агента
Role = Normal User
Face/RFID = зарегистрировать на терминале
```

Не использовать номер телефона как User ID.

Пример:

```text
Aura users.id: 163
Device User ID: 163
Имя: Ахмедов Саидиқбол
```

### 12.6. Проверка

1. Проверить индикатор подключения к ADMS.
2. Зарегистрировать тестового пользователя.
3. Выполнить распознавание лицом.
4. Выполнить распознавание RFID-картой.
5. Проверить `last_seen_at` устройства.
6. Проверить raw event.
7. Проверить сопоставление с агентом.
8. Проверить появление события в интерфейсе.
9. Отключить сеть и выполнить проход.
10. Восстановить сеть и проверить доставку без дублей.

## 13. Критерии приёмки

- онлайн-событие появляется в Aura не позднее 10 секунд;
- повторная отправка не создаёт дубль;
- офлайн-события принимаются после восстановления сети;
- неизвестное устройство не может записывать события;
- RBAC ограничивает данные филиалом и группой;
- время корректно сохраняется для `Asia/Dushanbe`;
- биометрические шаблоны не передаются в Aura;
- первый и последний проход отображаются корректно;
- администратор видит состояние устройства;
- администратор видит несопоставленные события;
- позднее событие пересчитывает дневную сводку;
- все изменения устройств и сопоставлений записываются в аудит.

## 14. Официальные источники

- ZKTeco SpeedFace-V5L Series: https://www.zkteco.com/en/SpeedFaceSeries/SpeedFace-V5L-Series
- ZKTeco SpeedFace-V5L product page (ADMS, AC PUSH / TA PUSH switch): https://www.zkteco.me/product-details/speedface-v5l
- ZKBio Time: https://www.zkteco.com/en/ZKBio_Time/ZKBioTime
- Руководство SpeedFace-V5L: https://www.zkteco.me/download-file/1791
- Руководство SpeedFace-V5L-RFID, раздел Cloud Server Setting: https://www.zkteco.me/download-file/2104

## 15. Реализованное обновление backend

Реализация находится в текущем backend Aura и использует существующий домен без дополнительного поддомена.

Добавлены:

- корневые TA PUSH endpoints `/iclock/*`;
- регистрация нескольких терминалов;
- зашифрованный communication key;
- необязательный IP allowlist;
- сохранение полного входящего ATTLOG-запроса до парсинга;
- построчный протокольный адаптер;
- точная и семантическая дедупликация;
- очередь нормализации с повторными попытками;
- список несопоставленных ID и повторная обработка после сопоставления;
- дневные сводки, опоздания, индивидуальные графики и праздники;
- пересчёт поздних офлайн-событий;
- API с существующим RBAC Aura;
- CSV-экспорт;
- аудит устройств, сопоставлений и графиков;
- статус online/offline, контроль часов и уведомление администраторов;
- автоматическая очистка raw payload по сроку хранения.

## 16. Развёртывание

### 16.1. Backend

После публикации кода выполнить:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
```

Очередь и планировщик обязательны:

```bash
php artisan queue:work --queue=default --tries=5
php artisan schedule:work
```

На production их необходимо запускать через Supervisor/systemd и cron соответственно. Если основной scheduler уже настроен через cron, отдельный `schedule:work` не нужен:

```cron
* * * * * cd /path/to/aura-estate && php artisan schedule:run >> /dev/null 2>&1
```

Переменные окружения:

```dotenv
ATTENDANCE_TIMEZONE=Asia/Dushanbe
ATTENDANCE_DEVICE_REQUEST_MAX_BYTES=262144
ATTENDANCE_DEVICE_RATE_LIMIT_PER_MINUTE=240
ATTENDANCE_DUPLICATE_WINDOW_SECONDS=10
ATTENDANCE_OFFLINE_THRESHOLD_MINUTES=10
ATTENDANCE_CLOCK_DRIFT_WARNING_SECONDS=300
ATTENDANCE_CLOCK_DRIFT_MEASUREMENT_WINDOW_SECONDS=1800
ATTENDANCE_RAW_RETENTION_DAYS=90
ATTENDANCE_QUEUE_STALE_AFTER_MINUTES=15
ATTENDANCE_ALLOWED_IPS=
```

`ATTENDANCE_ALLOWED_IPS` оставляется пустым, если у офиса нет постоянного публичного IP. При наличии статического IP указываются адреса через запятую.
Если backend работает за reverse proxy/CDN, сначала необходимо корректно настроить trusted proxies и проверить значение `last_ip`; иначе allowlist может сравнивать адрес прокси вместо адреса офиса.

В Nginx/Apache маршруты `/iclock/*` должны попадать в тот же Laravel `public/index.php`, что и `/api/*`. Нельзя ограничивать проксирование только префиксом `/api`.

### 16.2. Первичная регистрация устройства в Aura

Администратор создаёт терминал через защищённый API:

```http
POST /api/attendance/devices
Authorization: Bearer {admin_token}
Content-Type: application/json

{
  "name": "Главный вход",
  "serial_number": "{SN_ИЗ_МЕНЮ_ТЕРМИНАЛА}",
  "branch_id": 2,
  "branch_group_id": null,
  "timezone": "Asia/Dushanbe",
  "device_model": "SpeedFace-V5L-RFID",
  "platform": "ZAM230",
  "communication_key": "{случайный_секрет}",
  "is_active": true
}
```

Серийный номер должен полностью совпадать со значением `SN`, которое отправляет терминал. Секрет в ответах API не возвращается.

Затем создаётся сопоставление пользователя:

```http
PUT /api/attendance/device-users
Authorization: Bearer {admin_token}
Content-Type: application/json

{
  "device_id": 1,
  "device_user_id": "163",
  "user_id": 163,
  "is_active": true
}
```

### 16.3. Проверка после настройки терминала

Проверить по порядку:

1. `GET /api/attendance/devices` показывает свежий `last_seen_at` и `connection_status=online`.
2. Распознавание агента создаёт запись в `/api/attendance/events`.
3. `/api/attendance/daily` показывает первый проход и дневной статус.
4. Неизвестный User ID появляется в `/api/attendance/unmapped-events`.
5. После создания сопоставления событие автоматически нормализуется.
6. Повторная отправка того же ATTLOG не увеличивает число нормализованных событий.
7. После отключения сети терминала и её восстановления прошлые события доставляются и пересчитывают нужную дату.

После регистрации устройства можно выполнить безопасную проверку deployment без создания посещения:

```bash
ATTENDANCE_TEST_SERIAL='SERIAL_FROM_DEVICE' \
ATTENDANCE_TEST_COMM_KEY='COMMUNICATION_KEY' \
ATTENDANCE_ADMIN_TOKEN='ADMIN_SANCTUM_TOKEN' \
./scripts/verify-attendance-deployment.sh
```

Скрипт проверяет HTTPS-маршруты, отклонение неизвестного SN, handshake зарегистрированного терминала и наличие устройства во внутреннем API. ATTLOG он не отправляет и данные посещаемости не изменяет.

До получения первого реального ATTLOG от данного экземпляра ZAM230 формат парсера считается предварительно совместимым. Для завершения пилота необходимо сохранить фактические query-параметры и raw body терминала и добавить их как отдельный тестовый fixture без биометрических данных.

## 17. Отчёт локальной приёмки от 2026-08-16

Проверочная команда:

```bash
php artisan test \
  tests/Feature/AttendanceModuleFeatureTest.php \
  tests/Unit/ZktecoTaPushProtocolTest.php \
  tests/Feature/ApiRequestLogFeatureTest.php
```

Результат: `33 passed`, `158 assertions`.

Автоматически проверены:

- root routing и `text/plain` ответы TA PUSH;
- неизвестный и отключённый терминал;
- ограничение размера тела и rate limit;
- communication key, IP allowlist и удаление секретов из логов;
- полный raw-журнал и частично повреждённые пакеты;
- синхронный и реальный отложенный queue flow;
- восстановление зависшей очереди без повторной постановки свежих задач;
- точная и семантическая дедупликация;
- несопоставленные события и повторная обработка;
- поздние офлайн-события;
- первый/последний проход и неполный день при одной отметке;
- графики, праздники, опоздания и отсутствия;
- RBAC агента, МОП, РОП и администратора;
- фильтры устройства в дневном отчёте и CSV;
- аудит административных операций;
- создание offline-уведомления и сброс после восстановления связи;
- контроль расхождения часов;
- удаление raw payload без удаления нормализованного посещения.

Полный набор репозитория на момент проверки: `397 passed`, `44 failed`. Падения воспроизводятся отдельно от модуля посещаемости и относятся к существующим тестовым схемам CRM/KPI (например, отсутствующие в тестовой SQLite-схеме столбцы и старые ожидания KPI).

Read-only проверка `https://backend.aura.tj/iclock/cdata` на 2026-08-16 вернула `404 NOT_FOUND`. Следовательно, реализация проверена локально, но ещё не опубликована на production.
