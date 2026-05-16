# Android API Integration Guide (Aura Estate)

Документ собран по фактическим маршрутам проекта (`php artisan route:list --path=api --json`) и предназначен для Android/frontend разработчика.

## 1) Базовая информация

- Base URL: `https://<your-domain>/api/`
- Формат: `application/json`
- Аутентификация: `Bearer <token>` (Laravel Sanctum)
- TTL токена: 24 часа (см. `AuthController::issueApiToken`)

### Обязательные заголовки

- `Accept: application/json`
- `Content-Type: application/json` (кроме multipart)
- `Authorization: Bearer <token>` для защищенных endpoint'ов

## 2) Быстрый старт для Android

### 2.1 Retrofit + OkHttp

```kotlin
interface ApiService {
    @POST("login")
    suspend fun login(@Body body: LoginRequest): AuthResponse

    @GET("user/profile")
    suspend fun getProfile(): UserProfileResponse
}
```

```kotlin
class AuthInterceptor(
    private val tokenProvider: () -> String?
) : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val requestBuilder = chain.request().newBuilder()
            .addHeader("Accept", "application/json")

        tokenProvider()?.let { token ->
            requestBuilder.addHeader("Authorization", "Bearer $token")
        }

        return chain.proceed(requestBuilder.build())
    }
}
```

```kotlin
val okHttp = OkHttpClient.Builder()
    .addInterceptor(AuthInterceptor { sessionStore.token })
    .build()

val retrofit = Retrofit.Builder()
    .baseUrl("https://<your-domain>/api/")
    .addConverterFactory(MoshiConverterFactory.create())
    .client(okHttp)
    .build()
```

### 2.2 Обработка ошибок (рекомендация)

- `401` -> токен истек/невалиден, делать logout + редирект на login.
- `403` -> недостаточно прав (роль/филиал/бизнес-правила).
- `422` -> ошибки валидации, показывать field-level ошибки.
- `429` -> throttling (часто для SMS/reviews/stories view).
- `500` -> серверная ошибка, показывать retry state.

## 3) Аутентификация и сессия

Ключевые endpoint'ы:

- `POST /api/login`
- `POST /api/logout`
- `POST /api/register`
- `POST /api/password/request-reset-code`
- `POST /api/password/reset`
- `POST /api/sms/request`
- `POST /api/sms/verify`
- `POST /api/telegram/auth/login`
- `POST /api/telegram/auth/link`

Типовой успешный auth-ответ включает:

- `token`
- `user`
- `device`
- `daily_report_status` (+ поля статуса ежедневного отчета)

## 4) Middleware и ограничения доступа

Часто встречается:

- `Authenticate:sanctum` -> нужен Bearer token
- `EnsureUserIsActive` -> пользователь должен быть active
- `EnsureDailyReportSubmitted` -> доступ к части функционала зависит от daily-report
- `EnsureUserIsNotClient` -> endpoint закрыт для роли client
- `EnforceRopBranchScope` -> данные фильтруются по branch/branch_group

Это важно для фронта: один и тот же endpoint у разных ролей может возвращать разные выборки (особенно отчеты, клиенты, сделки, KPI).

## 5) Публичные endpoint'ы (без Bearer)

