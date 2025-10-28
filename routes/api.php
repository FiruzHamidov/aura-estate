<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{
    AuthController, B24AuthController, BookingController, BuildingTypeController, ChatController,
    ConstructionStageController, ContractTypeController, DeveloperController, DeveloperUnitController,
    DeveloperUnitPhotoController, FavoriteController, FeatureController, HeatingTypeController,
    LocationController, MaterialController, NewBuildingBlockController, NewBuildingController,
    NewBuildingPhotoController, ParkingTypeController, PropertyController, PropertyPhotoController,
    PropertyReportController, PropertyStatusController, PropertyTypeController, RepairTypeController,
    RoleController, SelectionController, UserController
};

// --- ПИНГ ---
Route::get('/ping', fn() => response()->json(['message' => 'API works']));

// --- ПУБЛИЧНЫЕ ---
Route::post('/login', [AuthController::class, 'login']);
Route::post('/sms/request', [AuthController::class, 'requestSmsCode']);
Route::post('/sms/verify',  [AuthController::class, 'verifySmsCode']);

Route::get('/properties',      [PropertyController::class, 'index']);
Route::get('/properties/map',  [PropertyController::class, 'map']);
Route::get('/properties/{property}', [PropertyController::class, 'show']);
Route::post('/properties/{property}/view', [PropertyController::class, 'trackView'])->middleware('throttle:30,1');

Route::get('/property-types',    [PropertyTypeController::class, 'index']);
Route::get('/property-statuses', [PropertyStatusController::class, 'index']);
Route::get('/locations',         [LocationController::class, 'index']);
Route::get('/building-types',    [BuildingTypeController::class, 'index']);
Route::get('/parking-types',     [ParkingTypeController::class, 'index']);
Route::get('/heating-types',     [HeatingTypeController::class, 'index']);
Route::get('/repair-types',      [RepairTypeController::class, 'index']);
Route::get('/contract-types',    [ContractTypeController::class, 'index']);

Route::get('/user/agents', [UserController::class, 'agents']);
Route::get('/user/{user}', [UserController::class, 'show']);

// --- Новостройки (public index/show + ВЛОЖЕННЫЕ index/show) ---
Route::scopeBindings()->group(function () {
    Route::apiResource('developers', DeveloperController::class)->only(['index', 'show']);
    Route::apiResource('construction-stages', ConstructionStageController::class)->only(['index', 'show']);
    Route::apiResource('materials',  MaterialController::class)->only(['index', 'show']);
    Route::apiResource('features',   FeatureController::class)->only(['index', 'show']);

    Route::apiResource('new-buildings', NewBuildingController::class)->only(['index', 'show']);

    // blocks (полностью вложенные; публично index/show)
    Route::apiResource('new-buildings.blocks', NewBuildingBlockController::class)->only(['index','show']);

    // units (полностью вложенные; публично index/show)
    Route::apiResource('new-buildings.units',  DeveloperUnitController::class)->only(['index','show']);

    // ФОТО новостройки (полностью вложенные; публично index)
    Route::get('new-buildings/{new_building}/photos', [NewBuildingPhotoController::class, 'index']);
});

// история чата (публично)
Route::get('/chat/history', [ChatController::class, 'history']);

