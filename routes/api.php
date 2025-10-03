<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BuildingTypeController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConstructionStageController;
use App\Http\Controllers\ContractTypeController;
use App\Http\Controllers\DeveloperController;
use App\Http\Controllers\DeveloperUnitController;
use App\Http\Controllers\DeveloperUnitPhotoController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\FeatureController;
use App\Http\Controllers\HeatingTypeController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\NewBuildingBlockController;
use App\Http\Controllers\NewBuildingController;
use App\Http\Controllers\NewBuildingPhotoController;
use App\Http\Controllers\ParkingTypeController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\PropertyPhotoController;
use App\Http\Controllers\PropertyReportController;
use App\Http\Controllers\PropertyStatusController;
use App\Http\Controllers\PropertyTypeController;
use App\Http\Controllers\RepairTypeController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;


// Тестовый маршрут на проверку API
Route::get('/ping', function () {
    return response()->json(['message' => 'API works']);
});

// --- ПУБЛИЧНЫЕ МАРШРУТЫ ---

// Авторизация
Route::post('/login', [AuthController::class, 'login']);
Route::post('/sms/request', [AuthController::class, 'requestSmsCode']);
Route::post('/sms/verify', [AuthController::class, 'verifySmsCode']);

// Получение списка объектов (публичный просмотр)
Route::get('/properties', [PropertyController::class, 'index']);
Route::get('/properties/map', [PropertyController::class, 'map']);

// Публичные GET-запросы на справочники (чтобы фронт мог их спокойно получить)
Route::get('/property-types', [PropertyTypeController::class, 'index']);
Route::get('/property-statuses', [PropertyStatusController::class, 'index']);
Route::get('/locations', [LocationController::class, 'index']);
Route::get('/building-types', [BuildingTypeController::class, 'index']);
Route::get('/parking-types', [ParkingTypeController::class, 'index']);
Route::get('/heating-types', [HeatingTypeController::class, 'index']);
Route::get('/repair-types', [RepairTypeController::class, 'index']);
Route::get('/contract-types', [ContractTypeController::class, 'index']);
Route::get('/properties/{property}', [PropertyController::class, 'show']);
Route::get('/user/agents', [UserController::class, 'agents']);
Route::get('/user/{user}', [UserController::class, 'show']);

// Новостройки и связанные роуты

Route::apiResource('developers', DeveloperController::class)->only(['index', 'show']);
Route::apiResource('construction-stages', ConstructionStageController::class)->only(['index', 'show']);
Route::apiResource('materials', MaterialController::class)->only(['index', 'show']);
Route::apiResource('features', FeatureController::class)->only(['index', 'show']);

Route::apiResource('new-buildings', NewBuildingController::class)->only(['index', 'show']);
Route::apiResource('new-buildings.blocks', NewBuildingBlockController::class)->only(['index', 'show'])->shallow();
Route::apiResource('new-buildings.units', DeveloperUnitController::class)->only(['index', 'show'])->shallow();

// Фотки — index доступен публично
Route::apiResource('new-buildings.photos', NewBuildingPhotoController::class)->only(['index'])->shallow();
Route::apiResource('units.photos', DeveloperUnitPhotoController::class)->only(['index'])->shallow();

Route::post('/properties/{property}/view', [PropertyController::class, 'trackView'])
    ->middleware('throttle:30,1'); // троттлинг от ботов

Route::get('/chat/history', [ChatController::class, 'history']);

// --- ЗАЩИЩЕННЫЕ МАРШРУТЫ ---