| Method | Endpoint | Controller@action |
|---|---|---|
| `DELETE` | `/api/reels/{reel}/like` | App\Http\Controllers\ReelController@unlike |
| `GET` | `/api/agents/{agent}/reviews` | App\Http\Controllers\ReviewController@index |
| `GET` | `/api/branches` | App\Http\Controllers\BranchController@index |
| `GET` | `/api/building-types` | App\Http\Controllers\BuildingTypeController@index |
| `GET` | `/api/chat/history` | App\Http\Controllers\ChatController@history |
| `GET` | `/api/client-need-statuses` | App\Http\Controllers\ClientNeedStatusController@index |
| `GET` | `/api/client-need-types` | App\Http\Controllers\ClientNeedTypeController@index |
| `GET` | `/api/client-sources` | App\Http\Controllers\ClientSourceController@index |
| `GET` | `/api/client-types` | App\Http\Controllers\ClientTypeController@index |
| `GET` | `/api/construction-stages/{construction_stage}` | App\Http\Controllers\ConstructionStageController@show |
| `GET` | `/api/construction-stages` | App\Http\Controllers\ConstructionStageController@index |
| `GET` | `/api/contract-types` | App\Http\Controllers\ContractTypeController@index |
| `GET` | `/api/developers/{developer}` | App\Http\Controllers\DeveloperController@show |
| `GET` | `/api/developers` | App\Http\Controllers\DeveloperController@index |
| `GET` | `/api/features/{feature}` | App\Http\Controllers\FeatureController@show |
| `GET` | `/api/features` | App\Http\Controllers\FeatureController@index |
| `GET` | `/api/heating-types` | App\Http\Controllers\HeatingTypeController@index |
| `GET` | `/api/locations/{location}/districts` | App\Http\Controllers\LocationController@districts |
| `GET` | `/api/locations` | App\Http\Controllers\LocationController@index |
| `GET` | `/api/materials/{material}` | App\Http\Controllers\MaterialController@show |
| `GET` | `/api/materials` | App\Http\Controllers\MaterialController@index |
| `GET` | `/api/new-buildings/plans` | App\Http\Controllers\NewBuildingPlanController@index |
| `GET` | `/api/new-buildings/{new_building}/blocks/{block}` | App\Http\Controllers\NewBuildingBlockController@show |
| `GET` | `/api/new-buildings/{new_building}/blocks` | App\Http\Controllers\NewBuildingBlockController@index |
| `GET` | `/api/new-buildings/{new_building}/photos` | App\Http\Controllers\NewBuildingPhotoController@index |
| `GET` | `/api/new-buildings/{new_building}/units/{unit}` | App\Http\Controllers\DeveloperUnitController@show |
| `GET` | `/api/new-buildings/{new_building}/units` | App\Http\Controllers\DeveloperUnitController@index |
| `GET` | `/api/new-buildings/{new_building}` | App\Http\Controllers\NewBuildingController@show |
| `GET` | `/api/new-buildings` | App\Http\Controllers\NewBuildingController@index |
| `GET` | `/api/parking-types` | App\Http\Controllers\ParkingTypeController@index |
| `GET` | `/api/ping` | Closure |
| `GET` | `/api/properties/map` | App\Http\Controllers\PropertyController@map |
| `GET` | `/api/properties/{property}/reels` | App\Http\Controllers\ReelController@propertyIndex |
| `GET` | `/api/properties/{property}/similar` | App\Http\Controllers\PropertyController@similar |
| `GET` | `/api/properties/{property}` | App\Http\Controllers\PropertyController@show |
| `GET` | `/api/properties` | App\Http\Controllers\PropertyController@index |
| `GET` | `/api/property-statuses` | App\Http\Controllers\PropertyStatusController@index |
| `GET` | `/api/property-types` | App\Http\Controllers\PropertyTypeController@index |
| `GET` | `/api/public/realtors/{id}` | App\Http\Controllers\PublicRealtorController@show |
| `GET` | `/api/public/team/hall-of-fame` | App\Http\Controllers\PublicTeamController@hallOfFame |
| `GET` | `/api/reels/{id}` | App\Http\Controllers\ReelController@show |
| `GET` | `/api/reels/{reel}/like-status` | App\Http\Controllers\ReelController@likeStatus |
| `GET` | `/api/reels` | App\Http\Controllers\ReelController@index |
| `GET` | `/api/repair-types` | App\Http\Controllers\RepairTypeController@index |
| `GET` | `/api/selections/public/{hash}` | App\Http\Controllers\SelectionController@publicShow |
| `GET` | `/api/stories/feed` | App\Http\Controllers\StoryController@feed |
| `GET` | `/api/stories/{story}` | App\Http\Controllers\StoryController@show |
| `GET` | `/api/user/agents` | App\Http\Controllers\UserController@agents |
| `POST` | `/api/agents/{agent}/reviews` | App\Http\Controllers\ReviewController@store |
| `POST` | `/api/b24/token` | App\Http\Controllers\B24AuthController@issue |
| `POST` | `/api/chat` | App\Http\Controllers\ChatController@handle |
| `POST` | `/api/lead-requests` | App\Http\Controllers\LeadRequestController@store |
| `POST` | `/api/login` | App\Http\Controllers\AuthController@login |
| `POST` | `/api/password/reset/confirm` | App\Http\Controllers\AuthController@resetPassword |
| `POST` | `/api/password/reset/request` | App\Http\Controllers\AuthController@requestPasswordResetCode |
| `POST` | `/api/properties/{property}/view` | App\Http\Controllers\PropertyController@trackView |
| `POST` | `/api/reels/{reel}/like` | App\Http\Controllers\ReelController@like |
| `POST` | `/api/reels/{reel}/view` | App\Http\Controllers\ReelController@trackView |
| `POST` | `/api/register` | App\Http\Controllers\AuthController@register |
| `POST` | `/api/reviews/request-code` | App\Http\Controllers\ReviewController@requestCode |
| `POST` | `/api/selections/{id}/events` | App\Http\Controllers\SelectionController@event |
| `POST` | `/api/selections` | App\Http\Controllers\SelectionController@store |
| `POST` | `/api/showings` | App\Http\Controllers\BookingController@store |
| `POST` | `/api/sms/request` | App\Http\Controllers\AuthController@requestSmsCode |
| `POST` | `/api/sms/verify` | App\Http\Controllers\AuthController@verifySmsCode |
| `POST` | `/api/stories/{story}/view` | App\Http\Controllers\StoryController@trackView |
| `POST` | `/api/telegram/auth/login` | App\Http\Controllers\TelegramAuthController@login |
| `POST` | `/api/telegram/webhook` | App\Http\Controllers\TelegramAuthController@webhook |

