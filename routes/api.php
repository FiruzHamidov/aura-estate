<?php

use App\Http\Controllers\RepairTypeController;
use App\Models\RepairType;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\PropertyTypeController;
use App\Http\Controllers\PropertyStatusController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\BuildingTypeController;
use App\Http\Controllers\ParkingTypeController;
use App\Http\Controllers\HeatingTypeController;

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

// Публичные GET-запросы на справочники (чтобы фронт мог их спокойно получить)
Route::get('/property-types', [PropertyTypeController::class, 'index']);
Route::get('/property-statuses', [PropertyStatusController::class, 'index']);
Route::get('/locations', [LocationController::class, 'index']);
Route::get('/building-types', [BuildingTypeController::class, 'index']);
Route::get('/parking-types', [ParkingTypeController::class, 'index']);
Route::get('/heating-types', [HeatingTypeController::class, 'index']);
Route::get('/repair-types', [RepairTypeController::class, 'index']);
Route::get('/properties/{property}', [PropertyController::class, 'show']);


// --- ЗАЩИЩЕННЫЕ МАРШРУТЫ ---

Route::middleware('auth:sanctum')->group(function () {

    // Профиль пользователя
    Route::get('/user/profile', [UserController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Полный CRUD для объектов недвижимости
    Route::post('/properties', [PropertyController::class, 'store']);
    Route::put('/properties/{property}', [PropertyController::class, 'update']);
    Route::delete('/properties/{property}', [PropertyController::class, 'destroy']);

    // Полный CRUD для справочников (если тебе нужно управление ими в админке)
    Route::apiResource('property-types', PropertyTypeController::class)->except(['index']);
    Route::apiResource('property-statuses', PropertyStatusController::class)->except(['index']);
    Route::apiResource('locations', LocationController::class)->except(['index']);
    Route::apiResource('building-types', BuildingTypeController::class)->except(['index']);
    Route::apiResource('parking-types', ParkingTypeController::class)->except(['index']);
    Route::apiResource('heating-types', HeatingTypeController::class)->except(['index']);
    Route::apiResource('repair-types', RepairTypeController::class)->except(['index']);

});
