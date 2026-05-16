# Полная API документация (по маршрутам, payload и ответам)

Источник: автоизвлечение из `routes/api.php` + контроллеров. Всего endpoint: 375.

## Общие правила

- Base URL: `https://<host>/api`
- Auth: `Authorization: Bearer <token>` для endpoint с `Auth: yes`
- Формат ошибок валидации: `422` с полем `errors`
- Если payload не указан: endpoint либо без body, либо валидация вынесена в `FormRequest`/внутреннюю логику.

## 1) `GET /api/admin/stories`

- Назначение: Операция контроллера `App\Http\Controllers\AdminStoryController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\AdminStoryController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 2) `PATCH /api/admin/stories/{story}/status`

- Назначение: Операция контроллера `App\Http\Controllers\AdminStoryController@updateStatus`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\AdminStoryController@updateStatus`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 3) `GET /api/agents/{agent}/reviews`

- Назначение: Отправляем SMS-код на номер, чтобы подтвердить владение номером перед добавлением отзыва.
- Auth: no
- Controller: `App\Http\Controllers\ReviewController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 4) `POST /api/agents/{agent}/reviews`

- Назначение: Отправляем SMS-код на номер, чтобы подтвердить владение номером перед добавлением отзыва.
- Auth: no
- Controller: `App\Http\Controllers\ReviewController@store`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 5) `POST /api/b24/token`

- Назначение: Операция контроллера `App\Http\Controllers\B24AuthController@issue`
- Auth: no
- Controller: `App\Http\Controllers\B24AuthController@issue`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['token'=>$jwt,'exp'=>$payload['exp']]`

## 6) `GET /api/bookings`

- Назначение: Операция контроллера `App\Http\Controllers\BookingController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BookingController@index`
- Payload:
  - `agent_id`: `nullable|integer`
  - `branch_id`: `nullable`
  - `branch_group_id`: `nullable`
  - `date_from`: `nullable|string`
  - `date_to`: `nullable|string`
  - `from`: `nullable|string`
  - `to`: `nullable|string`
  - `per_page`: `nullable|integer|min:1|max:100`
  - `page`: `nullable|integer|min:1`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 7) `POST /api/bookings`

- Назначение: Операция контроллера `App\Http\Controllers\BookingController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BookingController@store`
- Payload:
  - `property_id`: `required|exists:properties`
  - `agent_id`: `[`
  - `client_id`: `required|integer|exists:clients`
  - `start_time`: `required|date`
  - `end_time`: `required|date`
  - `note`: `nullable|string`
  - `client_name`: `prohibited`
  - `client_phone`: `prohibited`
  - `deal_id`: `nullable|integer`
  - `contact_id`: `nullable|integer`
  - `place`: `nullable|string`
  - `sync_to_b24`: `sometimes|boolean`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 8) `GET /api/bookings/agents-report`

- Назначение: Операция контроллера `App\Http\Controllers\BookingController@agentsReport`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BookingController@agentsReport`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 9) `GET /api/bookings/{id}`

- Назначение: Операция контроллера `App\Http\Controllers\BookingController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BookingController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$booking`

## 10) `PUT /api/bookings/{id}`

- Назначение: Операция контроллера `App\Http\Controllers\BookingController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BookingController@update`
- Payload:
  - `start_time`: `sometimes|date`
  - `end_time`: `sometimes|date`
  - `note`: `nullable|string`
  - `agent_id`: `[`
  - `client_id`: `sometimes|integer|exists:clients`
  - `client_name`: `prohibited`
  - `client_phone`: `prohibited`
- Response:
  - `403`: `['error' => 'Forbidden'], 403`

## 11) `PATCH /api/bookings/{id}`

- Назначение: Операция контроллера `App\Http\Controllers\BookingController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BookingController@update`
- Payload:
  - `start_time`: `sometimes|date`
  - `end_time`: `sometimes|date`
  - `note`: `nullable|string`
  - `agent_id`: `[`
  - `client_id`: `sometimes|integer|exists:clients`
  - `client_name`: `prohibited`
  - `client_phone`: `prohibited`
- Response:
  - `403`: `['error' => 'Forbidden'], 403`

## 12) `GET /api/branch-groups`

- Назначение: Операция контроллера `App\Http\Controllers\BranchGroupController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BranchGroupController@index`
- Payload:
  - `search`: `nullable|string`
  - `name`: `nullable|string`
  - `branch_id`: `nullable|integer|exists:branches`
  - `contact_visibility_mode`: `['nullable`
  - `per_page`: `nullable|integer|min:1|max:100`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 13) `POST /api/branch-groups`

- Назначение: Операция контроллера `App\Http\Controllers\BranchGroupController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BranchGroupController@store`
- Payload:
  - `branch_id`: `nullable|integer|exists:branches`
  - `name`: `[`
  - `description`: `nullable|string`
  - `contact_visibility_mode`: `['required`
- Response:
  - `200`: `$branchGroup->load('branch'`

## 14) `GET /api/branch-groups/{branch_group}`

- Назначение: Операция контроллера `App\Http\Controllers\BranchGroupController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BranchGroupController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$branchGroup->load('branch'`

## 15) `PUT|PATCH /api/branch-groups/{branch_group}`

- Назначение: Операция контроллера `App\Http\Controllers\BranchGroupController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BranchGroupController@update`
- Payload:
  - `branch_id`: `sometimes|integer|exists:branches`
  - `name`: `[`
  - `description`: `sometimes|nullable|string`
  - `contact_visibility_mode`: `['sometimes`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 16) `DELETE /api/branch-groups/{branch_group}`

- Назначение: Операция контроллера `App\Http\Controllers\BranchGroupController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BranchGroupController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `409`: `[ 'message' => 'Нельзя удалить группу: к ней привязаны пользователи или контакты.', ], 409`

## 17) `GET /api/branches`

- Назначение: Операция контроллера `App\Http\Controllers\BranchController@index`
- Auth: no
- Controller: `App\Http\Controllers\BranchController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `Branch::query(`

## 18) `POST /api/branches`

- Назначение: Операция контроллера `App\Http\Controllers\BranchController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BranchController@store`
- Payload:
  - `name`: `required|string|max:255`
  - `lat`: `nullable|numeric|between:-90`
  - `lng`: `nullable|numeric|between:-180`
  - `landmark`: `nullable|string|max:255`
  - `photo`: `nullable|image|mimes:jpg`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 19) `GET /api/branches/{branch}`

- Назначение: Операция контроллера `App\Http\Controllers\BranchController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BranchController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$branch`

## 20) `PUT|PATCH /api/branches/{branch}`

- Назначение: Операция контроллера `App\Http\Controllers\BranchController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BranchController@update`
- Payload:
  - `name`: `sometimes|required|string|max:255`
  - `lat`: `nullable|numeric|between:-90`
  - `lng`: `nullable|numeric|between:-180`
  - `landmark`: `nullable|string|max:255`
  - `photo`: `nullable|image|mimes:jpg`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 21) `DELETE /api/branches/{branch}`

- Назначение: Операция контроллера `App\Http\Controllers\BranchController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BranchController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `409`: `[ 'message' => 'Нельзя удалить филиал: к нему привязаны пользователи', ], 409`

## 22) `GET /api/building-types`

- Назначение: Операция контроллера `App\Http\Controllers\BuildingTypeController@index`
- Auth: no
- Controller: `App\Http\Controllers\BuildingTypeController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 23) `POST /api/building-types`

- Назначение: Операция контроллера `App\Http\Controllers\BuildingTypeController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BuildingTypeController@store`
- Payload:
  - `name`: `required|string`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 24) `GET /api/building-types/{building_type}`

- Назначение: Операция контроллера `App\Http\Controllers\BuildingTypeController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BuildingTypeController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `Laravel resource/model response`

## 25) `PUT|PATCH /api/building-types/{building_type}`

- Назначение: Операция контроллера `App\Http\Controllers\BuildingTypeController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BuildingTypeController@update`
- Payload:
  - `name`: `required|string`
- Response:
  - `200`: `Laravel resource/model response`

## 26) `DELETE /api/building-types/{building_type}`

- Назначение: Операция контроллера `App\Http\Controllers\BuildingTypeController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\BuildingTypeController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 27) `POST /api/chat`

- Назначение: Операция контроллера `App\Http\Controllers\ChatController@handle`
- Auth: no
- Controller: `App\Http\Controllers\ChatController@handle`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 28) `POST /api/chat/escalate`

- Назначение: Операция контроллера `App\Http\Controllers\SupportConversationController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\SupportConversationController@store`
- Payload:
  - `chat_session_id`: `nullable|string|max:100`
  - `summary`: `nullable|string|max:5000`
  - `meta`: `nullable|array`
- Response:
  - `200`: `$this->serializeThread($thread`

## 29) `POST /api/chat/feedback`

- Назначение: Операция контроллера `App\Http\Controllers\ChatController@feedback`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ChatController@feedback`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 30) `GET /api/chat/history`

- Назначение: Операция контроллера `App\Http\Controllers\ChatController@history`
- Auth: no
- Controller: `App\Http\Controllers\ChatController@history`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 31) `GET /api/client-need-statuses`

- Назначение: Операция контроллера `App\Http\Controllers\ClientNeedStatusController@index`
- Auth: no
- Controller: `App\Http\Controllers\ClientNeedStatusController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `ClientNeedStatus::query(`

## 32) `POST /api/client-need-statuses`

- Назначение: Операция контроллера `App\Http\Controllers\ClientNeedStatusController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientNeedStatusController@store`
- Payload:
  - `name`: `required|string|max:255|unique:client_need_statuses`
  - `slug`: `required|string|max:255|unique:client_need_statuses`
  - `is_closed`: `sometimes|boolean`
  - `sort_order`: `sometimes|integer|min:0`
  - `is_active`: `sometimes|boolean`
- Response:
  - `201`: `$status, 201`

## 33) `GET /api/client-need-statuses/{client_need_status}`

- Назначение: Операция контроллера `App\Http\Controllers\ClientNeedStatusController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientNeedStatusController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$clientNeedStatus`

## 34) `PUT|PATCH /api/client-need-statuses/{client_need_status}`

- Назначение: Операция контроллера `App\Http\Controllers\ClientNeedStatusController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientNeedStatusController@update`
- Payload:
  - `name`: `sometimes|string|max:255|unique:client_need_statuses`
  - `slug`: `sometimes|string|max:255|unique:client_need_statuses`
  - `is_closed`: `sometimes|boolean`
  - `sort_order`: `sometimes|integer|min:0`
  - `is_active`: `sometimes|boolean`
- Response:
  - `200`: `$clientNeedStatus`

## 35) `DELETE /api/client-need-statuses/{client_need_status}`

- Назначение: Операция контроллера `App\Http\Controllers\ClientNeedStatusController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientNeedStatusController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['message' => 'Удалено']`

## 36) `GET /api/client-need-types`