## 6) Защищенные endpoint'ы (Bearer required)

Колонка `Access`:
- `auth` = обычный защищенный endpoint
- `rop.branch.scope` = дополнительно branch/group ограничение

| Method | Endpoint | Access |
|---|---|---|
| `DELETE` | `/api/branch-groups/{branch_group}` | rop.branch.scope |
| `DELETE` | `/api/branches/{branch}` | auth |
| `DELETE` | `/api/building-types/{building_type}` | auth |
| `DELETE` | `/api/client-need-statuses/{client_need_status}` | auth |
| `DELETE` | `/api/client-need-types/{client_need_type}` | auth |
| `DELETE` | `/api/client-needs/{client_need}` | auth |
| `DELETE` | `/api/client-types/{client_type}` | auth |
| `DELETE` | `/api/clients/{client}/collaborators/{user}` | auth |
| `DELETE` | `/api/clients/{client}` | auth |
| `DELETE` | `/api/construction-stages/{construction_stage}` | auth |
| `DELETE` | `/api/contract-types/{contract_type}` | auth |
| `DELETE` | `/api/conversations/{conversation}/participants/{user}` | auth |
| `DELETE` | `/api/deal-pipelines/{deal_pipeline}` | rop.branch.scope |
| `DELETE` | `/api/deal-stages/{deal_stage}` | auth |
| `DELETE` | `/api/deals/{deal}` | rop.branch.scope |
| `DELETE` | `/api/developers/{developer}` | auth |
| `DELETE` | `/api/favorites/{property_id}` | auth |
| `DELETE` | `/api/features/{feature}` | auth |
| `DELETE` | `/api/heating-types/{heating_type}` | auth |
| `DELETE` | `/api/leads/{lead}` | rop.branch.scope |
| `DELETE` | `/api/locations/{location}` | auth |
| `DELETE` | `/api/materials/{material}` | auth |
| `DELETE` | `/api/new-buildings/{new_building}/blocks/{block}` | auth |
| `DELETE` | `/api/new-buildings/{new_building}/features/{feature}` | auth |
| `DELETE` | `/api/new-buildings/{new_building}/photos/{photo}` | auth |
| `DELETE` | `/api/new-buildings/{new_building}/units/{unit}/photos/{photo}` | auth |
| `DELETE` | `/api/new-buildings/{new_building}/units/{unit}` | auth |
| `DELETE` | `/api/new-buildings/{new_building}` | auth |
| `DELETE` | `/api/parking-types/{parking_type}` | auth |
| `DELETE` | `/api/properties/{property}/photos/{photo}` | auth |
| `DELETE` | `/api/properties/{property}` | auth |
| `DELETE` | `/api/property-statuses/{property_status}` | auth |
| `DELETE` | `/api/property-types/{property_type}` | auth |
| `DELETE` | `/api/reels/{reel}` | auth |
| `DELETE` | `/api/repair-types/{repair_type}` | auth |
| `DELETE` | `/api/roles/{role}` | auth |
| `DELETE` | `/api/stories/{story}` | auth |
| `DELETE` | `/api/user/photo` | auth |
| `DELETE` | `/api/user/{user}` | auth |
| `GET` | `/api/admin/stories` | auth |
| `GET` | `/api/bookings/agents-report` | auth |
| `GET` | `/api/bookings/{id}` | auth |
| `GET` | `/api/bookings` | auth |
| `GET` | `/api/branch-groups/{branch_group}` | rop.branch.scope |
| `GET` | `/api/branch-groups` | rop.branch.scope |
| `GET` | `/api/branches/{branch}` | auth |
| `GET` | `/api/building-types/{building_type}` | auth |
| `GET` | `/api/client-need-statuses/{client_need_status}` | auth |
| `GET` | `/api/client-need-types/{client_need_type}` | auth |
| `GET` | `/api/client-needs/{client_need}` | auth |
| `GET` | `/api/client-types/{client_type}` | auth |
| `GET` | `/api/clients/settings` | auth |
| `GET` | `/api/clients/{client}/activities` | auth |
| `GET` | `/api/clients/{client}/collaborators` | auth |
| `GET` | `/api/clients/{client}/matching-properties` | auth |
| `GET` | `/api/clients/{client}/needs` | auth |
| `GET` | `/api/clients/{client}` | auth |
| `GET` | `/api/clients` | auth |
| `GET` | `/api/contract-types/{contract_type}` | auth |
| `GET` | `/api/conversations/{conversation}/messages` | auth |
| `GET` | `/api/conversations/{conversation}/participants` | auth |
| `GET` | `/api/conversations/{conversation}` | auth |
| `GET` | `/api/conversations` | auth |
| `GET` | `/api/crm/deals/{deal}/activities` | rop.branch.scope |
| `GET` | `/api/crm/leads/{lead}/activities` | rop.branch.scope |
| `GET` | `/api/crm/reports/performance` | rop.branch.scope |
| `GET` | `/api/crm/task-types` | auth |
| `GET` | `/api/crm/tasks/kpi-daily-summary` | rop.branch.scope |
| `GET` | `/api/crm/tasks/kpi-weekly-summary` | rop.branch.scope |
| `GET` | `/api/crm/tasks` | rop.branch.scope |
| `GET` | `/api/daily-reports/my/{date}` | auth |
| `GET` | `/api/daily-reports/my` | auth |
| `GET` | `/api/daily-reports/status` | auth |
| `GET` | `/api/daily-reports` | rop.branch.scope |
| `GET` | `/api/deal-pipelines/{dealPipeline}/board` | rop.branch.scope |
| `GET` | `/api/deal-pipelines/{deal_pipeline}` | rop.branch.scope |
| `GET` | `/api/deal-pipelines` | rop.branch.scope |
| `GET` | `/api/deal-stages/{deal_stage}` | auth |
| `GET` | `/api/deals/{deal}` | rop.branch.scope |
| `GET` | `/api/deals` | rop.branch.scope |
| `GET` | `/api/favorites` | auth |
| `GET` | `/api/heating-types/{heating_type}` | auth |
| `GET` | `/api/kpi-adjustments` | rop.branch.scope |
| `GET` | `/api/kpi-plans` | auth |
| `GET` | `/api/kpi-reports` | rop.branch.scope |
| `GET` | `/api/kpi/acceptance-runs` | rop.branch.scope |
| `GET` | `/api/kpi/adjustments/entities` | rop.branch.scope |
| `GET` | `/api/kpi/adjustments/meta` | rop.branch.scope |
| `GET` | `/api/kpi/adjustments` | rop.branch.scope |
| `GET` | `/api/kpi/daily/my-progress` | auth |
| `GET` | `/api/kpi/daily/my-report` | auth |
| `GET` | `/api/kpi/daily/report` | auth |
| `GET` | `/api/kpi/daily` | rop.branch.scope |
| `GET` | `/api/kpi/dashboard/debug` | rop.branch.scope |
| `GET` | `/api/kpi/dashboard` | rop.branch.scope |
| `GET` | `/api/kpi/early-risk-alerts` | rop.branch.scope |
| `GET` | `/api/kpi/integrations/status` | rop.branch.scope |
| `GET` | `/api/kpi/metric-mapping` | rop.branch.scope |
| `GET` | `/api/kpi/monthly` | rop.branch.scope |
| `GET` | `/api/kpi/ops/acceptance-runs` | rop.branch.scope |
| `GET` | `/api/kpi/ops/early-risk-alerts` | rop.branch.scope |
| `GET` | `/api/kpi/ops/integrations/status` | rop.branch.scope |
| `GET` | `/api/kpi/ops/period-contract` | rop.branch.scope |
| `GET` | `/api/kpi/ops/quality/issues` | rop.branch.scope |
| `GET` | `/api/kpi/ops/telegram/config` | rop.branch.scope |
| `GET` | `/api/kpi/period-contract` | rop.branch.scope |
| `GET` | `/api/kpi/plans/common/{planId}` | rop.branch.scope |
| `GET` | `/api/kpi/plans/common` | rop.branch.scope |
| `GET` | `/api/kpi/plans/eligible-users` | rop.branch.scope |
| `GET` | `/api/kpi/plans/list` | rop.branch.scope |
| `GET` | `/api/kpi/plans/{planId}` | rop.branch.scope |
| `GET` | `/api/kpi/plans` | rop.branch.scope |
| `GET` | `/api/kpi/quality/issues` | rop.branch.scope |
| `GET` | `/api/kpi/rop-plans/{id}` | rop.branch.scope |
| `GET` | `/api/kpi/rop-plans` | rop.branch.scope |
| `GET` | `/api/kpi/telegram-reports/config` | rop.branch.scope |
| `GET` | `/api/kpi/weekly` | rop.branch.scope |
| `GET` | `/api/leads/{lead}` | rop.branch.scope |
| `GET` | `/api/leads` | rop.branch.scope |
| `GET` | `/api/locations/{location}` | auth |
| `GET` | `/api/me/reminders/daily-report` | auth |
| `GET` | `/api/motivation/achievements` | auth |
| `GET` | `/api/motivation/my-overview` | auth |
| `GET` | `/api/motivation/rules` | auth |
| `GET` | `/api/my-properties` | auth |
| `GET` | `/api/my/stories` | auth |
| `GET` | `/api/new-buildings/{new_building}/units/{unit}/photos` | auth |
| `GET` | `/api/notifications/unread-count` | auth |
| `GET` | `/api/notifications` | auth |
| `GET` | `/api/parking-types/{parking_type}` | auth |
| `GET` | `/api/properties/{property}/logs` | auth |
| `GET` | `/api/properties/{property}/matching-clients` | auth |
| `GET` | `/api/property-statuses/{property_status}` | auth |
| `GET` | `/api/property-types/{property_type}` | auth |
| `GET` | `/api/repair-types/{repair_type}` | auth |
| `GET` | `/api/reports/agent/clients` | rop.branch.scope |
| `GET` | `/api/reports/agent/contracts` | rop.branch.scope |
| `GET` | `/api/reports/agent/earnings` | rop.branch.scope |
| `GET` | `/api/reports/agent/shows` | rop.branch.scope |
| `GET` | `/api/reports/agents/properties` | rop.branch.scope |
| `GET` | `/api/reports/agents/{agent}/properties` | rop.branch.scope |
| `GET` | `/api/reports/missing-phone/agents-by-status` | rop.branch.scope |
| `GET` | `/api/reports/missing-phone/list` | rop.branch.scope |
| `GET` | `/api/reports/properties/agents-leaderboard` | rop.branch.scope |
| `GET` | `/api/reports/properties/by-location` | rop.branch.scope |
| `GET` | `/api/reports/properties/by-status` | rop.branch.scope |
| `GET` | `/api/reports/properties/by-type` | rop.branch.scope |
| `GET` | `/api/reports/properties/conversion` | rop.branch.scope |
| `GET` | `/api/reports/properties/manager-efficiency` | rop.branch.scope |
| `GET` | `/api/reports/properties/monthly-comparison-range` | rop.branch.scope |
| `GET` | `/api/reports/properties/monthly-comparison` | rop.branch.scope |
| `GET` | `/api/reports/properties/price-buckets` | rop.branch.scope |
| `GET` | `/api/reports/properties/rooms-hist` | rop.branch.scope |
| `GET` | `/api/reports/properties/summary` | rop.branch.scope |
| `GET` | `/api/reports/properties/time-series` | rop.branch.scope |
| `GET` | `/api/roles/{role}` | auth |
| `GET` | `/api/roles` | auth |
| `GET` | `/api/selections/{id}` | auth |
| `GET` | `/api/selections` | auth |
| `GET` | `/api/support/conversations/{conversation}` | auth |
| `GET` | `/api/support/conversations` | auth |
| `GET` | `/api/user/profile` | auth |
| `GET` | `/api/user/{user}` | auth |
| `GET` | `/api/user` | auth |
| `PATCH` | `/api/admin/stories/{story}/status` | auth |
| `PATCH` | `/api/bookings/{id}` | auth |
| `PATCH` | `/api/clients/settings` | auth |
| `PATCH` | `/api/crm/tasks/{crmTask}` | rop.branch.scope |
| `PATCH` | `/api/daily-reports/{dailyReport}` | auth |
| `PATCH` | `/api/deal-pipelines/{dealPipeline}/stages/reorder` | auth |
| `PATCH` | `/api/deals/{deal}/move` | rop.branch.scope |
| `PATCH` | `/api/kpi-plans` | rop.branch.scope |
| `PATCH` | `/api/kpi/daily/report` | auth |
| `PATCH` | `/api/kpi/early-risk-alerts/status` | rop.branch.scope |
| `PATCH` | `/api/kpi/ops/early-risk-alerts/status` | rop.branch.scope |
| `PATCH` | `/api/kpi/ops/telegram/config` | rop.branch.scope |
| `PATCH` | `/api/kpi/plans/common` | rop.branch.scope |
| `PATCH` | `/api/kpi/plans/{userId}` | rop.branch.scope |
| `PATCH` | `/api/kpi/rop-plans/{id}` | rop.branch.scope |
| `PATCH` | `/api/kpi/telegram-reports/config` | rop.branch.scope |
| `PATCH` | `/api/motivation/reward-issues/{rewardIssue}` | auth |
| `PATCH` | `/api/motivation/rules/{rule}` | auth |
| `PATCH` | `/api/notifications/read-all` | auth |
| `PATCH` | `/api/notifications/{notification}/read` | auth |
| `PATCH` | `/api/properties/{property}/moderation-listing` | auth |
| `PATCH` | `/api/reels/{reel}/publish` | auth |
| `PATCH` | `/api/reels/{reel}` | auth |
| `PATCH` | `/api/stories/{story}/status` | auth |
| `PATCH` | `/api/stories/{story}` | auth |
| `PATCH` | `/api/user/profile` | auth |
| `POST` | `/api/bookings` | auth |
| `POST` | `/api/branch-groups` | rop.branch.scope |
| `POST` | `/api/branches` | auth |
| `POST` | `/api/building-types` | auth |
| `POST` | `/api/chat/escalate` | auth |
| `POST` | `/api/chat/feedback` | auth |
| `POST` | `/api/client-need-statuses` | auth |
| `POST` | `/api/client-need-types` | auth |
| `POST` | `/api/client-types` | auth |
| `POST` | `/api/clients/attach-existing` | auth |
| `POST` | `/api/clients/duplicate-check` | auth |
| `POST` | `/api/clients/{client}/collaborators` | auth |
| `POST` | `/api/clients/{client}/needs` | auth |
| `POST` | `/api/clients` | auth |
| `POST` | `/api/construction-stages` | auth |
| `POST` | `/api/contract-types` | auth |
| `POST` | `/api/conversations/direct` | auth |
| `POST` | `/api/conversations/{conversation}/messages` | auth |
| `POST` | `/api/conversations/{conversation}/participants` | auth |
| `POST` | `/api/conversations` | auth |
| `POST` | `/api/crm/deals/{deal}/activities` | rop.branch.scope |
| `POST` | `/api/crm/leads/{lead}/activities` | rop.branch.scope |
| `POST` | `/api/crm/tasks` | rop.branch.scope |
| `POST` | `/api/daily-reports` | auth |
| `POST` | `/api/deal-pipelines/{dealPipeline}/stages` | auth |
| `POST` | `/api/deal-pipelines` | rop.branch.scope |
| `POST` | `/api/deals` | rop.branch.scope |
| `POST` | `/api/developers` | auth |
| `POST` | `/api/favorites` | auth |
| `POST` | `/api/features` | auth |
| `POST` | `/api/heating-types` | auth |
| `POST` | `/api/kpi-adjustments` | rop.branch.scope |
| `POST` | `/api/kpi-period-locks` | rop.branch.scope |
| `POST` | `/api/kpi/adjustments` | rop.branch.scope |
| `POST` | `/api/kpi/daily/my-report` | auth |
| `POST` | `/api/kpi/daily` | auth |
| `POST` | `/api/kpi/plans/bulk-upsert` | rop.branch.scope |
| `POST` | `/api/kpi/plans/common/apply-to-users` | rop.branch.scope |
| `POST` | `/api/kpi/rop-plans/{id}/copy` | rop.branch.scope |
| `POST` | `/api/kpi/rop-plans` | rop.branch.scope |
| `POST` | `/api/leads/{lead}/convert` | rop.branch.scope |
| `POST` | `/api/leads` | rop.branch.scope |
| `POST` | `/api/locations` | auth |
| `POST` | `/api/logout` | auth |
| `POST` | `/api/materials` | auth |
| `POST` | `/api/motivation/recalculate` | auth |
| `POST` | `/api/motivation/reward-issues/{achievement}/assign` | auth |
| `POST` | `/api/motivation/rules` | auth |
| `POST` | `/api/new-buildings/{new_building}/blocks` | auth |
| `POST` | `/api/new-buildings/{new_building}/features/{feature}` | auth |
| `POST` | `/api/new-buildings/{new_building}/photos/{photo}/cover` | auth |
| `POST` | `/api/new-buildings/{new_building}/photos` | auth |
| `POST` | `/api/new-buildings/{new_building}/units/{unit}/photos/{photo}/cover` | auth |
| `POST` | `/api/new-buildings/{new_building}/units/{unit}/photos` | auth |
| `POST` | `/api/new-buildings/{new_building}/units` | auth |
| `POST` | `/api/new-buildings` | auth |
| `POST` | `/api/parking-types` | auth |
| `POST` | `/api/properties/{property}/deal` | auth |
| `POST` | `/api/properties/{property}/photos` | auth |
| `POST` | `/api/properties` | auth |
| `POST` | `/api/property-statuses` | auth |
| `POST` | `/api/property-types` | auth |
| `POST` | `/api/reels/direct-upload` | auth |
| `POST` | `/api/reels/{reel}/complete-upload` | auth |
| `POST` | `/api/reels` | auth |
| `POST` | `/api/repair-types` | auth |
| `POST` | `/api/roles` | auth |
| `POST` | `/api/stories/from-property/{property}` | auth |
| `POST` | `/api/stories/from-reel/{reel}` | auth |
| `POST` | `/api/stories` | auth |
| `POST` | `/api/support/conversations` | auth |
| `POST` | `/api/telegram/auth/link` | auth |
| `POST` | `/api/user/update-password` | auth |
| `POST` | `/api/user/{user}/photo` | auth |
| `POST` | `/api/user/{user}/restore` | auth |
| `POST` | `/api/user` | auth |
| `PUT` | `/api/bookings/{id}` | auth |
| `PUT` | `/api/clients/settings` | auth |
| `PUT` | `/api/crm/tasks/{crmTask}` | rop.branch.scope |
| `PUT` | `/api/daily-reports/{dailyReport}` | auth |
| `PUT` | `/api/kpi/plans/common` | rop.branch.scope |
| `PUT` | `/api/kpi/plans/{userId}` | rop.branch.scope |
| `PUT` | `/api/me/reminders/daily-report` | auth |
| `PUT` | `/api/new-buildings/{new_building}/photos/reorder` | auth |
| `PUT` | `/api/new-buildings/{new_building}/units/{unit}/photos/reorder` | auth |
| `PUT` | `/api/properties/{property}/photos/reorder` | auth |
| `PUT` | `/api/properties/{property}` | auth |
| `PUT` | `/api/reels/{reel}` | auth |
| `PUT` | `/api/user/profile` | auth |
| `PUT|PATCH` | `/api/branch-groups/{branch_group}` | rop.branch.scope |
| `PUT|PATCH` | `/api/branches/{branch}` | auth |
| `PUT|PATCH` | `/api/building-types/{building_type}` | auth |
| `PUT|PATCH` | `/api/client-need-statuses/{client_need_status}` | auth |
| `PUT|PATCH` | `/api/client-need-types/{client_need_type}` | auth |
| `PUT|PATCH` | `/api/client-needs/{client_need}` | auth |
| `PUT|PATCH` | `/api/client-types/{client_type}` | auth |
| `PUT|PATCH` | `/api/clients/{client}` | auth |
| `PUT|PATCH` | `/api/construction-stages/{construction_stage}` | auth |
| `PUT|PATCH` | `/api/contract-types/{contract_type}` | auth |
| `PUT|PATCH` | `/api/deal-pipelines/{deal_pipeline}` | rop.branch.scope |
| `PUT|PATCH` | `/api/deal-stages/{deal_stage}` | auth |
| `PUT|PATCH` | `/api/deals/{deal}` | rop.branch.scope |
| `PUT|PATCH` | `/api/developers/{developer}` | auth |
| `PUT|PATCH` | `/api/features/{feature}` | auth |
| `PUT|PATCH` | `/api/heating-types/{heating_type}` | auth |
| `PUT|PATCH` | `/api/leads/{lead}` | rop.branch.scope |
| `PUT|PATCH` | `/api/locations/{location}` | auth |
| `PUT|PATCH` | `/api/materials/{material}` | auth |
| `PUT|PATCH` | `/api/new-buildings/{new_building}/blocks/{block}` | auth |
| `PUT|PATCH` | `/api/new-buildings/{new_building}/units/{unit}` | auth |
| `PUT|PATCH` | `/api/new-buildings/{new_building}` | auth |
| `PUT|PATCH` | `/api/parking-types/{parking_type}` | auth |
| `PUT|PATCH` | `/api/property-statuses/{property_status}` | auth |
| `PUT|PATCH` | `/api/property-types/{property_type}` | auth |
| `PUT|PATCH` | `/api/repair-types/{repair_type}` | auth |
| `PUT|PATCH` | `/api/roles/{role}` | auth |
| `PUT|PATCH` | `/api/user/{user}` | auth |

## 7) Рекомендации по интеграции

- Держите enum/константы на клиенте для: `roles`, `statuses`, `metric_key`, `deal stages`.
- Для крупных списков используйте единый интерфейс пагинации (`page`, `per_page`) и проверяйте `meta`.
- Для модулей KPI/отчетов всегда передавайте `date`, `year/week/month`, `branch_id`/`branch_group_id` строго по экрану.
- Для upload endpoint'ов (`photo`, `reels`, `stories`) сразу закладывайте `multipart` + прогресс + retry.
- Для 403 на report endpoint'ах показывайте штатный empty/error state (без крэша экрана).

## 8) Что обновлять при изменении API

После каждого backend-релиза:

1. Перегенерировать маршрутную таблицу:
   - `php artisan route:list --path=api --json`
2. Обновить этот документ.
3. Проверить Android-контракты DTO на breaking changes.

---

Если нужно, следующим шагом сделаю версию этого документа в формате "по экранам Android" (Login, CRM, Objects, Reports, KPI, Messaging) с точными JSON-примерами request/response для каждого экрана.


## Полная спецификация

- Подробно по каждому endpoint: [docs/android-api-full-endpoints.md](/Users/duck/WebstormProjects/aura-estate/docs/android-api-full-endpoints.md)