Route::middleware('auth:sanctum')->group(callback: function () {

    Route::get('/my-properties', [PropertyController::class, 'index']);

    // Профиль пользователя
    Route::get('/user/profile', [UserController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Полный CRUD для объектов недвижимости
    Route::post('/properties', [PropertyController::class, 'store']);
    Route::put('/properties/{property}', [PropertyController::class, 'update']);
    Route::delete('/properties/{property}', [PropertyController::class, 'destroy']);
    Route::patch('/properties/{property}/moderation-listing', [PropertyController::class, 'updateModerationAndListingType']);

    Route::post('properties/{property}/photos', [PropertyPhotoController::class, 'store']);
    Route::delete('properties/{property}/photos/{photo}', [PropertyPhotoController::class, 'destroy'])
        ->whereNumber('photo');
    Route::put('properties/{property}/photos/reorder', [PropertyPhotoController::class, 'reorder']);

    // Полный CRUD для справочников (если тебе нужно управление ими в админке)
    Route::apiResource('property-types', PropertyTypeController::class)->except(['index']);
    Route::apiResource('property-statuses', PropertyStatusController::class)->except(['index']);
    Route::apiResource('locations', LocationController::class)->except(['index']);
    Route::apiResource('building-types', BuildingTypeController::class)->except(['index']);
    Route::apiResource('parking-types', ParkingTypeController::class)->except(['index']);
    Route::apiResource('heating-types', HeatingTypeController::class)->except(['index']);
    Route::apiResource('contract-types', ContractTypeController::class)->except(['index']);
    Route::apiResource('repair-types', RepairTypeController::class)->except(['index']);
    Route::apiResource('roles', RoleController::class);
    Route::post('/user/{user}/photo', [UserController::class, 'updatePhoto']);
    Route::delete('/user/photo', [UserController::class, 'deleteMyPhoto']);
    Route::post('/user/update-password', [UserController::class, 'updatePassword']);
    Route::apiResource('user', UserController::class)->except(['show']);

    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/favorites', [FavoriteController::class, 'store']);
    Route::delete('/favorites/{property_id}', [FavoriteController::class, 'destroy']);

    Route::get('/bookings', [BookingController::class, 'index']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::get('/bookings/{id}', [BookingController::class, 'show']);


    // reports
    Route::get('/reports/properties/summary', [PropertyReportController::class, 'summary']);
    Route::get('/reports/properties/manager-efficiency', [PropertyReportController::class, 'managerEfficiency']);
    Route::get('/reports/properties/by-status', [PropertyReportController::class, 'byStatus']);
    Route::get('/reports/properties/by-type', [PropertyReportController::class, 'byType']);
    Route::get('/reports/properties/by-location', [PropertyReportController::class, 'byLocation']);
    Route::get('/reports/properties/time-series', [PropertyReportController::class, 'timeSeries']);
    Route::get('/reports/properties/price-buckets', [PropertyReportController::class, 'priceBuckets']);
    Route::get('/reports/properties/rooms-hist', [PropertyReportController::class, 'roomsHistogram']);
    Route::get('/reports/properties/agents-leaderboard', [PropertyReportController::class, 'agentsLeaderboard']);
    Route::get('/reports/properties/conversion', [PropertyReportController::class, 'conversionFunnel']);

    // Новостройки для админа

    Route::apiResource('developers', DeveloperController::class)->except(['index', 'show']);
    Route::apiResource('construction-stages', ConstructionStageController::class)->except(['index', 'show']);
    Route::apiResource('materials', MaterialController::class)->except(['index', 'show']);
    Route::apiResource('features', FeatureController::class)->except(['index', 'show']);

    Route::apiResource('new-buildings', NewBuildingController::class)->except(['index', 'show']);
    Route::apiResource('new-buildings.blocks', NewBuildingBlockController::class)->except(['index', 'show'])->shallow();
    Route::apiResource('new-buildings.units', DeveloperUnitController::class)->except(['index', 'show'])->shallow();


    Route::post('/new-buildings/{new_building}/photos', [NewBuildingPhotoController::class, 'store']);
    Route::delete('/new-building-photos/{photo}', [NewBuildingPhotoController::class, 'destroy']);
    Route::post('/new-building-photos/{photo}/cover', [NewBuildingPhotoController::class, 'setCover']);
    Route::post('/new-buildings/{new_building}/photos/reorder', [NewBuildingPhotoController::class, 'reorder']);

    Route::apiResource('units.photos', DeveloperUnitPhotoController::class)->only(['store', 'destroy'])->shallow();

    // attach/detach фич
    Route::post('new-buildings/{new_building}/features/{feature}', [NewBuildingController::class, 'attachFeature']);
    Route::delete('new-buildings/{new_building}/features/{feature}', [NewBuildingController::class, 'detachFeature']);
});

Route::middleware(['api'])->group(function () {
    Route::post('/chat', [ChatController::class, 'handle']);       // при желании ->middleware('auth:sanctum')
    Route::post('/chat/feedback', [ChatController::class, 'feedback'])->middleware('auth:sanctum');
});
