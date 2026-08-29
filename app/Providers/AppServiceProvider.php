<?php

namespace App\Providers;

use App\Contracts\AttendanceDeviceProtocol;
use App\Models\Property;
use App\Models\User;
use App\Observers\PropertyObserver;
use App\Services\Attendance\ZktecoTaPushProtocol;
use App\Services\Messaging\GuestSupportSessionService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AttendanceDeviceProtocol::class, ZktecoTaPushProtocol::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Property::observe(PropertyObserver::class);

        Relation::morphMap([
            'user' => User::class,
        ]);

        RateLimiter::for('attendance-device', function (Request $request) {
            $serial = (string) ($request->query('SN') ?? $request->query('sn') ?? 'unknown');

            return Limit::perMinute((int) config('attendance.device_rate_limit_per_minute', 240))
                ->by($serial.'|'.($request->ip() ?? 'unknown'));
        });

        RateLimiter::for('guest-support-create', function (Request $request) {
            return Limit::perMinute((int) config('guest-support.create_rate_per_minute', 5))
                ->by('guest-support-create|'.($request->ip() ?? 'unknown'));
        });

        RateLimiter::for('guest-support-read', function (Request $request) {
            $key = app(GuestSupportSessionService::class)->rateLimitKey($request);

            return Limit::perMinute((int) config('guest-support.read_rate_per_minute', 60))
                ->by('guest-support-read|'.$key);
        });

        RateLimiter::for('guest-support-message', function (Request $request) {
            $key = app(GuestSupportSessionService::class)->rateLimitKey($request);

            return Limit::perMinute((int) config('guest-support.message_rate_per_minute', 15))
                ->by('guest-support-message|'.$key);
        });
    }
}
