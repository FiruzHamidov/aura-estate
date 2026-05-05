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
        if ($this->isAllowedWhileBlocked($request)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $missingReportDate = $this->dailyReports->missingReportDate($user);

        if ($missingReportDate !== null) {
            return response()->json([
                'message' => 'Сдайте ежедневный отчет',
                'code' => 'daily_report_required',
                'missing_report_date' => $missingReportDate,
                'daily_report_required' => true,
                'blocked_until_report_submitted' => true,
            ], 403);
        }

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

        return preg_match('#^api/daily-reports/\d+$#', $path)
            && in_array($request->method(), ['PUT', 'PATCH'], true);
    }
}
