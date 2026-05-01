<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\B24AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchGroupController;
use App\Http\Controllers\BuildingTypeController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientNeedController;
use App\Http\Controllers\ClientNeedStatusController;
use App\Http\Controllers\ClientNeedTypeController;
use App\Http\Controllers\ClientSourceController;
use App\Http\Controllers\ClientTypeController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\ConversationMessageController;
use App\Http\Controllers\ConversationParticipantController;
use App\Http\Controllers\ConstructionStageController;
use App\Http\Controllers\ContractTypeController;
use App\Http\Controllers\CrmActivityController;
use App\Http\Controllers\CrmReportController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\DealPipelineController;
use App\Http\Controllers\DealStageController;
use App\Http\Controllers\DailyReportController;
use App\Http\Controllers\DeveloperController;
use App\Http\Controllers\DeveloperUnitController;
use App\Http\Controllers\DeveloperUnitPhotoController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\FeatureController;
use App\Http\Controllers\HeatingTypeController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadRequestController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\NewBuildingBlockController;
use App\Http\Controllers\NewBuildingController;
use App\Http\Controllers\NewBuildingPhotoController;
use App\Http\Controllers\NewBuildingPlanController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ParkingTypeController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\PropertyPhotoController;
use App\Http\Controllers\PropertyReportController;
use App\Http\Controllers\PropertyStatusController;
use App\Http\Controllers\PropertyTypeController;
use App\Http\Controllers\PublicRealtorController;
use App\Http\Controllers\PublicTeamController;
use App\Http\Controllers\RepairTypeController;
use App\Http\Controllers\ReelController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SelectionController;
use App\Http\Controllers\SupportConversationController;
use App\Http\Controllers\TelegramAuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StoryController;
use App\Http\Controllers\AdminStoryController;
use Illuminate\Support\Facades\Route;

// --- ПИНГ ---
Route::get('/ping', fn () => response()->json(['message' => 'API works']));

// --- ПУБЛИЧНЫЕ ---
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/sms/request', [AuthController::class, 'requestSmsCode']);
Route::post('/sms/verify', [AuthController::class, 'verifySmsCode']);
Route::post('/password/reset/request', [AuthController::class, 'requestPasswordResetCode']);
Route::post('/password/reset/confirm', [AuthController::class, 'resetPassword']);
Route::post('/telegram/auth/login', [TelegramAuthController::class, 'login']);
Route::post('/lead-requests', [LeadRequestController::class, 'store'])->middleware('throttle:20,1');

Route::get('/properties', [PropertyController::class, 'index']);
Route::get('/properties/map', [PropertyController::class, 'map']);
Route::get('/properties/{property}', [PropertyController::class, 'show']);
Route::get('/properties/{property}/similar', [PropertyController::class, 'similar']);
Route::post('/properties/{property}/view', [PropertyController::class, 'trackView'])->middleware('throttle:30,1');
Route::get('/properties/{property}/reels', [ReelController::class, 'propertyIndex']);
Route::get('/reels', [ReelController::class, 'index']);
Route::get('/reels/{id}', [ReelController::class, 'show'])->whereNumber('id');
Route::post('/reels/{reel}/view', [ReelController::class, 'trackView'])->middleware('throttle:30,1');
Route::post('/reels/{reel}/like', [ReelController::class, 'like'])->middleware('throttle:60,1');
Route::delete('/reels/{reel}/like', [ReelController::class, 'unlike'])->middleware('throttle:60,1');
Route::get('/reels/{reel}/like-status', [ReelController::class, 'likeStatus'])->middleware('throttle:60,1');

Route::get('/property-types', [PropertyTypeController::class, 'index']);
Route::get('/property-statuses', [PropertyStatusController::class, 'index']);
Route::get('/locations', [LocationController::class, 'index']);
Route::get('/locations/{location}/districts', [LocationController::class, 'districts'])->whereNumber('location');
Route::get('/building-types', [BuildingTypeController::class, 'index']);
Route::get('/parking-types', [ParkingTypeController::class, 'index']);
Route::get('/heating-types', [HeatingTypeController::class, 'index']);
Route::get('/repair-types', [RepairTypeController::class, 'index']);
Route::get('/contract-types', [ContractTypeController::class, 'index']);
Route::get('/branches', [BranchController::class, 'index']);
Route::get('/client-types', [ClientTypeController::class, 'index']);
Route::get('/client-sources', [ClientSourceController::class, 'index']);
Route::get('/client-need-types', [ClientNeedTypeController::class, 'index']);
Route::get('/client-need-statuses', [ClientNeedStatusController::class, 'index']);

