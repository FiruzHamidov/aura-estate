<?php

use App\Http\Controllers\LocationController;
use App\Http\Controllers\PropertyStatusController;
use App\Http\Controllers\PropertyTypeController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PropertyController;

// Тестовый маршрут на проверку API
Route::get('/ping', function () {
    return response()->json(['message' => 'API works']);
});

// Авторизация
Route::post('/login', [AuthController::class, 'login']);
Route::post('/sms/request', [AuthController::class, 'requestSmsCode']);
Route::post('/sms/verify', [AuthController::class, 'verifySmsCode']);
Route::get('/properties', [PropertyController::class, 'index']);

// Группируем все защищённые маршруты (требующие авторизацию)
Route::middleware('auth:sanctum')->group(function () {

    // Пользователь (например, получение профиля)
    Route::get('/user/profile', [UserController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Недвижимость (Property)
    Route::post('/properties', [PropertyController::class, 'store']);
    Route::get('/properties/{property}', [PropertyController::class, 'show']);
    Route::put('/properties/{property}', [PropertyController::class, 'update']);
    Route::delete('/properties/{property}', [PropertyController::class, 'destroy']);

});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::apiResource('property-types', PropertyTypeController::class);
    Route::apiResource('property-statuses', PropertyStatusController::class);
    Route::apiResource('locations', LocationController::class);
});
