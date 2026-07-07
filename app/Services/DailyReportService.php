<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\KpiQualityIssue;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DailyReportService
{
    public function __construct(
        private readonly SalesAttributionService $salesAttributionService
    ) {
    }

    public function statusForUser(User $user): array
    {
        $user->loadMissing('role');

        $roleSlug = $user->role?->slug;
        $required = in_array($roleSlug, $this->requiredRoleSlugs(), true);
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

        if (! in_array($user->role?->slug, $this->requiredRoleSlugs(), true)) {
            return null;
        }

        if (! Schema::hasTable('daily_reports')) {
            return null;
        }

        $now = Carbon::now($this->timezone());
        [$cutoffHour, $cutoffMinute] = $this->missingReportCutoffTime();

        if ($now->lt($now->copy()->setTime($cutoffHour, $cutoffMinute))) {
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

    /**
     * Single source of truth for roles required to submit daily report.
     *
     * @return list<string>
     */
    private function requiredRoleSlugs(): array
    {
        $roles = config('kpi.daily_report.enforced_roles', ['agent', 'mop', 'intern']);

        if (! is_array($roles)) {
            return ['agent', 'mop', 'intern'];
        }

        $normalized = array_values(array_filter(array_map(
            static fn ($role) => is_string($role) ? trim($role) : '',
            $roles
        )));

        return $normalized !== [] ? $normalized : ['agent', 'mop', 'intern'];
    }

    /**
     * @return array{0:int,1:int}
     */
    private function missingReportCutoffTime(): array
    {
        $raw = (string) config('kpi.daily_report.missing_report_check_time', '11:00');
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $matches) !== 1) {
            return [11, 0];
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return [11, 0];
        }

        return [$hour, $minute];
    }

    public function defaultReportDate(User $user): string
    {
        return $this->missingReportDate($user) ?? Carbon::now($this->timezone())->toDateString();
    }

    public function autoMetrics(User $user, string $reportDate): array
    {
        [$startUtc, $endUtc] = $this->utcDayBounds($reportDate);

        $showsCount = $this->countBookings($user, $startUtc, $endUtc);
        $salesCount = $this->countDeals($user, $startUtc, $endUtc);

        return [
            'ad_count' => $this->countAds($user, $startUtc, $endUtc),
            'calls_count' => $this->countCalls($user, $startUtc, $endUtc),
            'meetings_count' => $showsCount,
            'shows_count' => $showsCount,
            'new_clients_count' => $this->countNewClients($user, $startUtc, $endUtc),
            'new_properties_count' => $this->countNewProperties($user, $startUtc, $endUtc),
            // Keep integer legacy deals_count for backward compatibility.
            'deals_count' => (int) floor($salesCount),
            // Fractional sales KPI metric (1/N for N sale participants).
            'sales_count' => $salesCount,
        ];
    }

    public function autoMetricsDebug(User $user, string $reportDate, ?array $auto = null): array
    {
        [$startUtc, $endUtc] = $this->utcDayBounds($reportDate);
        $localStart = Carbon::parse($reportDate, $this->timezone())->startOfDay();
        $localEnd = Carbon::parse($reportDate, $this->timezone())->endOfDay();
        $autoMetrics = $auto ?? $this->autoMetrics($user, $reportDate);

        return [
            'employee_id' => (int) $user->id,
            'date' => $reportDate,
            'timezone' => $this->timezone(),
            'period_bounds' => [
                'local' => [
                    'start' => $localStart->toDateTimeString(),
                    'end' => $localEnd->toDateTimeString(),
                ],
                'utc' => [
                    'start' => $startUtc->toDateTimeString(),
                    'end' => $endUtc->toDateTimeString(),
                ],
            ],
            'object_ids' => $this->newPropertyIds($user, $startUtc, $endUtc),
            'booking_ids' => $this->bookingIds($user, $startUtc, $endUtc),
            'sales_property_ids' => $this->dealPropertyIds($user, $startUtc, $endUtc),
            'metrics' => [
                'objects' => ['fact' => (int) ($autoMetrics['new_properties_count'] ?? 0)],
                'shows' => ['fact' => (int) ($autoMetrics['shows_count'] ?? 0)],
                'sales' => ['fact' => (float) ($autoMetrics['sales_count'] ?? 0)],
            ],
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
                'ads' => $report?->ad_count ?? 0,
                'calls' => $report?->calls_count ?? 0,
                'comment' => $report?->comment ?? '',
                'plans_for_tomorrow' => $report?->plans_for_tomorrow ?? '',
            ],
            'report' => $report,
        ]);
    }

    private function countBookings(User $user, Carbon $startUtc, Carbon $endUtc): int
    {
        return count($this->bookingIds($user, $startUtc, $endUtc));
    }

    private function bookingIds(User $user, Carbon $startUtc, Carbon $endUtc): array
    {
        if (! Schema::hasTable('bookings')
            || ! Schema::hasColumn('bookings', 'agent_id')
            || ! Schema::hasColumn('bookings', 'start_time')) {
            return [];
        }

        return DB::table('bookings')
            ->where('agent_id', $user->id)
            ->whereBetween('start_time', [$startUtc->toDateTimeString(), $endUtc->toDateTimeString()])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
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

    private function countAds(User $user, Carbon $startUtc, Carbon $endUtc): int
    {
        if (! Schema::hasTable('crm_tasks')
            || ! Schema::hasTable('crm_task_types')
            || ! Schema::hasColumn('crm_tasks', 'assignee_id')
            || ! Schema::hasColumn('crm_tasks', 'status')
            || ! Schema::hasColumn('crm_tasks', 'completed_at')) {
            return 0;
        }

        return (int) DB::table('crm_tasks')
            ->join('crm_task_types', 'crm_task_types.id', '=', 'crm_tasks.task_type_id')
            ->where('crm_tasks.assignee_id', $user->id)
            ->where('crm_tasks.status', 'done')
            ->whereIn('crm_task_types.code', ['AD_PUBLICATION', 'AD_CREATE'])
            ->whereBetween('crm_tasks.completed_at', [$startUtc->toDateTimeString(), $endUtc->toDateTimeString()])
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
        return count($this->newPropertyIds($user, $startUtc, $endUtc));
    }

    private function newPropertyIds(User $user, Carbon $startUtc, Carbon $endUtc): array
    {
        if (! Schema::hasTable('properties') || ! Schema::hasColumn('properties', 'created_at')) {
            return [];
        }

        $hasCreatedBy = Schema::hasColumn('properties', 'created_by');
        $hasAgentId = Schema::hasColumn('properties', 'agent_id');

        if (! $hasCreatedBy && ! $hasAgentId) {
            return [];
        }

        return DB::table('properties')
            ->where(function ($query) use ($user) {
                $hasCreatedBy = Schema::hasColumn('properties', 'created_by');
                $hasAgentId = Schema::hasColumn('properties', 'agent_id');

                if ($hasCreatedBy) {
                    $query->where('created_by', $user->id);
                }

                if ($hasAgentId) {
                    if (! $hasCreatedBy) {
                        $query->orWhere('agent_id', $user->id);
                        return;
                    }

                    // Fallback to agent_id only for legacy rows with empty creator.
                    $query->orWhere(function ($legacy) use ($user) {
                        $legacy->where('agent_id', $user->id)
                            ->where(function ($creatorMissing) {
                                $creatorMissing->whereNull('created_by')
                                    ->orWhere('created_by', 0);
                            });
                    });
                }
            })
            ->whereBetween('created_at', [$startUtc->toDateTimeString(), $endUtc->toDateTimeString()])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function countDeals(User $user, Carbon $startUtc, Carbon $endUtc): float
    {
        return $this->dealCredits($user, $startUtc, $endUtc)['sales_count'];
    }

    private function dealPropertyIds(User $user, Carbon $startUtc, Carbon $endUtc): array
    {
        return $this->dealCredits($user, $startUtc, $endUtc)['property_ids'];
    }

    private function dealCredits(User $user, Carbon $startUtc, Carbon $endUtc): array
    {
        if (! Schema::hasTable('properties')
            || ! Schema::hasColumn('properties', 'moderation_status')
            || ! Schema::hasColumn('properties', 'sold_at')) {
            return ['sales_count' => 0.0, 'property_ids' => []];
        }

        $select = ['id', 'moderation_status', 'agent_id', 'sale_user_id', 'created_by'];
        if (Schema::hasColumn('properties', 'sale_agent_id')) {
            $select[] = 'sale_agent_id';
        }

        $soldProperties = DB::table('properties')
            ->select($select)
            ->whereIn('moderation_status', ['sold', 'sold_by_owner', 'rented'])
            ->whereBetween('sold_at', [$startUtc->toDateTimeString(), $endUtc->toDateTimeString()])
            ->get();

        if ($soldProperties->isEmpty()) {
            return ['sales_count' => 0.0, 'property_ids' => []];
        }

        $creditsByProperty = $this->salesAttributionService->creditsByProperty($soldProperties, ['sold', 'sold_by_owner', 'rented']);

        $credit = 0.0;
        $propertyIds = [];
        foreach ($soldProperties as $property) {
            $propertyId = (int) ($property->id ?? 0);
            $propertyCredit = (float) ($creditsByProperty[$propertyId][$user->id] ?? 0.0);
            if ($propertyCredit <= 0) {
                continue;
            }

            $propertyIds[] = $propertyId;
            $credit += $propertyCredit;
        }

        sort($propertyIds);

        return [
            'sales_count' => round((float) $credit, 4),
            'property_ids' => array_values(array_unique($propertyIds)),
        ];
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

    private function reportSalesQualityIssue(string $code, int $propertyId, array $details): void
    {
        if (!Schema::hasTable('kpi_quality_issues')) {
            return;
        }

        KpiQualityIssue::query()->updateOrCreate(
            ['title' => $code.'#'.$propertyId],
            [
                'severity' => 'high',
                'detected_at' => now(),
                'status' => 'open',
                'details' => array_merge($details, ['code' => $code, 'property_id' => $propertyId]),
            ]
        );
    }
}