Route::get('/user/agents', [UserController::class, 'agents']);
Route::get('/public/realtors/{id}', [PublicRealtorController::class, 'show'])->whereNumber('id');
Route::get('/public/team/hall-of-fame', [PublicTeamController::class, 'hallOfFame']);
Route::get('/stories/feed', [StoryController::class, 'feed']);
Route::get('/stories/{story}', [StoryController::class, 'show'])->whereNumber('story');
Route::post('/stories/{story}/view', [StoryController::class, 'trackView'])->whereNumber('story')->middleware('throttle:120,1');
Route::post('/reviews/request-code', [ReviewController::class, 'requestCode'])->middleware('throttle:10,1');
Route::get('/agents/{agent}/reviews', [ReviewController::class, 'index'])->whereNumber('agent');
Route::post('/agents/{agent}/reviews', [ReviewController::class, 'store'])->middleware('throttle:10,1')->whereNumber('agent');

// --- Новостройки (public index/show + ВЛОЖЕННЫЕ index/show) ---
Route::scopeBindings()->group(function () {
    Route::apiResource('developers', DeveloperController::class)->only(['index', 'show']);
    Route::apiResource('construction-stages', ConstructionStageController::class)->only(['index', 'show']);
    Route::apiResource('materials', MaterialController::class)->only(['index', 'show']);
    Route::apiResource('features', FeatureController::class)->only(['index', 'show']);

    Route::get('new-buildings/plans', [NewBuildingPlanController::class, 'index']);
    Route::apiResource('new-buildings', NewBuildingController::class)->only(['index', 'show']);

    // blocks (полностью вложенные; публично index/show)
    Route::apiResource('new-buildings.blocks', NewBuildingBlockController::class)->only(['index', 'show']);

    // units (полностью вложенные; публично index/show)
    Route::apiResource('new-buildings.units', DeveloperUnitController::class)->only(['index', 'show']);

    // ФОТО новостройки (полностью вложенные; публично index)
    Route::get('new-buildings/{new_building}/photos', [NewBuildingPhotoController::class, 'index']);
});

// история чата (публично)
Route::get('/chat/history', [ChatController::class, 'history']);