- Назначение: Операция контроллера `App\Http\Controllers\ClientNeedTypeController@index`
- Auth: no
- Controller: `App\Http\Controllers\ClientNeedTypeController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `ClientNeedType::query(`

## 37) `POST /api/client-need-types`

- Назначение: Операция контроллера `App\Http\Controllers\ClientNeedTypeController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientNeedTypeController@store`
- Payload:
  - `name`: `required|string|max:255|unique:client_need_types`
  - `slug`: `required|string|max:255|unique:client_need_types`
  - `sort_order`: `sometimes|integer|min:0`
  - `is_active`: `sometimes|boolean`
- Response:
  - `201`: `$type, 201`

## 38) `GET /api/client-need-types/{client_need_type}`

- Назначение: Операция контроллера `App\Http\Controllers\ClientNeedTypeController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientNeedTypeController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$clientNeedType`

## 39) `PUT|PATCH /api/client-need-types/{client_need_type}`

- Назначение: Операция контроллера `App\Http\Controllers\ClientNeedTypeController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientNeedTypeController@update`
- Payload:
  - `name`: `sometimes|string|max:255|unique:client_need_types`
  - `slug`: `sometimes|string|max:255|unique:client_need_types`
  - `sort_order`: `sometimes|integer|min:0`
  - `is_active`: `sometimes|boolean`
- Response:
  - `200`: `$clientNeedType`

## 40) `DELETE /api/client-need-types/{client_need_type}`

- Назначение: Операция контроллера `App\Http\Controllers\ClientNeedTypeController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientNeedTypeController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['message' => 'Удалено']`

## 41) `GET /api/client-needs/{client_need}`

- Назначение: Операция контроллера `App\Http\Controllers\ClientNeedController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientNeedController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$clientNeed->load($this->relations(`

## 42) `PUT|PATCH /api/client-needs/{client_need}`

- Назначение: Операция контроллера `App\Http\Controllers\ClientNeedController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientNeedController@update`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 43) `DELETE /api/client-needs/{client_need}`

- Назначение: Операция контроллера `App\Http\Controllers\ClientNeedController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientNeedController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['message' => 'Client need deleted']`

## 44) `GET /api/client-sources`

- Назначение: Операция контроллера `App\Http\Controllers\ClientSourceController@index`
- Auth: no
- Controller: `App\Http\Controllers\ClientSourceController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `ClientSource::query(`

## 45) `GET /api/client-types`

- Назначение: Операция контроллера `App\Http\Controllers\ClientTypeController@index`
- Auth: no
- Controller: `App\Http\Controllers\ClientTypeController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `ClientType::query(`

## 46) `POST /api/client-types`

- Назначение: Операция контроллера `App\Http\Controllers\ClientTypeController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientTypeController@store`
- Payload:
  - `name`: `required|string|max:255|unique:client_types`
  - `slug`: `required|string|max:255|unique:client_types`
  - `is_business`: `sometimes|boolean`
  - `sort_order`: `sometimes|integer|min:0`
  - `is_active`: `sometimes|boolean`
- Response:
  - `201`: `$type, 201`

## 47) `GET /api/client-types/{client_type}`

- Назначение: Операция контроллера `App\Http\Controllers\ClientTypeController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientTypeController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$clientType`

## 48) `PUT|PATCH /api/client-types/{client_type}`

- Назначение: Операция контроллера `App\Http\Controllers\ClientTypeController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientTypeController@update`
- Payload:
  - `name`: `sometimes|string|max:255|unique:client_types`
  - `slug`: `sometimes|string|max:255|unique:client_types`
  - `is_business`: `sometimes|boolean`
  - `sort_order`: `sometimes|integer|min:0`
  - `is_active`: `sometimes|boolean`
- Response:
  - `200`: `$clientType`

## 49) `DELETE /api/client-types/{client_type}`

- Назначение: Операция контроллера `App\Http\Controllers\ClientTypeController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientTypeController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['message' => 'Удалено']`

## 50) `GET /api/clients`

- Назначение: Операция контроллера `App\Http\Controllers\ClientController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 51) `POST /api/clients`

- Назначение: Операция контроллера `App\Http\Controllers\ClientController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientController@store`
- Payload:
  - `full_name`: `required|string|max:255`
  - `phone`: `nullable|string|max:50`
  - `email`: `nullable|email|max:255`
  - `note`: `nullable|string`
  - `branch_id`: `nullable|integer|exists:branches`
  - `branch_group_id`: `nullable|integer|exists:branch_groups`
  - `responsible_agent_id`: `nullable|integer|exists:users`
  - `client_type_id`: `nullable|integer|exists:client_types`
  - `source_id`: `[`
  - `source_comment`: `nullable|string`
  - `contact_kind`: `['nullable`
  - `status`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 52) `POST /api/clients/attach-existing`

- Назначение: Операция контроллера `App\Http\Controllers\ClientController@attachExisting`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientController@attachExisting`
- Payload:
  - `client_id`: `required|integer|exists:clients`
  - `context_type`: `['nullable`
  - `context_id`: `nullable|integer`
  - `property_relation`: `['nullable`
- Response:
  - `200`: `$result`

## 53) `POST /api/clients/duplicate-check`

- Назначение: Операция контроллера `App\Http\Controllers\ClientController@duplicateCheck`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientController@duplicateCheck`
- Payload:
  - `phone`: `nullable|string|max:50`
  - `email`: `nullable|email|max:255`
  - `branch_id`: `nullable|integer|exists:branches`
  - `exclude_client_id`: `nullable|integer|exists:clients`
  - `context_type`: `['nullable`
  - `context_id`: `nullable|integer`
  - `property_relation`: `['nullable`
- Response:
  - `200`: `$this->summarizeDuplicates($authUser, $data, $validated['exclude_client_id'] ?? null, $context`

## 54) `GET /api/clients/settings`

- Назначение: Операция контроллера `App\Http\Controllers\ClientController@settings`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientController@settings`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$this->clientAccess->settings(`

## 55) `PUT /api/clients/settings`

- Назначение: Операция контроллера `App\Http\Controllers\ClientController@updateSettings`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientController@updateSettings`
- Payload:
  - `agent_visibility_mode`: `['sometimes`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 56) `PATCH /api/clients/settings`

- Назначение: Операция контроллера `App\Http\Controllers\ClientController@updateSettings`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientController@updateSettings`
- Payload:
  - `agent_visibility_mode`: `['sometimes`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 57) `GET /api/clients/{client}`

- Назначение: Операция контроллера `App\Http\Controllers\ClientController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 58) `PUT|PATCH /api/clients/{client}`

- Назначение: Операция контроллера `App\Http\Controllers\ClientController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientController@update`
- Payload:
  - `full_name`: `sometimes|string|max:255`
  - `phone`: `sometimes|nullable|string|max:50`
  - `email`: `sometimes|nullable|email|max:255`
  - `note`: `nullable|string`
  - `branch_id`: `sometimes|nullable|integer|exists:branches`
  - `branch_group_id`: `sometimes|nullable|integer|exists:branch_groups`
  - `responsible_agent_id`: `sometimes|nullable|integer|exists:users`
  - `client_type_id`: `sometimes|nullable|integer|exists:client_types`
  - `source_id`: `[`
  - `source_comment`: `sometimes|nullable|string`
  - `contact_kind`: `['sometimes`
  - `status`: `['sometimes`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 59) `DELETE /api/clients/{client}`

- Назначение: Операция контроллера `App\Http\Controllers\ClientController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['message' => 'Client deleted']`

## 60) `GET /api/clients/{client}/activities`

- Назначение: Операция контроллера `App\Http\Controllers\ClientController@activities`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientController@activities`
- Payload:
  - `type`: `nullable|string|max:50`
  - `date_from`: `nullable|date`
  - `date_to`: `nullable|date`
  - `per_page`: `nullable|integer|min:1|max:100`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 61) `GET /api/clients/{client}/collaborators`

- Назначение: Операция контроллера `App\Http\Controllers\ClientController@collaborators`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientController@collaborators`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$this->loadCollaboratorPayload($client`

## 62) `POST /api/clients/{client}/collaborators`

- Назначение: Операция контроллера `App\Http\Controllers\ClientController@storeCollaborator`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientController@storeCollaborator`
- Payload:
  - `user_id`: `required|integer|exists:users`
  - `role`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 63) `DELETE /api/clients/{client}/collaborators/{user}`

- Назначение: Операция контроллера `App\Http\Controllers\ClientController@destroyCollaborator`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientController@destroyCollaborator`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 64) `GET /api/clients/{client}/matching-properties`

- Назначение: Операция контроллера `App\Http\Controllers\ClientController@matchingProperties`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientController@matchingProperties`
- Payload:
  - `limit`: `nullable|integer|min:1|max:20`
- Response:
  - `200`: `[ 'client' => [ 'id' => $client->id, 'full_name' => $client->full_name, 'phone' => $client->phone, 'contact_kind' => $client->contact_kind, ], 'needs' => $this->matcher->forClient($client, (int`

## 65) `GET /api/clients/{client}/needs`

- Назначение: Операция контроллера `App\Http\Controllers\ClientNeedController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientNeedController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$client->needs(`

## 66) `POST /api/clients/{client}/needs`

- Назначение: Операция контроллера `App\Http\Controllers\ClientNeedController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ClientNeedController@store`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$need->load($this->relations(`

## 67) `GET /api/construction-stages`

- Назначение: Операция контроллера `App\Http\Controllers\ConstructionStageController@index`
- Auth: no
- Controller: `App\Http\Controllers\ConstructionStageController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 68) `POST /api/construction-stages`

- Назначение: Операция контроллера `App\Http\Controllers\ConstructionStageController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ConstructionStageController@store`
- Payload:
  - `name`: `['required`
  - `slug`: `['nullable`
  - `sort_order`: `['nullable`
  - `is_active`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 69) `GET /api/construction-stages/{construction_stage}`

- Назначение: Операция контроллера `App\Http\Controllers\ConstructionStageController@show`
- Auth: no
- Controller: `App\Http\Controllers\ConstructionStageController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `Laravel resource/model response`

## 70) `PUT|PATCH /api/construction-stages/{construction_stage}`

- Назначение: Операция контроллера `App\Http\Controllers\ConstructionStageController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ConstructionStageController@update`
- Payload:
  - `name`: `['sometimes`
  - `slug`: `['sometimes`
  - `sort_order`: `['sometimes`
  - `is_active`: `['sometimes`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 71) `DELETE /api/construction-stages/{construction_stage}`

- Назначение: Операция контроллера `App\Http\Controllers\ConstructionStageController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ConstructionStageController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 72) `GET /api/contract-types`

- Назначение: Операция контроллера `App\Http\Controllers\ContractTypeController@index`
- Auth: no
- Controller: `App\Http\Controllers\ContractTypeController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `ContractType::all(`

## 73) `POST /api/contract-types`

- Назначение: Операция контроллера `App\Http\Controllers\ContractTypeController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ContractTypeController@store`
- Payload:
  - `slug`: `required|string|unique:contract_types`
  - `name`: `required|string`
- Response:
  - `201`: `$contractType, 201`

## 74) `GET /api/contract-types/{contract_type}`

- Назначение: Операция контроллера `App\Http\Controllers\ContractTypeController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ContractTypeController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$contractType`

## 75) `PUT|PATCH /api/contract-types/{contract_type}`

- Назначение: Операция контроллера `App\Http\Controllers\ContractTypeController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ContractTypeController@update`
- Payload:
  - `slug`: `sometimes|string|unique:contract_types`
  - `name`: `sometimes|string`
- Response:
  - `200`: `$contractType`

## 76) `DELETE /api/contract-types/{contract_type}`

- Назначение: Операция контроллера `App\Http\Controllers\ContractTypeController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ContractTypeController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['message' => 'Удалено успешно']`

## 77) `GET /api/conversations`

- Назначение: Операция контроллера `App\Http\Controllers\ConversationController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ConversationController@index`
- Payload:
  - `type`: `['nullable`
  - `per_page`: `nullable|integer|min:1|max:100`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 78) `POST /api/conversations`

- Назначение: Операция контроллера `App\Http\Controllers\ConversationController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ConversationController@store`
- Payload:
  - `name`: `required|string|max:255`
  - `participant_ids`: `required|array|min:1`
  - `participant_ids.*`: `integer|distinct|exists:users`
  - `meta`: `nullable|array`
- Response:
  - `200`: `$this->serializeConversation($conversation`

## 79) `POST /api/conversations/direct`

- Назначение: Операция контроллера `App\Http\Controllers\ConversationController@storeDirect`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ConversationController@storeDirect`
- Payload:
  - `target_user_id`: `required|integer|exists:users`
- Response:
  - `200`: `$this->serializeConversation($conversation`

## 80) `GET /api/conversations/{conversation}`

- Назначение: Операция контроллера `App\Http\Controllers\ConversationController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ConversationController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$this->serializeConversation($conversation`

## 81) `GET /api/conversations/{conversation}/messages`

- Назначение: Операция контроллера `App\Http\Controllers\ConversationMessageController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ConversationMessageController@index`
- Payload:
  - `per_page`: `nullable|integer|min:1|max:100`
- Response:
  - `200`: `$messages`

## 82) `POST /api/conversations/{conversation}/messages`

- Назначение: Операция контроллера `App\Http\Controllers\ConversationMessageController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ConversationMessageController@store`
- Payload:
  - `body`: `required|string|max:10000`
- Response:
  - `200`: `$this->serializeMessage($message`

## 83) `GET /api/conversations/{conversation}/participants`

- Назначение: Операция контроллера `App\Http\Controllers\ConversationParticipantController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ConversationParticipantController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$conversation->participants->map(fn ($participant`

## 84) `POST /api/conversations/{conversation}/participants`

- Назначение: Операция контроллера `App\Http\Controllers\ConversationParticipantController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ConversationParticipantController@store`
- Payload:
  - `user_id`: `required|integer|exists:users`
  - `role`: `['nullable`
- Response:
  - `200`: `[ 'user_id' => $participant->user_id, 'role' => $participant->role, 'joined_at' => $participant->joined_at?->toIso8601String(`

## 85) `DELETE /api/conversations/{conversation}/participants/{user}`

- Назначение: Операция контроллера `App\Http\Controllers\ConversationParticipantController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ConversationParticipantController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['message' => 'Participant removed.']`

## 86) `GET /api/crm/deals/{deal}/activities`

- Назначение: Операция контроллера `App\Http\Controllers\CrmActivityController@dealIndex`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\CrmActivityController@dealIndex`
- Payload:
  - `type`: `nullable|string|max:50`
  - `date_from`: `nullable|date`
  - `date_to`: `nullable|date`
  - `per_page`: `nullable|integer|min:1|max:100`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 87) `POST /api/crm/deals/{deal}/activities`

- Назначение: Операция контроллера `App\Http\Controllers\CrmActivityController@dealStore`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\CrmActivityController@dealStore`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 88) `GET /api/crm/leads/{lead}/activities`

- Назначение: Операция контроллера `App\Http\Controllers\CrmActivityController@leadIndex`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\CrmActivityController@leadIndex`
- Payload:
  - `type`: `nullable|string|max:50`
  - `date_from`: `nullable|date`
  - `date_to`: `nullable|date`
  - `per_page`: `nullable|integer|min:1|max:100`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 89) `POST /api/crm/leads/{lead}/activities`

- Назначение: Операция контроллера `App\Http\Controllers\CrmActivityController@leadStore`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\CrmActivityController@leadStore`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 90) `GET /api/crm/reports/performance`

- Назначение: Операция контроллера `App\Http\Controllers\CrmReportController@performance`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\CrmReportController@performance`
- Payload:
  - `role_type`: `['required`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 91) `GET /api/crm/task-types`

- Назначение: Операция контроллера `App\Http\Controllers\CrmTaskTypeController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\CrmTaskTypeController@index`
- Payload:
  - `group`: `nullable|string|max:64`
  - `is_kpi`: `nullable|boolean`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 92) `GET /api/crm/tasks`

- Назначение: Операция контроллера `App\Http\Controllers\CrmTaskController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\CrmTaskController@index`
- Payload:
  - `assignee_id`: `nullable|integer|exists:users`
  - `task_type_code`: `nullable|string|max:64`
  - `status`: `nullable|string|max:32`
  - `date_from`: `nullable|date`
  - `date_to`: `nullable|date`
  - `per_page`: `nullable|integer|min:1|max:100`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 93) `POST /api/crm/tasks`

- Назначение: Операция контроллера `App\Http\Controllers\CrmTaskController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\CrmTaskController@store`
- Payload:
  - `task_type_id`: `required|integer|exists:crm_task_types`
  - `assignee_id`: `required|integer|exists:users`
  - `title`: `required|string|max:255`
  - `description`: `nullable|string`
  - `status`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 94) `GET /api/crm/tasks/kpi-daily-summary`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@crmTaskDailySummary`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@crmTaskDailySummary`
- Payload:
  - `date`: `required|date`
  - `assignee_id`: `nullable|integer|exists:users`
  - `branch_id`: `nullable|integer|exists:branches`
  - `branch_group_id`: `nullable|integer|exists:branch_groups`
- Response:
  - `200`: `['data' => $this->service->taskDailySummary($this->authUser(`

## 95) `GET /api/crm/tasks/kpi-weekly-summary`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@crmTaskWeeklySummary`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@crmTaskWeeklySummary`
- Payload:
  - `year`: `required|integer|min:2000|max:2100`
  - `week`: `required|integer|min:1|max:53`
  - `assignee_id`: `nullable|integer|exists:users`
  - `branch_id`: `nullable|integer|exists:branches`
  - `branch_group_id`: `nullable|integer|exists:branch_groups`
- Response:
  - `200`: `['data' => $this->service->taskWeeklySummary($this->authUser(`

## 96) `PUT /api/crm/tasks/{crmTask}`

- Назначение: Операция контроллера `App\Http\Controllers\CrmTaskController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\CrmTaskController@update`
- Payload:
  - `title`: `nullable|string|max:255`
  - `description`: `nullable|string`
  - `status`: `['nullable`
- Response:
  - `200`: `$crmTask->fresh(['type', 'assignee.role']`

## 97) `PATCH /api/crm/tasks/{crmTask}`

- Назначение: Операция контроллера `App\Http\Controllers\CrmTaskController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\CrmTaskController@update`
- Payload:
  - `title`: `nullable|string|max:255`
  - `description`: `nullable|string`
  - `status`: `['nullable`
- Response:
  - `200`: `$crmTask->fresh(['type', 'assignee.role']`

## 98) `GET /api/daily-reports`

- Назначение: Операция контроллера `App\Http\Controllers\DailyReportController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DailyReportController@index`
- Payload:
  - `report_date`: `nullable|date_format:Y-m-d`
  - `from`: `nullable|date_format:Y-m-d`
  - `to`: `nullable|date_format:Y-m-d|after_or_equal:from`
  - `date_from`: `nullable|date_format:Y-m-d`
  - `date_to`: `nullable|date_format:Y-m-d|after_or_equal:date_from`
  - `role`: `nullable|string|exists:roles`
  - `user_id`: `nullable|integer|exists:users`
  - `branch_id`: `nullable|integer|exists:branches`
  - `branch_group_id`: `nullable|integer|exists:branch_groups`
  - `page`: `nullable|integer|min:1`
  - `per_page`: `nullable|integer|min:1|max:100`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 99) `POST /api/daily-reports`

- Назначение: Операция контроллера `App\Http\Controllers\DailyReportController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DailyReportController@store`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 100) `GET /api/daily-reports/my`

- Назначение: Операция контроллера `App\Http\Controllers\DailyReportController@my`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DailyReportController@my`
- Payload:
  - `date_from`: `nullable|date`
  - `date_to`: `nullable|date`
  - `page`: `nullable|integer|min:1`
  - `per_page`: `nullable|integer|min:1|max:100`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 101) `GET /api/daily-reports/my/{date}`

- Назначение: Операция контроллера `App\Http\Controllers\DailyReportController@showMine`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DailyReportController@showMine`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `[ 'report_date' => $date, 'auto' => $this->dailyReports->autoMetrics($user, $date`

## 102) `GET /api/daily-reports/status`

- Назначение: Операция контроллера `App\Http\Controllers\DailyReportController@status`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DailyReportController@status`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$payload`

## 103) `PUT /api/daily-reports/{dailyReport}`

- Назначение: Операция контроллера `App\Http\Controllers\DailyReportController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DailyReportController@update`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 104) `PATCH /api/daily-reports/{dailyReport}`

- Назначение: Операция контроллера `App\Http\Controllers\DailyReportController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DailyReportController@update`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 105) `GET /api/deal-pipelines`

- Назначение: Операция контроллера `App\Http\Controllers\DealPipelineController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealPipelineController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$this->pipelineAccess->visibleQuery($authUser`

## 106) `POST /api/deal-pipelines`

- Назначение: Операция контроллера `App\Http\Controllers\DealPipelineController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealPipelineController@store`
- Payload:
  - `name`: `required|string|max:255`
  - `slug`: `nullable|string|max:255|unique:crm_deal_pipelines`
  - `code`: `nullable|string|max:255`
  - `type`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 107) `GET /api/deal-pipelines/{dealPipeline}/board`

- Назначение: Операция контроллера `App\Http\Controllers\DealPipelineController@board`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealPipelineController@board`
- Payload:
  - `search`: `nullable|string`
  - `responsible_agent_id`: `nullable|integer|exists:users`
  - `client_id`: `nullable|integer|exists:clients`
  - `lead_id`: `nullable|integer|exists:leads`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 108) `POST /api/deal-pipelines/{dealPipeline}/stages`

- Назначение: Операция контроллера `App\Http\Controllers\DealStageController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealStageController@store`
- Payload:
  - `name`: `required|string|max:255`
  - `slug`: `['nullable`
  - `color`: `nullable|string|max:24`
  - `sort_order`: `sometimes|integer|min:0`
  - `is_default`: `sometimes|boolean`
  - `is_closed`: `sometimes|boolean`
  - `is_lost`: `sometimes|boolean`
  - `is_active`: `sometimes|boolean`
  - `meta`: `nullable|array`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 109) `PATCH /api/deal-pipelines/{dealPipeline}/stages/reorder`

- Назначение: Операция контроллера `App\Http\Controllers\DealStageController@reorder`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealStageController@reorder`
- Payload:
  - `stage_ids`: `required|array|min:1`
  - `stage_ids.*`: `integer|exists:crm_deal_stages`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 110) `GET /api/deal-pipelines/{deal_pipeline}`

- Назначение: Операция контроллера `App\Http\Controllers\DealPipelineController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealPipelineController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$dealPipeline->load($this->relations(`

## 111) `PUT|PATCH /api/deal-pipelines/{deal_pipeline}`

- Назначение: Операция контроллера `App\Http\Controllers\DealPipelineController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealPipelineController@update`
- Payload:
  - `name`: `sometimes|string|max:255`
  - `slug`: `['sometimes`
  - `code`: `sometimes|string|max:255`
  - `type`: `['sometimes`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 112) `DELETE /api/deal-pipelines/{deal_pipeline}`

- Назначение: Операция контроллера `App\Http\Controllers\DealPipelineController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealPipelineController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `409`: `[ 'message' => 'Нельзя удалить воронку: в ней есть сделки.', ], 409`

## 113) `GET /api/deal-stages/{deal_stage}`

- Назначение: Операция контроллера `App\Http\Controllers\DealStageController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealStageController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$dealStage->load('pipeline'`

## 114) `PUT|PATCH /api/deal-stages/{deal_stage}`

- Назначение: Операция контроллера `App\Http\Controllers\DealStageController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealStageController@update`
- Payload:
  - `name`: `sometimes|string|max:255`
  - `slug`: `['sometimes`
  - `color`: `sometimes|nullable|string|max:24`
  - `sort_order`: `sometimes|integer|min:0`
  - `is_default`: `sometimes|boolean`
  - `is_closed`: `sometimes|boolean`
  - `is_lost`: `sometimes|boolean`
  - `is_active`: `sometimes|boolean`
  - `meta`: `sometimes|nullable|array`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 115) `DELETE /api/deal-stages/{deal_stage}`

- Назначение: Операция контроллера `App\Http\Controllers\DealStageController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealStageController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `409`: `[ 'message' => 'Нельзя удалить стадию: в ней есть сделки.', ], 409`

## 116) `GET /api/deals`

- Назначение: Операция контроллера `App\Http\Controllers\DealController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealController@index`
- Payload:
  - `search`: `nullable|string`
  - `pipeline_id`: `nullable|integer|exists:crm_deal_pipelines`
  - `pipeline_code`: `nullable|string|max:255`
  - `pipeline_type`: `nullable|string|max:255`
  - `stage_id`: `nullable|integer|exists:crm_deal_stages`
  - `client_id`: `nullable|integer|exists:clients`
  - `lead_id`: `nullable|integer|exists:leads`
  - `client_need_id`: `nullable|integer|exists:client_needs`
  - `primary_property_id`: `nullable|integer|exists:properties`
  - `source_property_status`: `nullable|string|max:40`
  - `source`: `nullable|string|max:100`
  - `responsible_agent_id`: `nullable|integer|exists:users`
  - `branch_id`: `nullable|integer|exists:branches`
  - `overdue_activity`: `nullable|boolean`
  - `per_page`: `nullable|integer|min:1|max:100`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 117) `POST /api/deals`

- Назначение: Операция контроллера `App\Http\Controllers\DealController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealController@store`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 118) `GET /api/deals/{deal}`

- Назначение: Операция контроллера `App\Http\Controllers\DealController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$deal`

## 119) `PUT|PATCH /api/deals/{deal}`

- Назначение: Операция контроллера `App\Http\Controllers\DealController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealController@update`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 120) `DELETE /api/deals/{deal}`

- Назначение: Операция контроллера `App\Http\Controllers\DealController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['message' => 'Deal deleted']`

## 121) `PATCH /api/deals/{deal}/move`

- Назначение: Операция контроллера `App\Http\Controllers\DealController@move`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DealController@move`
- Payload:
  - `stage_id`: `required|integer|exists:crm_deal_stages`
  - `position`: `nullable|integer|min:0`
  - `lost_reason`: `nullable|string`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 122) `GET /api/developers`

- Назначение: GET /developers
- Auth: no
- Controller: `App\Http\Controllers\DeveloperController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 123) `POST /api/developers`

- Назначение: GET /developers
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DeveloperController@store`
- Payload:
  - `name`: `['required`
  - `phone`: `['nullable`
  - `under_construction_count`: `['nullable`
  - `built_count`: `['nullable`
  - `founded_year`: `['nullable`
  - `total_projects`: `['nullable`
  - `moderation_status`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 124) `GET /api/developers/{developer}`

- Назначение: GET /developers
- Auth: no
- Controller: `App\Http\Controllers\DeveloperController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$developer`

## 125) `PUT|PATCH /api/developers/{developer}`

- Назначение: GET /developers
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DeveloperController@update`
- Payload:
  - `name`: `['sometimes`
  - `phone`: `['nullable`
  - `under_construction_count`: `['nullable`
  - `built_count`: `['nullable`
  - `founded_year`: `['nullable`
  - `total_projects`: `['nullable`
  - `moderation_status`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 126) `DELETE /api/developers/{developer}`

- Назначение: GET /developers
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DeveloperController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 127) `GET /api/favorites`

- Назначение: Операция контроллера `App\Http\Controllers\FavoriteController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\FavoriteController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$favorites`

## 128) `POST /api/favorites`

- Назначение: Операция контроллера `App\Http\Controllers\FavoriteController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\FavoriteController@store`
- Payload:
  - `property_id`: `required|exists:properties`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 129) `DELETE /api/favorites/{property_id}`

- Назначение: Операция контроллера `App\Http\Controllers\FavoriteController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\FavoriteController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `404`: `['message' => 'Не найдено'], 404`

## 130) `GET /api/features`

- Назначение: Операция контроллера `App\Http\Controllers\FeatureController@index`
- Auth: no
- Controller: `App\Http\Controllers\FeatureController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 131) `POST /api/features`

- Назначение: Операция контроллера `App\Http\Controllers\FeatureController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\FeatureController@store`
- Payload:
  - `name`: `['required`
  - `slug`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 132) `GET /api/features/{feature}`