// --- ЗАЩИЩЁННЫЕ ---
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/my-properties', [PropertyController::class, 'index']);
    Route::get('/user/profile',  [UserController::class, 'profile']);
    Route::post('/logout',       [AuthController::class, 'logout']);

    // Properties CRUD + photos
    Route::post('/properties',                [PropertyController::class, 'store']);
    Route::put('/properties/{property}',      [PropertyController::class, 'update']);
    Route::delete('/properties/{property}',   [PropertyController::class, 'destroy']);
    Route::patch('/properties/{property}/moderation-listing', [PropertyController::class, 'updateModerationAndListingType']);
    Route::post('properties/{property}/photos',               [PropertyPhotoController::class, 'store']);
    Route::put('properties/{property}/photos/reorder',        [PropertyPhotoController::class, 'reorder']);
    Route::delete('properties/{property}/photos/{photo}',     [PropertyPhotoController::class, 'destroy'])->whereNumber('photo');

    // Справочники (админ)
    Route::apiResource('property-types',    PropertyTypeController::class)->except(['index']);
    Route::apiResource('property-statuses', PropertyStatusController::class)->except(['index']);
    Route::apiResource('locations',         LocationController::class)->except(['index']);
    Route::apiResource('building-types',    BuildingTypeController::class)->except(['index']);
    Route::apiResource('parking-types',     ParkingTypeController::class)->except(['index']);
    Route::apiResource('heating-types',     HeatingTypeController::class)->except(['index']);
    Route::apiResource('contract-types',    ContractTypeController::class)->except(['index']);
    Route::apiResource('repair-types',      RepairTypeController::class)->except(['index']);
    Route::apiResource('roles',             RoleController::class);
    Route::post('/user/{user}/photo',       [UserController::class, 'updatePhoto']);
    Route::delete('/user/photo',            [UserController::class, 'deleteMyPhoto']);
    Route::post('/user/update-password',    [UserController::class, 'updatePassword']);
    Route::apiResource('user',              UserController::class)->except(['show']);

    // Избранное
    Route::get('/favorites',  [FavoriteController::class, 'index']);
    Route::post('/favorites', [FavoriteController::class, 'store']);
    Route::delete('/favorites/{property_id}', [FavoriteController::class, 'destroy']);

    // Показы
    Route::get('/bookings',      [BookingController::class, 'index']);
    Route::post('/bookings',     [BookingController::class, 'store']);
    Route::get('/bookings/agents-report', [BookingController::class, 'agentsReport']);
    Route::get('/bookings/{id}', [BookingController::class, 'show'])->whereNumber('id');

    // Отчёты
    Route::get('/reports/properties/summary',            [PropertyReportController::class, 'summary']);
    Route::get('/reports/properties/manager-efficiency', [PropertyReportController::class, 'managerEfficiency']);
    Route::get('/reports/properties/by-status',          [PropertyReportController::class, 'byStatus']);
    Route::get('/reports/properties/by-type',            [PropertyReportController::class, 'byType']);
    Route::get('/reports/properties/by-location',        [PropertyReportController::class, 'byLocation']);
    Route::get('/reports/properties/time-series',        [PropertyReportController::class, 'timeSeries']);
    Route::get('/reports/properties/price-buckets',      [PropertyReportController::class, 'priceBuckets']);
    Route::get('/reports/properties/rooms-hist',         [PropertyReportController::class, 'roomsHistogram']);
    Route::get('/reports/properties/agents-leaderboard', [PropertyReportController::class, 'agentsLeaderboard']);
    Route::get('/reports/properties/conversion',         [PropertyReportController::class, 'conversionFunnel']);
    Route::get('/reports/missing-phone/agents-by-status',[PropertyReportController::class, 'missingPhoneAgentsByStatus']);
    Route::get('/reports/missing-phone/list',            [PropertyReportController::class, 'missingPhoneList']);
    // детализированный (по одному агенту) — уже есть
    Route::get('/reports/agents/{agent}/properties', [PropertyReportController::class, 'agentPropertiesReport']);

// агрегированный — новый (без параметра agent)
    Route::get('/reports/agents/properties', [PropertyReportController::class, 'agentPropertiesReport']);

    // Новостройки (админ) + полностью ВЛОЖЕННЫЕ blocks/units c CRUD
    Route::apiResource('new-buildings', NewBuildingController::class)->except(['index','show']);

    Route::scopeBindings()->group(function () {
        Route::apiResource('new-buildings.blocks', NewBuildingBlockController::class)->except(['index','show']);
        Route::apiResource('new-buildings.units',  DeveloperUnitController::class)->except(['index','show']);

        // ФОТО новостройки — полностью вложенные
        Route::post('new-buildings/{new_building}/photos',           [NewBuildingPhotoController::class, 'store']);
        Route::delete('new-buildings/{new_building}/photos/{photo}', [NewBuildingPhotoController::class, 'destroy']);
        Route::post('new-buildings/{new_building}/photos/{photo}/cover', [NewBuildingPhotoController::class, 'setCover']);
        Route::put('new-buildings/{new_building}/photos/reorder',    [NewBuildingPhotoController::class, 'reorder']);

        // ФОТО юнита — полностью вложенные
        Route::get('new-buildings/{new_building}/units/{unit}/photos',              [DeveloperUnitPhotoController::class, 'index']);
        Route::post('new-buildings/{new_building}/units/{unit}/photos',             [DeveloperUnitPhotoController::class, 'store']);
        Route::delete('new-buildings/{new_building}/units/{unit}/photos/{photo}',   [DeveloperUnitPhotoController::class, 'destroy']);
        Route::put('new-buildings/{new_building}/units/{unit}/photos/reorder',      [DeveloperUnitPhotoController::class, 'reorder']);
        Route::post('new-buildings/{new_building}/units/{unit}/photos/{photo}/cover',[DeveloperUnitPhotoController::class, 'setCover']);

        // Фичи (как было)
        Route::post('new-buildings/{new_building}/features/{feature}',   [NewBuildingController::class, 'attachFeature']);
        Route::delete('new-buildings/{new_building}/features/{feature}', [NewBuildingController::class, 'detachFeature']);
    });

    // Bitrix selections (auth зона для админки/лички)
    Route::get('/selections',      [SelectionController::class, 'index']);
    Route::post('/selections',     [SelectionController::class, 'store']);
    Route::get('/selections/{id}', [SelectionController::class, 'show']);
});

// --- CHAT (вне sanctum для бота/вебхуков) ---
Route::middleware(['api'])->group(function () {
    Route::post('/chat', [ChatController::class, 'handle']);
    Route::post('/chat/feedback', [ChatController::class, 'feedback'])->middleware('auth:sanctum');
});

// --- Публичная подборка по hash ---
Route::get('/selections/public/{hash}', [SelectionController::class, 'publicShow']);

// --- Bitrix24 ---
Route::post('/b24/token', [B24AuthController::class,'issue']);
Route::middleware('b24.jwt')->group(function(){
    Route::post('/selections',            [SelectionController::class,'store']);
    Route::post('/showings',              [BookingController::class,'store']);
});
    Route::post('/selections/{id}/events',[SelectionController::class,'event']);
