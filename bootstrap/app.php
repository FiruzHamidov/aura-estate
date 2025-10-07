<?php

use App\Http\Middleware\B24Jwt;
use App\Http\Middleware\DetectClientLocale;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Middleware\HandleCors;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Enable CORS globally for API routes (configured via config/cors.php)
        $middleware->group('api', [
            HandleCors::class,
            DetectClientLocale::class,
        ]);

        $middleware->alias([
            'b24.jwt' => B24Jwt::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e, $request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('b24:sync:properties')->everyTenMinutes();
    })
    ->create();