- Назначение: Операция контроллера `App\Http\Controllers\FeatureController@show`
- Auth: no
- Controller: `App\Http\Controllers\FeatureController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `Laravel resource/model response`

## 133) `PUT|PATCH /api/features/{feature}`

- Назначение: Операция контроллера `App\Http\Controllers\FeatureController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\FeatureController@update`
- Payload:
  - `name`: `['sometimes`
  - `slug`: `['sometimes`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 134) `DELETE /api/features/{feature}`

- Назначение: Операция контроллера `App\Http\Controllers\FeatureController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\FeatureController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 135) `GET /api/heating-types`

- Назначение: Операция контроллера `App\Http\Controllers\HeatingTypeController@index`
- Auth: no
- Controller: `App\Http\Controllers\HeatingTypeController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 136) `POST /api/heating-types`

- Назначение: Операция контроллера `App\Http\Controllers\HeatingTypeController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\HeatingTypeController@store`
- Payload:
  - `name`: `required|string`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 137) `GET /api/heating-types/{heating_type}`

- Назначение: Операция контроллера `App\Http\Controllers\HeatingTypeController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\HeatingTypeController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `Laravel resource/model response`

## 138) `PUT|PATCH /api/heating-types/{heating_type}`

- Назначение: Операция контроллера `App\Http\Controllers\HeatingTypeController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\HeatingTypeController@update`
- Payload:
  - `name`: `required|string`
- Response:
  - `200`: `Laravel resource/model response`

## 139) `DELETE /api/heating-types/{heating_type}`

- Назначение: Операция контроллера `App\Http\Controllers\HeatingTypeController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\HeatingTypeController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 140) `POST /api/kpi-adjustments`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@createAdjustment`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@createAdjustment`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 141) `GET /api/kpi-adjustments`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@adjustments`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@adjustments`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 142) `POST /api/kpi-period-locks`

