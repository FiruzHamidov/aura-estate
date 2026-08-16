<?php

use App\Http\Controllers\ZktecoAttendanceController;
use App\Http\Middleware\LogApiRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('iclock')
    ->middleware('throttle:attendance-device')
    ->withoutMiddleware(LogApiRequest::class)
    ->group(function () {
        Route::match(['get', 'post'], '/cdata', [ZktecoAttendanceController::class, 'cdata']);
        Route::get('/getrequest', [ZktecoAttendanceController::class, 'getRequest']);
        Route::post('/devicecmd', [ZktecoAttendanceController::class, 'deviceCommand']);
        Route::match(['get', 'post'], '/registry', [ZktecoAttendanceController::class, 'registry']);
        Route::match(['get', 'post'], '/ping', [ZktecoAttendanceController::class, 'ping']);
    });