// --- ЗАЩИЩЁННЫЕ ---
Route::middleware(['auth:sanctum', 'active.user', 'daily.report'])->group(function () {
    Route::get('/user/profile', [UserController::class, 'profile']);
    Route::put('/user/profile', [UserController::class, 'updateProfile']);
    Route::patch('/user/profile', [UserController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/daily-reports/status', [DailyReportController::class, 'status']);
    Route::get('/daily-reports/my', [DailyReportController::class, 'my']);
    Route::get('/daily-reports/my/{date}', [DailyReportController::class, 'showMine']);
    Route::get('/daily-reports', [DailyReportController::class, 'index'])->middleware('rop.branch.scope');
    Route::post('/daily-reports', [DailyReportController::class, 'store']);
    Route::put('/daily-reports/{dailyReport}', [DailyReportController::class, 'update']);
    Route::patch('/daily-reports/{dailyReport}', [DailyReportController::class, 'update']);
    Route::post('/telegram/auth/link', [TelegramAuthController::class, 'link']);
    Route::delete('/user/photo', [UserController::class, 'deleteMyPhoto']);
    Route::post('/user/update-password', [UserController::class, 'updatePassword']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::get('/my/stories', [StoryController::class, 'myStories']);
    Route::post('/stories', [StoryController::class, 'store'])->middleware('throttle:30,60');
    Route::post('/stories/from-property/{property}', [StoryController::class, 'storeFromProperty'])->middleware('throttle:30,60');
    Route::post('/stories/from-reel/{reel}', [StoryController::class, 'storeFromReel'])->middleware('throttle:30,60');
    Route::patch('/stories/{story}', [StoryController::class, 'update'])->whereNumber('story');
    Route::delete('/stories/{story}', [StoryController::class, 'destroy'])->whereNumber('story');
    Route::patch('/stories/{story}/status', [StoryController::class, 'changeStatus'])->whereNumber('story');
    Route::get('/admin/stories', [AdminStoryController::class, 'index']);
    Route::patch('/admin/stories/{story}/status', [AdminStoryController::class, 'updateStatus'])->whereNumber('story');

    // Messaging
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::post('/conversations', [ConversationController::class, 'store']);
    Route::post('/conversations/direct', [ConversationController::class, 'storeDirect']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::get('/conversations/{conversation}/messages', [ConversationMessageController::class, 'index']);
    Route::post('/conversations/{conversation}/messages', [ConversationMessageController::class, 'store']);
    Route::get('/conversations/{conversation}/participants', [ConversationParticipantController::class, 'index']);
    Route::post('/conversations/{conversation}/participants', [ConversationParticipantController::class, 'store']);
    Route::delete('/conversations/{conversation}/participants/{user}', [ConversationParticipantController::class, 'destroy']);
    Route::get('/support/conversations', [SupportConversationController::class, 'index']);
    Route::post('/support/conversations', [SupportConversationController::class, 'store']);
    Route::get('/support/conversations/{conversation}', [SupportConversationController::class, 'show']);
    Route::post('/chat/escalate', [SupportConversationController::class, 'store']);

    // Избранное
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/favorites', [FavoriteController::class, 'store']);
    Route::delete('/favorites/{property_id}', [FavoriteController::class, 'destroy']);

    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/bookings/{id}', [BookingController::class, 'show'])->whereNumber('id');
    Route::middleware('non.client')->group(function () {
        Route::get('/my-properties', [PropertyController::class, 'myProperties']);

        // Properties CRUD + photos
        Route::post('/properties', [PropertyController::class, 'store']);
        Route::get('/properties/{property}/logs', [PropertyController::class, 'logs']);
        Route::get('/properties/{property}/matching-clients', [PropertyController::class, 'matchingClients']);
        Route::put('/properties/{property}', [PropertyController::class, 'update']);
        Route::delete('/properties/{property}', [PropertyController::class, 'destroy']);
        Route::patch('/properties/{property}/moderation-listing', [PropertyController::class, 'updateModerationAndListingType']);
        Route::post('properties/{property}/photos', [PropertyPhotoController::class, 'store']);
        Route::put('properties/{property}/photos/reorder', [PropertyPhotoController::class, 'reorder']);
        Route::delete('properties/{property}/photos/{photo}', [PropertyPhotoController::class, 'destroy'])->whereNumber('photo');
        Route::post('/reels/direct-upload', [ReelController::class, 'initDirectUpload']);
        Route::post('/reels/{reel}/complete-upload', [ReelController::class, 'completeDirectUpload']);
        Route::post('/reels', [ReelController::class, 'store']);
        Route::put('/reels/{reel}', [ReelController::class, 'update']);
        Route::patch('/reels/{reel}', [ReelController::class, 'update']);
        Route::patch('/reels/{reel}/publish', [ReelController::class, 'publish']);
        Route::delete('/reels/{reel}', [ReelController::class, 'destroy']);

        // Справочники (админ)
        Route::apiResource('property-types', PropertyTypeController::class)->except(['index']);
        Route::apiResource('property-statuses', PropertyStatusController::class)->except(['index']);
        Route::apiResource('locations', LocationController::class)->except(['index']);
        Route::apiResource('building-types', BuildingTypeController::class)->except(['index']);
        Route::apiResource('parking-types', ParkingTypeController::class)->except(['index']);
        Route::apiResource('heating-types', HeatingTypeController::class)->except(['index']);
        Route::apiResource('contract-types', ContractTypeController::class)->except(['index']);
        Route::apiResource('repair-types', RepairTypeController::class)->except(['index']);
        Route::apiResource('branches', BranchController::class)->except(['index']);
        Route::apiResource('branch-groups', BranchGroupController::class)->middleware('rop.branch.scope');
        Route::apiResource('developers', DeveloperController::class)->except(['index', 'show']);
        Route::apiResource('features', FeatureController::class)->except(['index', 'show']);
        Route::apiResource('materials', MaterialController::class)->except(['index', 'show']);
        Route::apiResource('construction-stages', ConstructionStageController::class)->except(['index', 'show']);

        Route::apiResource('roles', RoleController::class);
        Route::post('/user/{user}/photo', [UserController::class, 'updatePhoto']);
        Route::apiResource('user', UserController::class);
        Route::get('/clients/settings', [ClientController::class, 'settings']);
        Route::put('/clients/settings', [ClientController::class, 'updateSettings']);
        Route::patch('/clients/settings', [ClientController::class, 'updateSettings']);
        Route::get('/deal-pipelines/{dealPipeline}/board', [DealPipelineController::class, 'board'])->middleware('rop.branch.scope');
        Route::patch('/deal-pipelines/{dealPipeline}/stages/reorder', [DealStageController::class, 'reorder']);
        Route::post('/deal-pipelines/{dealPipeline}/stages', [DealStageController::class, 'store']);
        Route::apiResource('deal-pipelines', DealPipelineController::class)->middleware('rop.branch.scope');
        Route::apiResource('deal-stages', DealStageController::class)->only(['show', 'update', 'destroy']);
        Route::patch('/deals/{deal}/move', [DealController::class, 'move'])->middleware('rop.branch.scope');
        Route::apiResource('deals', DealController::class)->middleware('rop.branch.scope');
        Route::post('/leads/{lead}/convert', [LeadController::class, 'convert'])->middleware('rop.branch.scope');
        Route::apiResource('leads', LeadController::class)->middleware('rop.branch.scope');
        Route::get('/crm/leads/{lead}/activities', [CrmActivityController::class, 'leadIndex'])->middleware('rop.branch.scope');
        Route::post('/crm/leads/{lead}/activities', [CrmActivityController::class, 'leadStore'])->middleware('rop.branch.scope');
        Route::get('/crm/deals/{deal}/activities', [CrmActivityController::class, 'dealIndex'])->middleware('rop.branch.scope');
        Route::post('/crm/deals/{deal}/activities', [CrmActivityController::class, 'dealStore'])->middleware('rop.branch.scope');
        Route::get('/crm/reports/performance', [CrmReportController::class, 'performance'])->middleware('rop.branch.scope');
        Route::post('/clients/duplicate-check', [ClientController::class, 'duplicateCheck']);
        Route::post('/clients/attach-existing', [ClientController::class, 'attachExisting']);
        Route::get('/clients/{client}/activities', [ClientController::class, 'activities']);
        Route::get('/clients/{client}/matching-properties', [ClientController::class, 'matchingProperties']);
        Route::get('/clients/{client}/collaborators', [ClientController::class, 'collaborators']);
        Route::post('/clients/{client}/collaborators', [ClientController::class, 'storeCollaborator']);
        Route::delete('/clients/{client}/collaborators/{user}', [ClientController::class, 'destroyCollaborator']);
        Route::apiResource('clients', ClientController::class);
        Route::apiResource('clients.needs', ClientNeedController::class)->only(['index', 'store']);
        Route::apiResource('client-needs', ClientNeedController::class)->only(['show', 'update', 'destroy']);
        Route::apiResource('client-types', ClientTypeController::class)->except(['index']);
        Route::apiResource('client-need-types', ClientNeedTypeController::class)->except(['index']);
        Route::apiResource('client-need-statuses', ClientNeedStatusController::class)->except(['index']);

        // Показы
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::put('/bookings/{id}', [BookingController::class, 'update']);
        Route::patch('/bookings/{id}', [BookingController::class, 'update']);
        Route::get('/bookings/agents-report', [BookingController::class, 'agentsReport']);

        // Отчёты
        // --- Агентские отчёты (дополнительно) ---
        Route::middleware('rop.branch.scope')->group(function () {
            Route::get('/reports/agent/contracts', [PropertyReportController::class, 'agentContractsStats']);
            Route::get('/reports/agent/clients', [PropertyReportController::class, 'agentClientsStats']);
            Route::get('/reports/agent/shows', [PropertyReportController::class, 'agentShowsStats']);
            Route::get('/reports/agent/earnings', [PropertyReportController::class, 'agentEarningsReport']);
            Route::get('/reports/properties/summary', [PropertyReportController::class, 'summary']);
            Route::get('/reports/properties/manager-efficiency', [PropertyReportController::class, 'managerEfficiency']);
            Route::get('/reports/properties/by-status', [PropertyReportController::class, 'byStatus']);
            Route::get('/reports/properties/by-type', [PropertyReportController::class, 'byType']);
            Route::get('/reports/properties/by-location', [PropertyReportController::class, 'byLocation']);
            Route::get('/reports/properties/monthly-comparison', [PropertyReportController::class, 'monthlyComparison']);
            Route::get('/reports/properties/monthly-comparison-range', [PropertyReportController::class, 'monthlyComparisonRange']);
            Route::get('/reports/properties/time-series', [PropertyReportController::class, 'timeSeries']);
            Route::get('/reports/properties/price-buckets', [PropertyReportController::class, 'priceBuckets']);
            Route::get('/reports/properties/rooms-hist', [PropertyReportController::class, 'roomsHistogram']);
            Route::get('/reports/properties/agents-leaderboard', [PropertyReportController::class, 'agentsLeaderboard']);
            Route::get('/reports/properties/conversion', [PropertyReportController::class, 'conversionFunnel']);
            Route::get('/reports/missing-phone/agents-by-status', [PropertyReportController::class, 'missingPhoneAgentsByStatus']);
            Route::get('/reports/missing-phone/list', [PropertyReportController::class, 'missingPhoneList']);
            // детализированный (по одному агенту) — уже есть
            Route::get('/reports/agents/{agent}/properties', [PropertyReportController::class, 'agentPropertiesReport']);
        });

        Route::post(
            '/properties/{property}/deal',
            [PropertyController::class, 'saveDeal']
        );

        // агрегированный — новый (без параметра agent)
        Route::get('/reports/agents/properties', [PropertyReportController::class, 'agentPropertiesReport'])->middleware('rop.branch.scope');

        // Новостройки (админ) + полностью ВЛОЖЕННЫЕ blocks/units c CRUD
        Route::apiResource('new-buildings', NewBuildingController::class)->except(['index', 'show']);

        Route::scopeBindings()->group(function () {
            Route::apiResource('new-buildings.blocks', NewBuildingBlockController::class)->except(['index', 'show']);
            Route::apiResource('new-buildings.units', DeveloperUnitController::class)->except(['index', 'show']);

            // ФОТО новостройки — полностью вложенные
            Route::post('new-buildings/{new_building}/photos', [NewBuildingPhotoController::class, 'store']);
            Route::delete('new-buildings/{new_building}/photos/{photo}', [NewBuildingPhotoController::class, 'destroy']);
            Route::post('new-buildings/{new_building}/photos/{photo}/cover', [NewBuildingPhotoController::class, 'setCover']);
            Route::put('new-buildings/{new_building}/photos/reorder', [NewBuildingPhotoController::class, 'reorder']);

            // ФОТО юнита — полностью вложенные
            Route::get('new-buildings/{new_building}/units/{unit}/photos', [DeveloperUnitPhotoController::class, 'index']);
            Route::post('new-buildings/{new_building}/units/{unit}/photos', [DeveloperUnitPhotoController::class, 'store']);
            Route::delete('new-buildings/{new_building}/units/{unit}/photos/{photo}', [DeveloperUnitPhotoController::class, 'destroy']);
            Route::put('new-buildings/{new_building}/units/{unit}/photos/reorder', [DeveloperUnitPhotoController::class, 'reorder']);
            Route::post('new-buildings/{new_building}/units/{unit}/photos/{photo}/cover', [DeveloperUnitPhotoController::class, 'setCover']);

            // Фичи (как было)
            Route::post('new-buildings/{new_building}/features/{feature}', [NewBuildingController::class, 'attachFeature']);
            Route::delete('new-buildings/{new_building}/features/{feature}', [NewBuildingController::class, 'detachFeature']);
        });

        // Bitrix selections (auth зона для админки/лички)
        Route::get('/selections', [SelectionController::class, 'index']);
        Route::post('/selections', [SelectionController::class, 'store']);
        Route::get('/selections/{id}', [SelectionController::class, 'show']);
    });
});

// --- CHAT (вне sanctum для бота/вебхуков) ---
Route::middleware(['api'])->group(function () {
    Route::post('/chat', [ChatController::class, 'handle']);
    Route::post('/chat/feedback', [ChatController::class, 'feedback'])->middleware(['auth:sanctum', 'active.user']);
    Route::post('/telegram/webhook', [TelegramAuthController::class, 'webhook']);
});

// --- Публичная подборка по hash ---
Route::get('/selections/public/{hash}', [SelectionController::class, 'publicShow']);

// --- Bitrix24 ---
Route::post('/b24/token', [B24AuthController::class, 'issue']);
Route::middleware('b24.jwt')->group(function () {
    Route::post('/selections', [SelectionController::class, 'store']);
    Route::post('/showings', [BookingController::class, 'store']);
    Route::post('/selections/{id}/events', [SelectionController::class, 'event']);
});