- Назначение: Операция контроллера `App\Http\Controllers\KpiPeriodLockController@lock`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiPeriodLockController@lock`
- Payload:
  - `period_type`: `['required`
- Response:
  - `200`: `$lock->fresh(`

## 143) `GET /api/kpi-plans`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@plans`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@plans`
- Payload:
  - `role`: `nullable|string|max:64`
  - `user_id`: `nullable|integer|exists:users`
  - `date`: `nullable|date_format:Y-m-d`
- Response:
  - `200`: `[ 'data' => $effective['items'], 'plans' => $effective['items'], 'source' => $effective['source'], 'meta' => [ 'exists' => count($effective['items']`

## 144) `PATCH /api/kpi-plans`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@updatePlans`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@updatePlans`
- Payload:
  - `role`: `nullable|string|max:64`
  - `items`: `required|array|min:1`
  - `items.*.metric_key`: `['required`
- Response:
  - `200`: `['data' => $this->service->upsertPlans($role, $validated['items']`

## 145) `GET /api/kpi-reports`

- Назначение: Операция контроллера `App\Http\Controllers\KpiReportController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiReportController@index`
- Payload:
  - `period_type`: `['nullable`
- Response:
  - `200`: `$payload`

## 146) `GET /api/kpi/acceptance-runs`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@acceptanceRuns`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@acceptanceRuns`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 147) `GET /api/kpi/adjustments`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@adjustments`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@adjustments`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 148) `POST /api/kpi/adjustments`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@createAdjustment`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@createAdjustment`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 149) `GET /api/kpi/adjustments/entities`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@adjustmentEntities`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@adjustmentEntities`
- Payload:
  - `role`: `nullable|string|max:128`
  - `active`: `nullable|boolean`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 150) `GET /api/kpi/adjustments/meta`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@adjustmentMeta`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@adjustmentMeta`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `[ 'fields' => [ ['value' => 'objects', 'label' => 'Объекты', 'hint' => 'Количество добавленных объектов за период.'], ['value' => 'shows', 'label' => 'Показы', 'hint' => 'Количество проведённых показов за период.'], ['va`

## 151) `GET /api/kpi/daily`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@daily`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@daily`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$this->service->dailyRowsV2($this->authUser(`

## 152) `POST /api/kpi/daily`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@upsertDaily`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@upsertDaily`
- Payload:
  - `rows`: `required|array|min:1`
  - `rows.*.date`: `required|date_format:Y-m-d`
  - `rows.*.role`: `nullable|string|max:64`
  - `rows.*.employee_id`: `required|integer|exists:users`
  - `rows.*.employee_name`: `nullable|string|max:255`
  - `rows.*.group_name`: `nullable|string|max:255`
  - `rows.*.advertisement`: `nullable|integer|min:0`
  - `rows.*.call`: `nullable|integer|min:0`
  - `rows.*.kabul`: `nullable|integer|min:0`
  - `rows.*.show`: `nullable|integer|min:0`
  - `rows.*.lead`: `nullable|integer|min:0`
  - `rows.*.deposit`: `nullable|integer|min:0`
  - `rows.*.deal`: `nullable|integer|min:0`
  - `rows.*.objects`: `nullable|numeric|min:0`
  - `rows.*.shows`: `nullable|numeric|min:0`
  - `rows.*.ads`: `nullable|numeric|min:0`
  - `rows.*.calls`: `nullable|numeric|min:0`
  - `rows.*.sales`: `nullable|numeric|min:0`
  - `rows.*.comment`: `nullable|string`
- Response:
  - `200`: `[ 'data' => $this->service->upsertDailyRowsV2($this->authUser(`

## 153) `GET /api/kpi/daily/my-progress`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@myDailyProgress`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@myDailyProgress`
- Payload:
  - `date`: `nullable|date_format:Y-m-d`
- Response:
  - `200`: `$this->service->myDailyProgress($this->authUser(`

## 154) `GET /api/kpi/daily/my-report`

- Назначение: Операция контроллера `App\Http\Controllers\DailyReportController@myReport`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DailyReportController@myReport`
- Payload:
  - `date`: `required|date_format:Y-m-d`
- Response:
  - `200`: `[ 'report_date' => $date, 'metrics' => $metricsBundle['metrics'], 'manual' => [ 'ads' => (int`

## 155) `POST /api/kpi/daily/my-report`

- Назначение: Операция контроллера `App\Http\Controllers\DailyReportController@submitMyReport`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DailyReportController@submitMyReport`
- Payload:
  - `report_date`: `required|date_format:Y-m-d`
  - `ads`: `required|integer|min:0`
  - `calls`: `required|integer|min:0`
  - `comment`: `nullable|string|max:2000`
  - `plans_for_tomorrow`: `nullable|string|max:2000`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 156) `GET /api/kpi/daily/report`

- Назначение: Операция контроллера `App\Http\Controllers\DailyReportController@scopeReport`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DailyReportController@scopeReport`
- Payload:
  - `date`: `required|date_format:Y-m-d`
  - `employee_id`: `required|integer|exists:users`
- Response:
  - `200`: `[ 'report_date' => $date, 'employee_id' => $targetUser->id, 'employee_name' => $targetUser->name, 'employee_role' => (string`

## 157) `PATCH /api/kpi/daily/report`

- Назначение: Операция контроллера `App\Http\Controllers\DailyReportController@updateScopeReport`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DailyReportController@updateScopeReport`
- Payload:
  - `report_date`: `required|date_format:Y-m-d`
  - `employee_id`: `required|integer|exists:users`
  - `ads`: `required|integer|min:0`
  - `calls`: `required|integer|min:0`
  - `comment`: `nullable|string|max:2000`
  - `plans_for_tomorrow`: `nullable|string|max:2000`
  - `updated_reason`: `nullable|string|max:500`
  - `edit_source`: `nullable|string|max:64`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 158) `GET /api/kpi/dashboard`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@dashboard`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@dashboard`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['data' => $this->service->dashboard($this->authUser(`

## 159) `GET /api/kpi/dashboard/debug`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@dashboardDebug`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@dashboardDebug`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['data' => $this->service->dashboardDebug($this->authUser(`

## 160) `GET /api/kpi/early-risk-alerts`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@earlyRiskAlerts`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@earlyRiskAlerts`
- Payload:
  - `date`: `nullable|date`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 161) `PATCH /api/kpi/early-risk-alerts/status`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@updateEarlyRiskStatus`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@updateEarlyRiskStatus`
- Payload:
  - `alert_id`: `required|integer|exists:kpi_early_risk_alerts`
  - `status`: `['required`
- Response:
  - `200`: `['success' => true]`

## 162) `GET /api/kpi/integrations/status`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@integrationsStatus`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@integrationsStatus`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['data' => KpiIntegrationStatus::query(`

## 163) `GET /api/kpi/metric-mapping`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@metricMapping`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@metricMapping`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['data' => $this->service->metricMapping(`

## 164) `GET /api/kpi/monthly`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@monthly`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@monthly`
- Payload:
  - `year`: `nullable|integer|min:2000|max:2100`
  - `month`: `nullable|integer|min:1|max:12`
  - `date_from`: `nullable|date`
  - `date_to`: `nullable|date|after_or_equal:date_from`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 165) `GET /api/kpi/ops/acceptance-runs`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@acceptanceRuns`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@acceptanceRuns`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 166) `GET /api/kpi/ops/early-risk-alerts`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@earlyRiskAlerts`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@earlyRiskAlerts`
- Payload:
  - `date`: `nullable|date`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 167) `PATCH /api/kpi/ops/early-risk-alerts/status`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@updateEarlyRiskStatus`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@updateEarlyRiskStatus`
- Payload:
  - `alert_id`: `required|integer|exists:kpi_early_risk_alerts`
  - `status`: `['required`
- Response:
  - `200`: `['success' => true]`

## 168) `GET /api/kpi/ops/integrations/status`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@integrationsStatus`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@integrationsStatus`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['data' => KpiIntegrationStatus::query(`

## 169) `GET /api/kpi/ops/period-contract`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@periodContract`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@periodContract`
- Payload:
  - `period_type`: `['required`
- Response:
  - `200`: `['data' => $this->service->periodContract($this->authUser(`

## 170) `GET /api/kpi/ops/quality/issues`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@qualityIssues`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@qualityIssues`
- Payload:
  - `date_from`: `nullable|date`
  - `date_to`: `nullable|date`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 171) `GET /api/kpi/ops/telegram/config`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@telegramConfig`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@telegramConfig`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['data' => $this->service->telegramConfig(`

## 172) `PATCH /api/kpi/ops/telegram/config`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@updateTelegramConfig`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@updateTelegramConfig`
- Payload:
  - `daily_enabled`: `sometimes|boolean`
  - `daily_time`: `sometimes|date_format:H:i`
  - `weekly_enabled`: `sometimes|boolean`
  - `weekly_day`: `sometimes|integer|min:1|max:7`
  - `weekly_time`: `sometimes|date_format:H:i`
  - `timezone`: `sometimes|string|max:64`
- Response:
  - `200`: `['data' => $this->service->updateTelegramConfig($validated`

## 173) `GET /api/kpi/period-contract`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@periodContract`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@periodContract`
- Payload:
  - `period_type`: `['required`
- Response:
  - `200`: `['data' => $this->service->periodContract($this->authUser(`

## 174) `GET /api/kpi/plans`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@plans`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@plans`
- Payload:
  - `role`: `nullable|string|max:64`
  - `user_id`: `nullable|integer|exists:users`
  - `date`: `nullable|date_format:Y-m-d`
- Response:
  - `200`: `[ 'data' => $effective['items'], 'plans' => $effective['items'], 'source' => $effective['source'], 'meta' => [ 'exists' => count($effective['items']`

## 175) `POST /api/kpi/plans/bulk-upsert`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@bulkUpsertUserPlans`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@bulkUpsertUserPlans`
- Payload:
  - `effective_from`: `required|date`
  - `effective_to`: `nullable|date|after_or_equal:effective_from`
  - `replace_if_conflict`: `sometimes|boolean`
  - `conflict_strategy`: `['sometimes`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 176) `GET /api/kpi/plans/common`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@commonPlans`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@commonPlans`
- Payload:
  - `role`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 177) `PUT /api/kpi/plans/common`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@upsertCommonPlans`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@upsertCommonPlans`
- Payload:
  - `role`: `['required`
- Response:
  - `200`: `Laravel resource/model response`

## 178) `PATCH /api/kpi/plans/common`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@upsertCommonPlans`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@upsertCommonPlans`
- Payload:
  - `role`: `['required`
- Response:
  - `200`: `Laravel resource/model response`

## 179) `POST /api/kpi/plans/common/apply-to-users`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@applyCommonPlansToUsers`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@applyCommonPlansToUsers`
- Payload:
  - `role`: `['required`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 180) `GET /api/kpi/plans/common/{planId}`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@showCommonPlan`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@showCommonPlan`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 181) `GET /api/kpi/plans/eligible-users`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@eligibleUsers`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@eligibleUsers`
- Payload:
  - `role`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 182) `GET /api/kpi/plans/list`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@listPlans`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@listPlans`
- Payload:
  - `type`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 183) `GET /api/kpi/plans/{planId}`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@showPlan`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@showPlan`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 184) `PUT /api/kpi/plans/{userId}`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@updateUserPlans`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@updateUserPlans`
