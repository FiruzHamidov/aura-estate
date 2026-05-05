<?php

use App\Http\Middleware\B24Jwt;
use App\Http\Middleware\DetectClientLocale;
use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Http\Middleware\EnsureTraceId;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsNotClient;
use App\Http\Middleware\EnforceRopBranchScope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->group('api', [
            HandleCors::class,
            EnsureTraceId::class,
            DetectClientLocale::class,
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            'b24.jwt' => B24Jwt::class,
            'active.user' => EnsureUserIsActive::class,
            'daily.report' => EnsureDailyReportSubmitted::class,
            'non.client' => EnsureUserIsNotClient::class,
            'rop.branch.scope' => EnforceRopBranchScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (ValidationException $e, $request) {
            $isKpi = str_starts_with((string) $request->path(), 'api/kpi')
                || str_starts_with((string) $request->path(), 'api/daily-reports');

            return response()->json([
                'code' => $isKpi ? 'KPI_VALIDATION_FAILED' : 'VALIDATION_ERROR',
                'message' => 'Validation failed.',
                'details' => ['errors' => $e->errors()],
                'trace_id' => $request->attributes->get('trace_id'),
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, $request) {
            return response()->json([
                'code' => 'UNAUTHENTICATED',
                'message' => 'Unauthenticated.',
                'details' => (object) [],
                'trace_id' => $request->attributes->get('trace_id'),
            ], 401);
        });

        $exceptions->render(function (\Throwable $e, $request) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            if ($status < 400) {
                $status = 500;
            }

            if ($status === 500) {
                report($e);
            }

            return response()->json([
                'code' => $status === 500 ? 'INTERNAL_ERROR' : 'REQUEST_FAILED',
                'message' => $status === 500 ? 'Server Error.' : ($e->getMessage() ?: 'Request failed.'),
                'details' => (object) [],
                'trace_id' => $request->attributes->get('trace_id'),
            ], $status);
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('b24:sync:properties')->everyTenMinutes();
        $schedule->command('notifications:dispatch-reminders')->everyFiveMinutes();
        $schedule->command('stories:expire')->everyFiveMinutes();
    })
    ->create();
