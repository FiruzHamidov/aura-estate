<?php

namespace App\Http\Middleware;

use App\Services\DailyReportService;
use Closure;
use Illuminate\Http\Request;

class EnsureDailyReportSubmitted
{
    public function __construct(private readonly DailyReportService $dailyReports)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    private function isAllowedWhileBlocked(Request $request): bool
    {
        $path = trim($request->path(), '/');

        if ($path === 'api/logout' && $request->isMethod('post')) {
            return true;
        }

        if ($path === 'api/user/profile' && $request->isMethod('get')) {
            return true;
        }

        if ($path === 'api/daily-reports/status') {
            return true;
        }

        if ($path === 'api/daily-reports' && $request->isMethod('post')) {
            return true;
        }

        if (str_starts_with($path, 'api/daily-reports/my')) {
            return true;
        }

        if ($path === 'api/me/reminders/daily-report') {
            return true;
        }

        if ($path === 'api/kpi/daily/my-progress' && $request->isMethod('get')) {
            return true;
        }

        if ($path === 'api/kpi/daily/my-report' && in_array($request->method(), ['GET', 'POST'], true)) {
            return true;
        }

        if ($path === 'api/kpi/daily/report' && in_array($request->method(), ['GET', 'PATCH'], true)) {
            return true;
        }

        return preg_match('#^api/daily-reports/\d+$#', $path)
            && in_array($request->method(), ['PUT', 'PATCH'], true);
    }
}