- Payload:
  - `effective_from`: `required|date`
  - `effective_to`: `nullable|date|after_or_equal:effective_from`
  - `replace_if_conflict`: `sometimes|boolean`
  - `conflict_strategy`: `['sometimes`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 185) `PATCH /api/kpi/plans/{userId}`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@updateUserPlans`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@updateUserPlans`
- Payload:
  - `effective_from`: `required|date`
  - `effective_to`: `nullable|date|after_or_equal:effective_from`
  - `replace_if_conflict`: `sometimes|boolean`
  - `conflict_strategy`: `['sometimes`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 186) `GET /api/kpi/quality/issues`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@qualityIssues`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@qualityIssues`
- Payload:
  - `date_from`: `nullable|date`
  - `date_to`: `nullable|date`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 187) `GET /api/kpi/rop-plans`

- Назначение: Операция контроллера `App\Http\Controllers\KpiRopPlanController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiRopPlanController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 188) `POST /api/kpi/rop-plans`

- Назначение: Операция контроллера `App\Http\Controllers\KpiRopPlanController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiRopPlanController@store`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 189) `GET /api/kpi/rop-plans/{id}`

- Назначение: Операция контроллера `App\Http\Controllers\KpiRopPlanController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiRopPlanController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 190) `PATCH /api/kpi/rop-plans/{id}`

- Назначение: Операция контроллера `App\Http\Controllers\KpiRopPlanController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiRopPlanController@update`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 191) `POST /api/kpi/rop-plans/{id}/copy`

- Назначение: Операция контроллера `App\Http\Controllers\KpiRopPlanController@copy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiRopPlanController@copy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 192) `GET /api/kpi/telegram-reports/config`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@telegramConfig`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@telegramConfig`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['data' => $this->service->telegramConfig(`

## 193) `PATCH /api/kpi/telegram-reports/config`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@updateTelegramConfig`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@updateTelegramConfig`
- Payload:
  - `daily_enabled`: `sometimes|boolean`
  - `daily_time`: `sometimes|date_format:H:i`
  - `weekly_enabled`: `sometimes|boolean`
  - `weekly_day`: `sometimes|integer|min:1|max:7`
  - `weekly_time`: `sometimes|date_format:H:i`
  - `timezone`: `sometimes|string|max:64`
- Response:
  - `200`: `['data' => $this->service->updateTelegramConfig($validated`

## 194) `GET /api/kpi/weekly`

- Назначение: Операция контроллера `App\Http\Controllers\KpiModuleController@weekly`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\KpiModuleController@weekly`
- Payload:
  - `year`: `nullable|integer|min:2000|max:2100`
  - `week`: `nullable|integer|min:1|max:53`
  - `date_from`: `nullable|date`
  - `date_to`: `nullable|date|after_or_equal:date_from`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 195) `POST /api/lead-requests`

- Назначение: Операция контроллера `App\Http\Controllers\LeadRequestController@store`
- Auth: no
- Controller: `App\Http\Controllers\LeadRequestController@store`
- Payload:
  - `service_type`: `['required`
  - `name`: `['required`
  - `phone`: `['required`
  - `email`: `['nullable`
  - `comment`: `['nullable`
  - `source`: `['nullable`
  - `source_url`: `['nullable`
  - `utm`: `['nullable`
  - `context`: `['nullable`
- Response:
  - `503`: `[ 'message' => 'Bitrix24 не настроен', ], 503`

## 196) `GET /api/leads`

- Назначение: Операция контроллера `App\Http\Controllers\LeadController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\LeadController@index`
- Payload:
  - `search`: `nullable|string`
  - `status`: `['nullable`
  - `source`: `nullable|string|max:100`
  - `branch_id`: `nullable|integer|exists:branches`
  - `responsible_agent_id`: `nullable|integer|exists:users`
  - `client_id`: `nullable|integer|exists:clients`
  - `overdue_first_contact`: `nullable|boolean`
  - `overdue_follow_up`: `nullable|boolean`
  - `overdue_activity`: `nullable|boolean`
  - `per_page`: `nullable|integer|min:1|max:100`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 197) `POST /api/leads`

