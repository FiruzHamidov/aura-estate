<?php

use App\Http\Middleware\DetectClientLocale;
use App\Http\Middleware\EnforceRopBranchScope;
use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Http\Middleware\EnsureGuestSupportRequest;
use App\Http\Middleware\EnsureTraceId;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsNotClient;
use App\Http\Middleware\LogApiRequest;
use App\Http\Middleware\EnsurePropertyModerationIdempotency;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')->group(base_path('routes/attendance_device.php'));
        },
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'middleware' => ['api', 'auth:sanctum', 'active.user'],
        'prefix' => 'api',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->group('api', [
            HandleCors::class,
            EnsureTraceId::class,
            LogApiRequest::class,
            DetectClientLocale::class,
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            'active.user' => EnsureUserIsActive::class,
            'daily.report' => EnsureDailyReportSubmitted::class,
            'guest.support.request' => EnsureGuestSupportRequest::class,
            'non.client' => EnsureUserIsNotClient::class,
            'rop.branch.scope' => EnforceRopBranchScope::class,
            'kpi.performance' => \App\Http\Middleware\LogKpiPerformance::class,
            'moderation.idempotent' => EnsurePropertyModerationIdempotency::class,
            'moderation.payload' => \App\Http\Middleware\EnsurePropertyModerationPayload::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request): ?string {
            return $request->is('api/*') ? null : null;
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->report(function (\Throwable $exception) {
            if (! app()->bound('request') || ! \App\Support\ResidentialDiagnostics::applies(request())) {
                return null;
            }
            // Database exceptions can include SQL bindings (contacts, review
            // text). Do not pass the throwable/message/stack to a log handler.
            \Illuminate\Support\Facades\Log::error('Residential request failed.', [
                'exception_type' => $exception::class,
                'route' => request()->route()?->uri() ?? 'residential-or-lead',
                'trace_id' => request()->attributes->get('trace_id'),
            ]);

            return false;
        });

        $exceptions->render(function (ValidationException $e, $request) {
            $isKpi = str_starts_with((string) $request->path(), 'api/kpi')
                || str_starts_with((string) $request->path(), 'api/daily-reports');
            $errors = $e->errors();
            $validationCode = $errors['code'][0] ?? null;
            $domainCode = is_string($validationCode) && preg_match('/^[A-Z][A-Z0-9_]+$/', $validationCode) === 1
                ? $validationCode
                : null;

            return response()->json([
                'code' => $domainCode ?? ($isKpi ? 'KPI_VALIDATION_FAILED' : 'VALIDATION_ERROR'),
                'message' => 'Validation failed.',
                'details' => ['errors' => $errors],
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

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('iclock/*')) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            if ($status < 400) {
                $status = 500;
            }
            if ($status === 500) {
                report($e);
            }
            $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

            return response(
                $status === 500 ? 'ERROR: SERVER' : 'ERROR: REQUEST FAILED',
                $status,
                [...$headers, 'Content-Type' => 'text/plain; charset=UTF-8']
            );
        });

        $exceptions->render(function (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            // Preserve intentional JSON responses (e.g. RBAC 403) instead of converting them to 500.
            return $e->getResponse();
        });

        $exceptions->render(function (\Throwable $e, $request) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            if ($status < 400) {
                $status = 500;
            }

            if ($status === 500) {
                report($e);
            }

            $message = $status === 500 ? 'Server Error.' : ($e->getMessage() ?: 'Request failed.');
            $domainCode = preg_match('/^[A-Z][A-Z0-9_]+$/', $message) === 1 ? $message : null;

            return response()->json([
                'code' => $domainCode ?? match ($status) {
                    401 => 'UNAUTHENTICATED',
                    403 => 'FORBIDDEN',
                    404 => 'NOT_FOUND',
                    409 => 'CONFLICT',
                    422 => 'VALIDATION_ERROR',
                    500 => 'INTERNAL_ERROR',
                    default => 'REQUEST_FAILED',
                },
                'message' => $message,
                'details' => (object) [],
                'trace_id' => $request->attributes->get('trace_id'),
            ], $status);
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('notifications:dispatch-reminders')->everyFiveMinutes();
        $schedule->command('stories:expire')->everyFiveMinutes();
        $schedule->command('audit:prune-api-request-logs')->dailyAt('03:30');
        $schedule->command('locations:prune-history')->dailyAt('04:00');
        $schedule->command('attendance:summarize')->dailyAt('00:05');
        $schedule->command('attendance:reprocess-pending')->everyMinute()->withoutOverlapping();
        $schedule->command('attendance:monitor-devices')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('attendance:prune-raw')->dailyAt('04:15');
        $schedule->command('properties:refresh-liquidity-market')->dailyAt('02:00')->withoutOverlapping();
        $schedule->command('properties:recalculate-liquidity')->dailyAt('02:30')->withoutOverlapping();
        $schedule->command('properties:expire-promotions')->everyFiveMinutes()->withoutOverlapping();
    })
    ->create();
