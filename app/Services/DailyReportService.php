<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DailyReportService
{
    public const REQUIRED_ROLE_SLUGS = ['agent', 'rop', 'mop', 'intern'];

    public function statusForUser(User $user): array
    {
        $user->loadMissing('role');

        $roleSlug = $user->role?->slug;
        $required = in_array($roleSlug, self::REQUIRED_ROLE_SLUGS, true);
        $missingReportDate = $this->missingReportDate($user);

        return [
            'daily_report_required' => $required && $missingReportDate !== null,
            'blocked_until_report_submitted' => $required && $missingReportDate !== null,
            'missing_report_date' => $missingReportDate,
            'role_slug' => $roleSlug,
        ];
    }

    public function missingReportDate(User $user): ?string
    {
        $user->loadMissing('role');

        if (! in_array($user->role?->slug, self::REQUIRED_ROLE_SLUGS, true)) {
            return null;
        }

        if (! Schema::hasTable('daily_reports')) {
            return null;
        }

        $now = Carbon::now($this->timezone());

        if ($now->lt($now->copy()->setTime(11, 0))) {
            return null;
        }

        $reportDate = $now->copy()->subDay()->toDateString();

        $submitted = DailyReport::query()
            ->where('user_id', $user->id)
            ->whereDate('report_date', $reportDate)
            ->whereNotNull('submitted_at')
            ->exists();

        return $submitted ? null : $reportDate;
    }

    public function defaultReportDate(User $user): string
    {
        return $this->missingReportDate($user) ?? Carbon::now($this->timezone())->toDateString();
    }

    public function autoMetrics(User $user, string $reportDate): array
    {
        [$startUtc, $endUtc] = $this->utcDayBounds($reportDate);

        $showsCount = $this->countBookings($user, $startUtc, $endUtc);

        return [
            'calls_count' => $this->countCalls($user, $startUtc, $endUtc),
            'meetings_count' => $showsCount,
            'shows_count' => $showsCount,
            'new_clients_count' => $this->countNewClients($user, $startUtc, $endUtc),
            'new_properties_count' => $this->countNewProperties($user, $startUtc, $endUtc),
            'deals_count' => $this->countDeals($user, $startUtc, $endUtc),
        ];
    }

    public function reportStatusPayload(User $user, ?string $reportDate = null): array
    {
        $date = $reportDate ?: $this->defaultReportDate($user);
        $report = Schema::hasTable('daily_reports')
            ? DailyReport::query()
                ->where('user_id', $user->id)
                ->whereDate('report_date', $date)
                ->first()
            : null;

        return array_merge($this->statusForUser($user), [
            'report_date' => $date,
            'submitted' => $report?->submitted_at !== null,
            'submitted_at' => $report?->submitted_at,
            'auto' => $this->autoMetrics($user, $date),
            'manual' => [
                'ad_count' => $report?->ad_count ?? 0,
                'meetings_count' => $report?->meetings_count ?? 0,
                'comment' => $report?->comment ?? '',
                'plans_for_tomorrow' => $report?->plans_for_tomorrow ?? '',
            ],
            'report' => $report,
        ]);
    }

    private function countBookings(User $user, Carbon $startUtc, Carbon $endUtc): int
    {
        if (! Schema::hasTable('bookings')
            || ! Schema::hasColumn('bookings', 'agent_id')
            || ! Schema::hasColumn('bookings', 'start_time')) {
            return 0;
        }

        return (int) DB::table('bookings')
            ->where('agent_id', $user->id)
            ->whereBetween('start_time', [$startUtc->toDateTimeString(), $endUtc->toDateTimeString()])
            ->count();
    }

    private function countCalls(User $user, Carbon $startUtc, Carbon $endUtc): int
    {
        if (Schema::hasTable('crm_tasks')
            && Schema::hasTable('crm_task_types')
            && Schema::hasColumn('crm_tasks', 'assignee_id')
            && Schema::hasColumn('crm_tasks', 'status')
            && Schema::hasColumn('crm_tasks', 'completed_at')) {
            return (int) DB::table('crm_tasks')
                ->join('crm_task_types', 'crm_task_types.id', '=', 'crm_tasks.task_type_id')
                ->where('crm_tasks.assignee_id', $user->id)
                ->where('crm_tasks.status', 'done')
                ->where('crm_task_types.code', 'CALL')
                ->whereBetween('crm_tasks.completed_at', [$startUtc->toDateTimeString(), $endUtc->toDateTimeString()])
                ->count();
        }

        if (! Schema::hasTable('crm_audit_logs')
            || ! Schema::hasColumn('crm_audit_logs', 'actor_id')
            || ! Schema::hasColumn('crm_audit_logs', 'event')
            || ! Schema::hasColumn('crm_audit_logs', 'created_at')) {
            return 0;
        }

        return (int) DB::table('crm_audit_logs')
            ->where('actor_id', $user->id)
            ->where('event', 'call')
            ->whereBetween('created_at', [$startUtc->toDateTimeString(), $endUtc->toDateTimeString()])
            ->count();
    }

    private function countNewClients(User $user, Carbon $startUtc, Carbon $endUtc): int
    {
        if (! Schema::hasTable('clients') || ! Schema::hasColumn('clients', 'created_at')) {
            return 0;
        }

        $query = DB::table('clients')
            ->where(function ($query) use ($user) {
                if (Schema::hasColumn('clients', 'created_by')) {
                    $query->where('created_by', $user->id);
                }

                if (Schema::hasColumn('clients', 'responsible_agent_id')) {
                    $query->orWhere('responsible_agent_id', $user->id);
                }
            });

        if (! Schema::hasColumn('clients', 'created_by')
            && ! Schema::hasColumn('clients', 'responsible_agent_id')) {
            return 0;
        }

        return (int) $query
            ->whereBetween('created_at', [$startUtc->toDateTimeString(), $endUtc->toDateTimeString()])
            ->count();
    }

    private function countNewProperties(User $user, Carbon $startUtc, Carbon $endUtc): int
    {
        if (! Schema::hasTable('properties') || ! Schema::hasColumn('properties', 'created_at')) {
            return 0;
        }

        if (! Schema::hasColumn('properties', 'created_by')
            && ! Schema::hasColumn('properties', 'agent_id')) {
            return 0;
        }

        return (int) DB::table('properties')
            ->where(function ($query) use ($user) {
                if (Schema::hasColumn('properties', 'created_by')) {
                    $query->where('created_by', $user->id);
                }

                if (Schema::hasColumn('properties', 'agent_id')) {
                    $query->orWhere('agent_id', $user->id);
                }
            })
            ->whereBetween('created_at', [$startUtc->toDateTimeString(), $endUtc->toDateTimeString()])
            ->count();
    }

    private function countDeals(User $user, Carbon $startUtc, Carbon $endUtc): int
    {
        if (! Schema::hasTable('properties')
            || ! Schema::hasColumn('properties', 'agent_id')
            || ! Schema::hasColumn('properties', 'moderation_status')
            || ! Schema::hasColumn('properties', 'sold_at')) {
            return 0;
        }

        return (int) DB::table('properties')
            ->where('agent_id', $user->id)
            ->whereIn('moderation_status', ['sold', 'rented', 'sold_by_owner'])
            ->whereBetween('sold_at', [$startUtc->toDateTimeString(), $endUtc->toDateTimeString()])
            ->count();
    }

    private function utcDayBounds(string $reportDate): array
    {
        $start = Carbon::parse($reportDate, $this->timezone())->startOfDay();
        $end = Carbon::parse($reportDate, $this->timezone())->endOfDay();

        return [
            $start->copy()->setTimezone('UTC'),
            $end->copy()->setTimezone('UTC'),
        ];
    }

    private function timezone(): string
    {
        return (string) config('app.timezone', 'Asia/Dushanbe');
    }
}