- Назначение: Операция контроллера `App\Http\Controllers\LeadController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\LeadController@store`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$this->attachDuplicateSummary($lead->load($this->relations(`

## 198) `GET /api/leads/{lead}`

- Назначение: Операция контроллера `App\Http\Controllers\LeadController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\LeadController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$this->attachDuplicateSummary($lead`

## 199) `PUT|PATCH /api/leads/{lead}`

- Назначение: Операция контроллера `App\Http\Controllers\LeadController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\LeadController@update`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 200) `DELETE /api/leads/{lead}`

- Назначение: Операция контроллера `App\Http\Controllers\LeadController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\LeadController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['message' => 'Lead deleted']`

## 201) `POST /api/leads/{lead}/convert`

- Назначение: Операция контроллера `App\Http\Controllers\LeadController@convert`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\LeadController@convert`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$conversion`

## 202) `GET /api/locations`

- Назначение: Операция контроллера `App\Http\Controllers\LocationController@index`
- Auth: no
- Controller: `App\Http\Controllers\LocationController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$locations->map(fn (Location $location`

## 203) `POST /api/locations`

- Назначение: Операция контроллера `App\Http\Controllers\LocationController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\LocationController@store`
- Payload:
  - `city`: `required|string`
  - `district`: `required|string`
- Response:
  - `201`: `$location, 201`

## 204) `GET /api/locations/{location}`

- Назначение: Операция контроллера `App\Http\Controllers\LocationController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\LocationController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$this->serializeLocation($location, $locations`

## 205) `PUT|PATCH /api/locations/{location}`

- Назначение: Операция контроллера `App\Http\Controllers\LocationController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\LocationController@update`
- Payload:
  - `city`: `sometimes|string`
  - `district`: `sometimes|string`
- Response:
  - `200`: `$location`

## 206) `DELETE /api/locations/{location}`

- Назначение: Операция контроллера `App\Http\Controllers\LocationController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\LocationController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['message' => 'Удалено']`

## 207) `GET /api/locations/{location}/districts`

- Назначение: Операция контроллера `App\Http\Controllers\LocationController@districts`
- Auth: no
- Controller: `App\Http\Controllers\LocationController@districts`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$this->buildDistricts($locations`

## 208) `POST /api/login`

- Назначение: Операция контроллера `App\Http\Controllers\AuthController@login`
- Auth: no
- Controller: `App\Http\Controllers\AuthController@login`
- Payload:
  - `phone`: `required|string`
  - `password`: `required|string`
  - `device_name`: `nullable|string|max:255`
  - `platform`: `nullable|string|max:50`
  - `app_version`: `nullable|string|max:50`
- Response:
  - `404`: `['message' => 'Пользователь не найден'], 404`

## 209) `POST /api/logout`

- Назначение: Операция контроллера `App\Http\Controllers\AuthController@logout`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\AuthController@logout`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `401`: `['message' => 'Unauthenticated.'], 401`

## 210) `GET /api/materials`

- Назначение: Операция контроллера `App\Http\Controllers\MaterialController@index`
- Auth: no
- Controller: `App\Http\Controllers\MaterialController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 211) `POST /api/materials`

- Назначение: Операция контроллера `App\Http\Controllers\MaterialController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\MaterialController@store`
- Payload:
  - `name`: `['required`
  - `slug`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 212) `GET /api/materials/{material}`

- Назначение: Операция контроллера `App\Http\Controllers\MaterialController@show`
- Auth: no
- Controller: `App\Http\Controllers\MaterialController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `Laravel resource/model response`

## 213) `PUT|PATCH /api/materials/{material}`

- Назначение: Операция контроллера `App\Http\Controllers\MaterialController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\MaterialController@update`
- Payload:
  - `name`: `['sometimes`
  - `slug`: `['sometimes`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 214) `DELETE /api/materials/{material}`

- Назначение: Операция контроллера `App\Http\Controllers\MaterialController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\MaterialController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 215) `GET /api/me/reminders/daily-report`

- Назначение: Операция контроллера `App\Http\Controllers\MyReminderController@showDailyReport`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\MyReminderController@showDailyReport`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 216) `PUT /api/me/reminders/daily-report`

- Назначение: Операция контроллера `App\Http\Controllers\MyReminderController@updateDailyReport`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\MyReminderController@updateDailyReport`
- Payload:
  - `enabled`: `required|boolean`
  - `remind_time`: `required|date_format:H:i`
  - `timezone`: `required|timezone`
  - `channels`: `nullable|array|min:1`
  - `allow_edit_submitted_daily_report`: `nullable|boolean`
  - `channels.*`: `['string`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 217) `GET /api/motivation/achievements`

- Назначение: Операция контроллера `App\Http\Controllers\MotivationController@achievements`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\MotivationController@achievements`
- Payload:
  - `user_id`: `nullable|integer|exists:users`
  - `status`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 218) `GET /api/motivation/my-overview`

- Назначение: Операция контроллера `App\Http\Controllers\MotivationController@myOverview`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\MotivationController@myOverview`
- Payload:
  - `period_type`: `['required`
- Response:
  - `200`: `$payload`

## 219) `POST /api/motivation/recalculate`

- Назначение: Операция контроллера `App\Http\Controllers\MotivationController@recalculate`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\MotivationController@recalculate`
- Payload:
  - `rule_id`: `nullable|integer|exists:motivation_rules`
  - `user_id`: `nullable|integer|exists:users`
  - `reason`: `nullable|string|max:500`
- Response:
  - `200`: `['data' => $result]`

## 220) `POST /api/motivation/reward-issues/{achievement}/assign`

- Назначение: Операция контроллера `App\Http\Controllers\MotivationController@assignRewardIssue`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\MotivationController@assignRewardIssue`
- Payload:
  - `assignee_id`: `nullable|integer|exists:users`
  - `comment`: `nullable|string`
- Response:
  - `200`: `['data' => $issue]`

## 221) `PATCH /api/motivation/reward-issues/{rewardIssue}`

- Назначение: Операция контроллера `App\Http\Controllers\MotivationController@updateRewardIssue`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\MotivationController@updateRewardIssue`
- Payload:
  - `assignee_id`: `nullable|integer|exists:users`
  - `status`: `['sometimes`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 222) `GET /api/motivation/rules`

- Назначение: Операция контроллера `App\Http\Controllers\MotivationController@rules`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\MotivationController@rules`
- Payload:
  - `scope`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 223) `POST /api/motivation/rules`

- Назначение: Операция контроллера `App\Http\Controllers\MotivationController@storeRule`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\MotivationController@storeRule`
- Payload:
  - `scope`: `['required`
- Response:
  - `201`: `['data' => $rule], 201`

## 224) `PATCH /api/motivation/rules/{rule}`

- Назначение: Операция контроллера `App\Http\Controllers\MotivationController@updateRule`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\MotivationController@updateRule`
- Payload:
  - `scope`: `['sometimes`
- Response:
  - `200`: `['data' => $rule->fresh(`

## 225) `GET /api/my-properties`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyController@myProperties`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyController@myProperties`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$query->latest(`

## 226) `GET /api/my/stories`

- Назначение: Операция контроллера `App\Http\Controllers\StoryController@myStories`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\StoryController@myStories`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 227) `GET /api/new-buildings`

- Назначение: Операция контроллера `App\Http\Controllers\NewBuildingController@index`
- Auth: no
- Controller: `App\Http\Controllers\NewBuildingController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 228) `POST /api/new-buildings`

- Назначение: Операция контроллера `App\Http\Controllers\NewBuildingController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\NewBuildingController@store`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 229) `GET /api/new-buildings/plans`

- Назначение: Операция контроллера `App\Http\Controllers\NewBuildingPlanController@index`
- Auth: no
- Controller: `App\Http\Controllers\NewBuildingPlanController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 230) `GET /api/new-buildings/{new_building}`

- Назначение: Операция контроллера `App\Http\Controllers\NewBuildingController@show`
- Auth: no
- Controller: `App\Http\Controllers\NewBuildingController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 231) `PUT|PATCH /api/new-buildings/{new_building}`

- Назначение: Операция контроллера `App\Http\Controllers\NewBuildingController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\NewBuildingController@update`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 232) `DELETE /api/new-buildings/{new_building}`

- Назначение: Операция контроллера `App\Http\Controllers\NewBuildingController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\NewBuildingController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 233) `GET /api/new-buildings/{new_building}/blocks`

- Назначение: Операция контроллера `App\Http\Controllers\NewBuildingBlockController@index`
- Auth: no
- Controller: `App\Http\Controllers\NewBuildingBlockController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 234) `POST /api/new-buildings/{new_building}/blocks`

- Назначение: Операция контроллера `App\Http\Controllers\NewBuildingBlockController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\NewBuildingBlockController@store`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `201`: `$block, 201`

## 235) `GET /api/new-buildings/{new_building}/blocks/{block}`

- Назначение: Операция контроллера `App\Http\Controllers\NewBuildingBlockController@show`
- Auth: no
- Controller: `App\Http\Controllers\NewBuildingBlockController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `Laravel resource/model response`

## 236) `PUT|PATCH /api/new-buildings/{new_building}/blocks/{block}`

- Назначение: Операция контроллера `App\Http\Controllers\NewBuildingBlockController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\NewBuildingBlockController@update`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 237) `DELETE /api/new-buildings/{new_building}/blocks/{block}`

- Назначение: Операция контроллера `App\Http\Controllers\NewBuildingBlockController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\NewBuildingBlockController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 238) `POST /api/new-buildings/{new_building}/features/{feature}`

- Назначение: Операция контроллера `App\Http\Controllers\NewBuildingController@attachFeature`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\NewBuildingController@attachFeature`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['ok' => true]`

## 239) `DELETE /api/new-buildings/{new_building}/features/{feature}`

- Назначение: Операция контроллера `App\Http\Controllers\NewBuildingController@detachFeature`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\NewBuildingController@detachFeature`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['ok' => true]`

## 240) `GET /api/new-buildings/{new_building}/photos`

- Назначение: Операция контроллера `App\Http\Controllers\NewBuildingPhotoController@index`
- Auth: no
- Controller: `App\Http\Controllers\NewBuildingPhotoController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 241) `POST /api/new-buildings/{new_building}/photos`

- Назначение: POST /api/new-buildings/{new_building}/photos
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\NewBuildingPhotoController@store`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `[ 'message' => 'Передайте либо file (multipart/form-data`

## 242) `PUT /api/new-buildings/{new_building}/photos/reorder`

- Назначение: POST /api/new-buildings/{new_building}/photos
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\NewBuildingPhotoController@reorder`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 243) `DELETE /api/new-buildings/{new_building}/photos/{photo}`

- Назначение: Операция контроллера `App\Http\Controllers\NewBuildingPhotoController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\NewBuildingPhotoController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 244) `POST /api/new-buildings/{new_building}/photos/{photo}/cover`

- Назначение: Операция контроллера `App\Http\Controllers\NewBuildingPhotoController@setCover`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\NewBuildingPhotoController@setCover`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['ok' => true]`

## 245) `GET /api/new-buildings/{new_building}/units`

- Назначение: Операция контроллера `App\Http\Controllers\DeveloperUnitController@index`
- Auth: no
- Controller: `App\Http\Controllers\DeveloperUnitController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 246) `POST /api/new-buildings/{new_building}/units`

- Назначение: Операция контроллера `App\Http\Controllers\DeveloperUnitController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DeveloperUnitController@store`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$unit->load('block'`

## 247) `GET /api/new-buildings/{new_building}/units/{unit}`

- Назначение: Операция контроллера `App\Http\Controllers\DeveloperUnitController@show`
- Auth: no
- Controller: `App\Http\Controllers\DeveloperUnitController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 248) `PUT|PATCH /api/new-buildings/{new_building}/units/{unit}`

- Назначение: Операция контроллера `App\Http\Controllers\DeveloperUnitController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DeveloperUnitController@update`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 249) `DELETE /api/new-buildings/{new_building}/units/{unit}`

- Назначение: Операция контроллера `App\Http\Controllers\DeveloperUnitController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DeveloperUnitController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 250) `GET /api/new-buildings/{new_building}/units/{unit}/photos`

- Назначение: Операция контроллера `App\Http\Controllers\DeveloperUnitPhotoController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DeveloperUnitPhotoController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 251) `POST /api/new-buildings/{new_building}/units/{unit}/photos`

- Назначение: Операция контроллера `App\Http\Controllers\DeveloperUnitPhotoController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DeveloperUnitPhotoController@store`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 252) `PUT /api/new-buildings/{new_building}/units/{unit}/photos/reorder`

- Назначение: Операция контроллера `App\Http\Controllers\DeveloperUnitPhotoController@reorder`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DeveloperUnitPhotoController@reorder`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 253) `DELETE /api/new-buildings/{new_building}/units/{unit}/photos/{photo}`

- Назначение: Операция контроллера `App\Http\Controllers\DeveloperUnitPhotoController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DeveloperUnitPhotoController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 254) `POST /api/new-buildings/{new_building}/units/{unit}/photos/{photo}/cover`

- Назначение: Операция контроллера `App\Http\Controllers\DeveloperUnitPhotoController@setCover`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\DeveloperUnitPhotoController@setCover`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 255) `GET /api/notifications`

- Назначение: Операция контроллера `App\Http\Controllers\NotificationController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\NotificationController@index`
- Payload:
  - `type`: `['nullable`
  - `category`: `['nullable`
  - `is_read`: `nullable|boolean`
  - `per_page`: `nullable|integer|min:1|max:100`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 256) `PATCH /api/notifications/read-all`

- Назначение: Операция контроллера `App\Http\Controllers\NotificationController@markAllRead`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\NotificationController@markAllRead`
- Payload:
  - `category`: `['nullable`
- Response:
  - `200`: `[ 'updated' => $this->notifications->markAllAsRead($this->authUser(`

## 257) `GET /api/notifications/unread-count`

- Назначение: Операция контроллера `App\Http\Controllers\NotificationController@unreadCount`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\NotificationController@unreadCount`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `[ 'unread_count' => $this->notifications->unreadCount($this->authUser(`

## 258) `PATCH /api/notifications/{notification}/read`

- Назначение: Операция контроллера `App\Http\Controllers\NotificationController@markRead`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\NotificationController@markRead`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$this->serialize($this->notifications->markAsRead($notification, $this->authUser(`

## 259) `GET /api/parking-types`

- Назначение: Операция контроллера `App\Http\Controllers\ParkingTypeController@index`
- Auth: no
- Controller: `App\Http\Controllers\ParkingTypeController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 260) `POST /api/parking-types`

- Назначение: Операция контроллера `App\Http\Controllers\ParkingTypeController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ParkingTypeController@store`
- Payload:
  - `name`: `required|string`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 261) `GET /api/parking-types/{parking_type}`

- Назначение: Операция контроллера `App\Http\Controllers\ParkingTypeController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ParkingTypeController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `Laravel resource/model response`

## 262) `PUT|PATCH /api/parking-types/{parking_type}`

- Назначение: Операция контроллера `App\Http\Controllers\ParkingTypeController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ParkingTypeController@update`
- Payload:
  - `name`: `required|string`
- Response:
  - `200`: `Laravel resource/model response`

## 263) `DELETE /api/parking-types/{parking_type}`

- Назначение: Операция контроллера `App\Http\Controllers\ParkingTypeController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ParkingTypeController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 264) `POST /api/password/reset/confirm`

- Назначение: Операция контроллера `App\Http\Controllers\AuthController@resetPassword`
- Auth: no
- Controller: `App\Http\Controllers\AuthController@resetPassword`
- Payload:
  - `phone`: `required|string`
  - `code`: `required|string`
  - `new_password`: `required|string|min:6|confirmed`
- Response:
  - `404`: `['message' => 'Пользователь не найден'], 404`

## 265) `POST /api/password/reset/request`

- Назначение: Операция контроллера `App\Http\Controllers\AuthController@requestPasswordResetCode`
- Auth: no
- Controller: `App\Http\Controllers\AuthController@requestPasswordResetCode`
- Payload:
  - `phone`: `required|string`
  - `channel`: `required|string|in:sms`
- Response:
  - `404`: `['message' => 'Пользователь не найден'], 404`

## 266) `GET /api/ping`

- Назначение: Операция контроллера `Closure`
- Auth: no
- Controller: `Closure`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 267) `GET /api/properties`

- Назначение: @var User|null $user */
- Auth: no
- Controller: `App\Http\Controllers\PropertyController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$query->latest(`

## 268) `POST /api/properties`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyController@store`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `[ 'message' => 'Найдены возможные дубликаты (телефон/адрес/гео/этаж/площадь`

## 269) `GET /api/properties/map`

- Назначение: @var User|null $user */
- Auth: no
- Controller: `App\Http\Controllers\PropertyController@map`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `400`: `['error' => 'Invalid bbox. Expected south,west,north,east'], 400`

## 270) `GET /api/properties/{property}`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyController@show`
- Auth: no
- Controller: `App\Http\Controllers\PropertyController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 271) `PUT /api/properties/{property}`

- Назначение: @var User|null $user */
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyController@update`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 272) `DELETE /api/properties/{property}`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 273) `POST /api/properties/{property}/deal`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyController@saveDeal`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyController@saveDeal`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 274) `GET /api/properties/{property}/logs`

- Назначение: @var User|null $user */
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyController@logs`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$logs`

## 275) `GET /api/properties/{property}/matching-clients`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyController@matchingClients`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyController@matchingClients`
- Payload:
  - `limit`: `nullable|integer|min:1|max:20`
- Response:
  - `200`: `[ 'property' => [ 'id' => $property->id, 'title' => $property->title, 'price' => $property->price, 'currency' => $property->currency, 'offer_type' => $property->offer_type, 'district' => $property->district, 'rooms' => $`

## 276) `PATCH /api/properties/{property}/moderation-listing`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyController@updateModerationAndListingType`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyController@updateModerationAndListingType`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 277) `POST /api/properties/{property}/photos`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyPhotoController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyPhotoController@store`
- Payload:
  - `photos`: `['required`
  - `photos.*`: `['file`
  - `photo_positions`: `['nullable`
  - `photo_positions.*`: `['integer`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 278) `PUT /api/properties/{property}/photos/reorder`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyPhotoController@reorder`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyPhotoController@reorder`
- Payload:
  - `photo_order`: `['required`
  - `photo_order.*`: `['integer`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 279) `DELETE /api/properties/{property}/photos/{photo}`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyPhotoController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyPhotoController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 280) `GET /api/properties/{property}/reels`

- Назначение: Операция контроллера `App\Http\Controllers\ReelController@propertyIndex`
- Auth: no
- Controller: `App\Http\Controllers\ReelController@propertyIndex`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 281) `GET /api/properties/{property}/similar`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyController@similar`
- Auth: no
- Controller: `App\Http\Controllers\PropertyController@similar`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 282) `POST /api/properties/{property}/view`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyController@trackView`
- Auth: no
- Controller: `App\Http\Controllers\PropertyController@trackView`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 283) `GET /api/property-statuses`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyStatusController@index`
- Auth: no
- Controller: `App\Http\Controllers\PropertyStatusController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `PropertyStatus::all(`

## 284) `POST /api/property-statuses`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyStatusController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyStatusController@store`
- Payload:
  - `name`: `required|string|unique:property_statuses`
  - `slug`: `required|string|unique:property_statuses`
- Response:
  - `201`: `$status, 201`

## 285) `GET /api/property-statuses/{property_status}`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyStatusController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyStatusController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$propertyStatus`

## 286) `PUT|PATCH /api/property-statuses/{property_status}`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyStatusController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyStatusController@update`
- Payload:
  - `name`: `sometimes|string|unique:property_statuses`
  - `slug`: `sometimes|string|unique:property_statuses`
- Response:
  - `200`: `$propertyStatus`

## 287) `DELETE /api/property-statuses/{property_status}`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyStatusController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyStatusController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['message' => 'Удалено']`

## 288) `GET /api/property-types`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyTypeController@index`
- Auth: no
- Controller: `App\Http\Controllers\PropertyTypeController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `PropertyType::all(`

## 289) `POST /api/property-types`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyTypeController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyTypeController@store`
- Payload:
  - `name`: `required|string|unique:property_types`
  - `slug`: `required|string|unique:property_types`
- Response:
  - `201`: `$type, 201`

## 290) `GET /api/property-types/{property_type}`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyTypeController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyTypeController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$propertyType`

## 291) `PUT|PATCH /api/property-types/{property_type}`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyTypeController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyTypeController@update`
- Payload:
  - `name`: `sometimes|string|unique:property_types`
  - `slug`: `sometimes|string|unique:property_types`
- Response:
  - `200`: `$propertyType`

## 292) `DELETE /api/property-types/{property_type}`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyTypeController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyTypeController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['message' => 'Удалено']`

## 293) `GET /api/public/realtors/{id}`

- Назначение: Операция контроллера `App\Http\Controllers\PublicRealtorController@show`
- Auth: no
- Controller: `App\Http\Controllers\PublicRealtorController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 294) `GET /api/public/team/hall-of-fame`

- Назначение: Операция контроллера `App\Http\Controllers\PublicTeamController@hallOfFame`
- Auth: no
- Controller: `App\Http\Controllers\PublicTeamController@hallOfFame`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 295) `GET /api/reels`

- Назначение: Операция контроллера `App\Http\Controllers\ReelController@index`
- Auth: no
- Controller: `App\Http\Controllers\ReelController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 296) `POST /api/reels`

- Назначение: Операция контроллера `App\Http\Controllers\ReelController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ReelController@store`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 297) `POST /api/reels/direct-upload`

- Назначение: Операция контроллера `App\Http\Controllers\ReelController@initDirectUpload`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ReelController@initDirectUpload`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 298) `GET /api/reels/{id}`

- Назначение: Операция контроллера `App\Http\Controllers\ReelController@show`
- Auth: no
- Controller: `App\Http\Controllers\ReelController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 299) `PUT /api/reels/{reel}`

- Назначение: Операция контроллера `App\Http\Controllers\ReelController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ReelController@update`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 300) `PATCH /api/reels/{reel}`

- Назначение: Операция контроллера `App\Http\Controllers\ReelController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ReelController@update`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 301) `DELETE /api/reels/{reel}`

- Назначение: Операция контроллера `App\Http\Controllers\ReelController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ReelController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 302) `POST /api/reels/{reel}/complete-upload`

- Назначение: Операция контроллера `App\Http\Controllers\ReelController@completeDirectUpload`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ReelController@completeDirectUpload`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 303) `POST /api/reels/{reel}/like`

- Назначение: Операция контроллера `App\Http\Controllers\ReelController@like`
- Auth: no
- Controller: `App\Http\Controllers\ReelController@like`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 304) `DELETE /api/reels/{reel}/like`

- Назначение: Операция контроллера `App\Http\Controllers\ReelController@unlike`
- Auth: no
- Controller: `App\Http\Controllers\ReelController@unlike`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 305) `GET /api/reels/{reel}/like-status`

- Назначение: Операция контроллера `App\Http\Controllers\ReelController@likeStatus`
- Auth: no
- Controller: `App\Http\Controllers\ReelController@likeStatus`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 306) `PATCH /api/reels/{reel}/publish`

- Назначение: Операция контроллера `App\Http\Controllers\ReelController@publish`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\ReelController@publish`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 307) `POST /api/reels/{reel}/view`

- Назначение: Операция контроллера `App\Http\Controllers\ReelController@trackView`
- Auth: no
- Controller: `App\Http\Controllers\ReelController@trackView`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 308) `POST /api/register`

- Назначение: Операция контроллера `App\Http\Controllers\AuthController@register`
- Auth: no
- Controller: `App\Http\Controllers\AuthController@register`
- Payload:
  - `name`: `required|string|max:255`
  - `phone`: `required|string|max:255|unique:users`
  - `email`: `nullable|email|max:255|unique:users`
  - `password`: `required|string|min:6|confirmed`
  - `device_name`: `nullable|string|max:255`
  - `platform`: `nullable|string|max:50`
  - `app_version`: `nullable|string|max:50`
- Response:
  - `500`: `['message' => 'Роль клиента не настроена в системе'], 500`

## 309) `GET /api/repair-types`

- Назначение: Операция контроллера `App\Http\Controllers\RepairTypeController@index`
- Auth: no
- Controller: `App\Http\Controllers\RepairTypeController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 310) `POST /api/repair-types`

- Назначение: Операция контроллера `App\Http\Controllers\RepairTypeController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\RepairTypeController@store`
- Payload:
  - `name`: `required|string|unique:repair_types`
- Response:
  - `201`: `$type, 201`

## 311) `GET /api/repair-types/{repair_type}`

- Назначение: Операция контроллера `App\Http\Controllers\RepairTypeController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\RepairTypeController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `Laravel resource/model response`

## 312) `PUT|PATCH /api/repair-types/{repair_type}`

- Назначение: Операция контроллера `App\Http\Controllers\RepairTypeController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\RepairTypeController@update`
- Payload:
  - `name`: `required|string|unique:repair_types`
- Response:
  - `200`: `$repairType`

## 313) `DELETE /api/repair-types/{repair_type}`

- Назначение: Операция контроллера `App\Http\Controllers\RepairTypeController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\RepairTypeController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['message' => 'Удалено']`

## 314) `GET /api/reports/agent/clients`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@agentClientsStats`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@agentClientsStats`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 315) `GET /api/reports/agent/contracts`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@agentContractsStats`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@agentContractsStats`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 316) `GET /api/reports/agent/earnings`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@agentEarningsReport`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@agentEarningsReport`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `[ 'sum_price' => round($sumPrice, 2`

## 317) `GET /api/reports/agent/shows`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@agentShowsStats`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@agentShowsStats`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 318) `GET /api/reports/agents/properties`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@agentPropertiesReport`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@agentPropertiesReport`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 319) `GET /api/reports/agents/{agent}/properties`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@agentPropertiesReport`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@agentPropertiesReport`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 320) `GET /api/reports/missing-phone/agents-by-status`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@missingPhoneAgentsByStatus`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@missingPhoneAgentsByStatus`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 321) `GET /api/reports/missing-phone/list`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@missingPhoneList`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@missingPhoneList`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 322) `GET /api/reports/properties/agents-leaderboard`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@agentsLeaderboard`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@agentsLeaderboard`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `Laravel resource/model response`

## 323) `GET /api/reports/properties/by-location`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@byLocation`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@byLocation`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `Laravel resource/model response`

## 324) `GET /api/reports/properties/by-status`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@byStatus`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@byStatus`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$rows`

## 325) `GET /api/reports/properties/by-type`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@byType`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@byType`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `Laravel resource/model response`

## 326) `GET /api/reports/properties/conversion`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@conversionFunnel`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@conversionFunnel`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 327) `GET /api/reports/properties/manager-efficiency`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@managerEfficiency`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@managerEfficiency`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 328) `GET /api/reports/properties/monthly-comparison`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@monthlyComparison`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@monthlyComparison`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `[ 'comparison_for' => $currentStart->format('Y-m'`

## 329) `GET /api/reports/properties/monthly-comparison-range`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@monthlyComparisonRange`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@monthlyComparisonRange`
- Payload:
  - `from_month`: `['required`
  - `to_month`: `['required`
  - `branch_id`: `['nullable`
- Response:
  - `422`: `[ 'message' => 'from_month должен быть меньше или равен to_month', 'errors' => [ 'from_month' => ['from_month должен быть меньше или равен to_month'], ], ], 422`

## 330) `GET /api/reports/properties/price-buckets`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@priceBuckets`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@priceBuckets`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `[ 'range' => [$min, $max], 'buckets' => [], ]`

## 331) `GET /api/reports/properties/rooms-hist`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@roomsHistogram`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@roomsHistogram`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$rows`

## 332) `GET /api/reports/properties/summary`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@summary`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@summary`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `[ 'total' => $total, 'published_sale' => $publishedSale, 'published_rent' => $publishedRent, 'by_status' => $byStatus, 'sold_status' => $soldStatus, 'by_offer_type' => $byOffer, 'avg_price' => round((float`

## 333) `GET /api/reports/properties/time-series`

- Назначение: Операция контроллера `App\Http\Controllers\PropertyReportController@timeSeries`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\PropertyReportController@timeSeries`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 334) `POST /api/reviews/request-code`

- Назначение: Отправляем SMS-код на номер, чтобы подтвердить владение номером перед добавлением отзыва.
- Auth: no
- Controller: `App\Http\Controllers\ReviewController@requestCode`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 335) `GET /api/roles`

- Назначение: Операция контроллера `App\Http\Controllers\RoleController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\RoleController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 336) `POST /api/roles`

- Назначение: Операция контроллера `App\Http\Controllers\RoleController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\RoleController@store`
- Payload:
  - `name`: `['required`
  - `slug`: `['required`
  - `description`: `['nullable`
- Response:
  - `201`: `$role, 201`

## 337) `GET /api/roles/{role}`

- Назначение: Операция контроллера `App\Http\Controllers\RoleController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\RoleController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$role`

## 338) `PUT|PATCH /api/roles/{role}`

- Назначение: Операция контроллера `App\Http\Controllers\RoleController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\RoleController@update`
- Payload:
  - `name`: `['sometimes`
  - `slug`: `[`
  - `description`: `['nullable`
- Response:
  - `200`: `$role`

## 339) `DELETE /api/roles/{role}`

- Назначение: Операция контроллера `App\Http\Controllers\RoleController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\RoleController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `409`: `[ 'message' => 'Нельзя удалить роль: к ней привязаны пользователи', ], 409`

## 340) `GET /api/selections`

- Назначение: Операция контроллера `App\Http\Controllers\SelectionController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\SelectionController@index`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 341) `POST /api/selections`

- Назначение: Операция контроллера `App\Http\Controllers\SelectionController@store`
- Auth: no
- Controller: `App\Http\Controllers\SelectionController@store`
- Payload:
  - `title`: `nullable|string|max:255`
  - `property_ids`: `required|array|min:1`
  - `property_ids.*`: `integer|exists:properties`
  - `channel`: `['nullable`
- Response:
  - `201`: `[ 'selection' => $selection, 'bitrix' => [ 'error' => 'DEAL_NOT_FOUND_OR_NO_ACCESS', 'debug' => $exists, ], ], 201`

## 342) `GET /api/selections/public/{hash}`

- Назначение: Операция контроллера `App\Http\Controllers\SelectionController@publicShow`
- Auth: no
- Controller: `App\Http\Controllers\SelectionController@publicShow`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 343) `GET /api/selections/{id}`

- Назначение: Операция контроллера `App\Http\Controllers\SelectionController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\SelectionController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 344) `POST /api/selections/{id}/events`

- Назначение: Операция контроллера `App\Http\Controllers\SelectionController@event`
- Auth: no
- Controller: `App\Http\Controllers\SelectionController@event`
- Payload:
  - `type`: `['required`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 345) `POST /api/showings`

- Назначение: Операция контроллера `App\Http\Controllers\BookingController@store`
- Auth: no
- Controller: `App\Http\Controllers\BookingController@store`
- Payload:
  - `property_id`: `required|exists:properties`
  - `agent_id`: `[`
  - `client_id`: `required|integer|exists:clients`
  - `start_time`: `required|date`
  - `end_time`: `required|date`
  - `note`: `nullable|string`
  - `client_name`: `prohibited`
  - `client_phone`: `prohibited`
  - `deal_id`: `nullable|integer`
  - `contact_id`: `nullable|integer`
  - `place`: `nullable|string`
  - `sync_to_b24`: `sometimes|boolean`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 346) `POST /api/sms/request`

- Назначение: Операция контроллера `App\Http\Controllers\AuthController@requestSmsCode`
- Auth: no
- Controller: `App\Http\Controllers\AuthController@requestSmsCode`
- Payload:
  - `phone`: `required|string`
- Response:
  - `200`: `Laravel resource/model response`

## 347) `POST /api/sms/verify`

- Назначение: Операция контроллера `App\Http\Controllers\AuthController@verifySmsCode`
- Auth: no
- Controller: `App\Http\Controllers\AuthController@verifySmsCode`
- Payload:
  - `phone`: `required|string`
  - `code`: `required|string`
  - `device_name`: `nullable|string|max:255`
  - `platform`: `nullable|string|max:50`
  - `app_version`: `nullable|string|max:50`
- Response:
  - `500`: `['message' => 'Роль клиента не настроена в системе'], 500`

## 348) `POST /api/stories`

- Назначение: Операция контроллера `App\Http\Controllers\StoryController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\StoryController@store`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 349) `GET /api/stories/feed`

- Назначение: Операция контроллера `App\Http\Controllers\StoryController@feed`
- Auth: no
- Controller: `App\Http\Controllers\StoryController@feed`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 350) `POST /api/stories/from-property/{property}`

- Назначение: Операция контроллера `App\Http\Controllers\StoryController@storeFromProperty`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\StoryController@storeFromProperty`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 351) `POST /api/stories/from-reel/{reel}`

- Назначение: Операция контроллера `App\Http\Controllers\StoryController@storeFromReel`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\StoryController@storeFromReel`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 352) `GET /api/stories/{story}`

- Назначение: Операция контроллера `App\Http\Controllers\StoryController@show`
- Auth: no
- Controller: `App\Http\Controllers\StoryController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 353) `PATCH /api/stories/{story}`

- Назначение: Операция контроллера `App\Http\Controllers\StoryController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\StoryController@update`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 354) `DELETE /api/stories/{story}`

- Назначение: Операция контроллера `App\Http\Controllers\StoryController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\StoryController@destroy`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 355) `PATCH /api/stories/{story}/status`

- Назначение: Операция контроллера `App\Http\Controllers\StoryController@changeStatus`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\StoryController@changeStatus`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 356) `POST /api/stories/{story}/view`

- Назначение: Операция контроллера `App\Http\Controllers\StoryController@trackView`
- Auth: no
- Controller: `App\Http\Controllers\StoryController@trackView`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 357) `GET /api/support/conversations`

- Назначение: Операция контроллера `App\Http\Controllers\SupportConversationController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\SupportConversationController@index`
- Payload:
  - `status`: `['nullable`
  - `per_page`: `nullable|integer|min:1|max:100`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 358) `POST /api/support/conversations`

- Назначение: Операция контроллера `App\Http\Controllers\SupportConversationController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\SupportConversationController@store`
- Payload:
  - `chat_session_id`: `nullable|string|max:100`
  - `summary`: `nullable|string|max:5000`
  - `meta`: `nullable|array`
- Response:
  - `200`: `$this->serializeThread($thread`

## 359) `GET /api/support/conversations/{conversation}`

- Назначение: Операция контроллера `App\Http\Controllers\SupportConversationController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\SupportConversationController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$this->serializeThread($thread`

## 360) `POST /api/telegram/auth/link`

- Назначение: Операция контроллера `App\Http\Controllers\TelegramAuthController@link`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\TelegramAuthController@link`
- Payload:
  - `id`: `required`
  - `first_name`: `nullable|string`
  - `last_name`: `nullable|string`
  - `username`: `nullable|string`
  - `photo_url`: `nullable|string`
  - `auth_date`: `required`
  - `hash`: `required|string`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 361) `POST /api/telegram/auth/login`

- Назначение: Операция контроллера `App\Http\Controllers\TelegramAuthController@login`
- Auth: no
- Controller: `App\Http\Controllers\TelegramAuthController@login`
- Payload:
  - `id`: `required`
  - `first_name`: `nullable|string`
  - `last_name`: `nullable|string`
  - `username`: `nullable|string`
  - `photo_url`: `nullable|string`
  - `auth_date`: `required`
  - `hash`: `required|string`
  - `device_name`: `nullable|string|max:255`
  - `platform`: `nullable|string|max:50`
  - `app_version`: `nullable|string|max:50`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 362) `POST /api/telegram/webhook`

- Назначение: Операция контроллера `App\Http\Controllers\TelegramAuthController@webhook`
- Auth: no
- Controller: `App\Http\Controllers\TelegramAuthController@webhook`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `['ok' => true]`

## 363) `GET /api/user`

- Назначение: Операция контроллера `App\Http\Controllers\UserController@index`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\UserController@index`
- Payload:
  - `name`: `nullable|string`
  - `phone`: `nullable|string`
  - `email`: `nullable|string`
  - `branch_id`: `nullable|integer|exists:branches`
  - `branch_group_id`: `nullable|integer|exists:branch_groups`
  - `role`: `nullable|string|exists:roles`
  - `roles`: `nullable|array`
  - `roles.*`: `string|exists:roles`
  - `report_agents`: `nullable|boolean`
  - `include_unassigned`: `nullable`
  - `status`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 364) `POST /api/user`

- Назначение: Операция контроллера `App\Http\Controllers\UserController@store`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\UserController@store`
- Payload:
  - `name`: `required|string`
  - `description`: `nullable|string`
  - `birthday`: `nullable|date`
  - `phone`: `required|string|unique:users`
  - `email`: `nullable|email|unique:users`
  - `role_id`: `required|exists:roles`
  - `branch_id`: `nullable|exists:branches`
  - `branch_group_id`: `nullable|integer|exists:branch_groups`
  - `auth_method`: `nullable|in:password`
  - `status`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 365) `GET /api/user/agents`

- Назначение: Операция контроллера `App\Http\Controllers\UserController@agents`
- Auth: no
- Controller: `App\Http\Controllers\UserController@agents`
- Payload:
  - `status`: `['nullable`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 366) `DELETE /api/user/photo`

- Назначение: Операция контроллера `App\Http\Controllers\UserController@deleteMyPhoto`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\UserController@deleteMyPhoto`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 367) `GET /api/user/profile`

- Назначение: Операция контроллера `App\Http\Controllers\UserController@profile`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\UserController@profile`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `array_merge($user->toArray(`

## 368) `PUT /api/user/profile`

- Назначение: Операция контроллера `App\Http\Controllers\UserController@updateProfile`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\UserController@updateProfile`
- Payload:
  - `name`: `sometimes|string`
  - `description`: `nullable|string`
  - `birthday`: `nullable|date`
  - `phone`: `sometimes|string|unique:users`
  - `email`: `sometimes|email|unique:users`
- Response:
  - `200`: `$user->fresh(['role', 'branch', 'branchGroup']`

## 369) `PATCH /api/user/profile`

- Назначение: Операция контроллера `App\Http\Controllers\UserController@updateProfile`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\UserController@updateProfile`
- Payload:
  - `name`: `sometimes|string`
  - `description`: `nullable|string`
  - `birthday`: `nullable|date`
  - `phone`: `sometimes|string|unique:users`
  - `email`: `sometimes|email|unique:users`
- Response:
  - `200`: `$user->fresh(['role', 'branch', 'branchGroup']`

## 370) `POST /api/user/update-password`

- Назначение: Операция контроллера `App\Http\Controllers\UserController@updatePassword`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\UserController@updatePassword`
- Payload:
  - `current_password`: `required`
  - `new_password`: `required|string|min:6|confirmed`
- Response:
  - `422`: `[ 'message' => 'Текущий пароль введён неверно', ], 422`

## 371) `GET /api/user/{user}`

- Назначение: Операция контроллера `App\Http\Controllers\UserController@show`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\UserController@show`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: `$user->load(['role', 'branch', 'branchGroup']`

## 372) `PUT|PATCH /api/user/{user}`

- Назначение: Операция контроллера `App\Http\Controllers\UserController@update`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\UserController@update`
- Payload:
  - `name`: `sometimes|string`
  - `description`: `nullable|string`
  - `birthday`: `nullable|date`
  - `phone`: `sometimes|string|unique:users`
  - `email`: `sometimes|email|unique:users`
  - `role_id`: `sometimes|exists:roles`
  - `branch_id`: `sometimes|nullable|exists:branches`
  - `branch_group_id`: `sometimes|nullable|integer|exists:branch_groups`
  - `auth_method`: `sometimes|nullable|in:password`
  - `status`: `['sometimes`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 373) `DELETE /api/user/{user}`

- Назначение: Операция контроллера `App\Http\Controllers\UserController@destroy`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\UserController@destroy`
- Payload:
  - `distribute_to_agents`: `nullable|boolean`
  - `agent_id`: `nullable|integer|exists:users`
- Response:
  - `422`: `[ 'message' => 'Укажите distribute_to_agents=true для авто-распределения ИЛИ передайте agent_id.', ], 422`

## 374) `POST /api/user/{user}/photo`

- Назначение: Операция контроллера `App\Http\Controllers\UserController@updatePhoto`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\UserController@updatePhoto`
- Payload:
  - `photo`: `required|image|max:2048`
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)

## 375) `POST /api/user/{user}/restore`

- Назначение: Операция контроллера `App\Http\Controllers\UserController@restore`
- Auth: yes (Bearer token required)
- Controller: `App\Http\Controllers\UserController@restore`
- Payload:
  - Не найден явный `request->validate([...])` в методе
- Response:
  - `200`: JSON/resource response (точная форма зависит от ресурса/сериализации)
