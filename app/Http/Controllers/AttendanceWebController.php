<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDailyComment;
use App\Models\AttendanceDailySummary;
use App\Models\AttendanceEvent;
use App\Models\AttendanceWorkSchedule;
use App\Models\User;
use App\Services\Attendance\AttendanceAccessService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AttendanceWebController extends Controller
{
    public function __construct(private readonly AttendanceAccessService $access) {}

    public function matrix(Request $request)
    {
        $this->access->assertCanViewTable($request->user());
        $filters = $this->matrixFilters($request);
        $timezone = (string) config('attendance.timezone', 'Asia/Dushanbe');
        $from = CarbonImmutable::parse($filters['date_from'], $timezone)->startOfDay();
        $to = CarbonImmutable::parse($filters['date_to'], $timezone)->endOfDay();
        $dates = collect(CarbonPeriod::create($from->startOfDay(), $to->startOfDay()))
            ->map(fn ($date) => $date->toDateString());

        $visibleQuery = $this->access->visibleUsersQuery($request->user())->select('users.*');
        $activeUsersCount = (clone $visibleQuery)->count('users.id');
        $this->applyUserFilters($visibleQuery, $filters, $from, $to);

        if ($filters['view'] === 'branches') {
            $users = $visibleQuery->orderBy('users.branch_id')->orderBy('users.name')->get();
            $payload = $this->branchRows($users, $dates, $from, $to);
            $pagination = ['current_page' => 1, 'last_page' => 1, 'per_page' => $users->count(), 'total' => $payload->count()];
        } else {
            $this->applySort($visibleQuery, $filters, $from, $to);
            $page = $visibleQuery->paginate($filters['per_page'])->appends($request->query());
            $payload = $this->userRows($page->getCollection(), $dates, $from, $to);
            $pagination = [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ];
        }

        return response()->json([
            'data' => $payload->values(),
            'meta' => [
                'timezone' => $timezone,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'permissions' => $this->access->permissions($request->user()),
                'summary' => $this->summary($request->user(), $activeUsersCount, $from, $to),
                'pagination' => $pagination,
                'last_updated_at' => AttendanceDailySummary::query()->max('updated_at'),
            ],
        ]);
    }

    public function day(Request $request, User $user, string $date)
    {
        $this->access->assertCanViewTable($request->user());
        $this->access->assertCanViewUser($request->user(), $user);
        $timezone = (string) config('attendance.timezone', 'Asia/Dushanbe');
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['date' => ['Дата должна быть в формате YYYY-MM-DD.']]);
        }
        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['date' => ['Дата должна быть в формате YYYY-MM-DD.']]);
        }

        $user->loadMissing(['role', 'branch', 'branchGroup']);
        $summary = AttendanceDailySummary::query()->where('user_id', $user->id)->whereDate('work_date', $date)->first();
        $settings = AttendanceWorkSchedule::query()->where('user_id', $user->id)->first();
        $schedule = ($settings?->schedule ?? config('attendance.default_schedule', []))[(string) $day->dayOfWeekIso] ?? null;
        $events = AttendanceEvent::query()
            ->with('device:id,name,serial_number')
            ->where('user_id', $user->id)
            ->whereBetween('occurred_at', [$day->utc(), $day->endOfDay()->utc()])
            ->orderBy('occurred_at')->orderBy('id')->get();
        $comment = AttendanceDailyComment::query()->with('author:id,name')->where('user_id', $user->id)->whereDate('work_date', $date)->first();

        return response()->json(['data' => [
            'user' => $this->userPayload($user),
            'work_date' => $date,
            'schedule' => is_array($schedule) ? [
                'starts_at' => $schedule['start'] ?? null,
                'ends_at' => $schedule['end'] ?? null,
                'label' => isset($schedule['start'], $schedule['end']) ? $schedule['start'].'–'.$schedule['end'] : null,
            ] : null,
            'summary' => $this->dayPayload($summary, is_array($schedule), collect(), $comment),
            'events' => $events->map(fn (AttendanceEvent $event) => [
                'id' => $event->id,
                'occurred_at' => $event->occurred_at?->toISOString(),
                'event_type' => $event->event_type,
                'verification_method' => $event->verification_method,
                'is_semantic_duplicate' => (bool) $event->is_duplicate,
                'device' => $event->device ? $event->device->only(['id', 'name', 'serial_number']) : null,
            ])->values(),
            'comment' => $comment ? $this->commentPayload($comment) : null,
        ]]);
    }

    private function matrixFilters(Request $request): array
    {
        $timezone = (string) config('attendance.timezone', 'Asia/Dushanbe');
        $today = CarbonImmutable::now($timezone);
        $data = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'view' => ['nullable', Rule::in(['users', 'branches'])],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'branch_group_id' => ['nullable', 'integer', 'exists:branch_groups,id'],
            'role' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in(['present', 'late', 'absent', 'incomplete'])],
            'verification_method' => ['nullable', Rule::in(['face', 'card', 'fingerprint', 'password', 'unknown'])],
            'device_id' => ['nullable', 'integer', 'exists:attendance_devices,id'],
            'has_comment' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:150'],
            'sort' => ['nullable', Rule::in(['name', 'late_count', 'average_late_minutes'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $data['date_from'] ??= $today->startOfMonth()->toDateString();
        $data['date_to'] ??= $today->endOfMonth()->toDateString();
        if (CarbonImmutable::parse($data['date_from'])->diffInDays(CarbonImmutable::parse($data['date_to'])) > 30) {
            throw ValidationException::withMessages(['date_to' => ['Диапазон не может превышать 31 день.']]);
        }
        $data['view'] ??= 'users';
        $data['sort'] ??= 'name';
        $data['per_page'] = min((int) ($data['per_page'] ?? 50), 50);

        return $data;
    }

    private function applyUserFilters(Builder $query, array $filters, CarbonImmutable $from, CarbonImmutable $to): void
    {
        foreach (['branch_id', 'branch_group_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where('users.'.$field, $filters[$field]);
            }
        }
        if (isset($filters['role'])) {
            $query->whereHas('role', fn (Builder $roles) => $roles->where('slug', $filters['role']));
        }
        if (isset($filters['user_id'])) {
            $query->whereKey($filters['user_id']);
        }
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function (Builder $users) use ($search) {
                $users->where('users.name', 'like', '%'.$search.'%');
                if (ctype_digit($search)) {
                    $users->orWhere('users.id', (int) $search);
                }
            });
        }
        if (isset($filters['status'])) {
            $query->whereIn('users.id', AttendanceDailySummary::query()->select('user_id')
                ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
                ->where('status', $filters['status']));
        }
        if (! empty($filters['has_comment'])) {
            $query->whereIn('users.id', AttendanceDailyComment::query()->select('user_id')
                ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()]));
        }
        if (isset($filters['verification_method']) || isset($filters['device_id'])) {
            $events = AttendanceEvent::query()->select('user_id')->whereBetween('occurred_at', [$from->utc(), $to->utc()]);
            if (isset($filters['verification_method'])) {
                $events->where('verification_method', $filters['verification_method']);
            }
            if (isset($filters['device_id'])) {
                $events->where('device_id', $filters['device_id']);
            }
            $query->whereIn('users.id', $events);
        }
    }

    private function applySort(Builder $query, array $filters, CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($filters['sort'] === 'late_count') {
            $query->orderByDesc(AttendanceDailySummary::query()->selectRaw('COUNT(*)')
                ->whereColumn('attendance_daily_summaries.user_id', 'users.id')
                ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
                ->where('status', 'late'));
        } elseif ($filters['sort'] === 'average_late_minutes') {
            $query->orderByDesc(AttendanceDailySummary::query()->selectRaw('COALESCE(AVG(late_minutes), 0)')
                ->whereColumn('attendance_daily_summaries.user_id', 'users.id')
                ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()]));
        }
        $query->orderBy('users.name')->orderBy('users.id');
    }

    private function userRows(Collection $users, Collection $dates, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $data = $this->matrixData($users, $from, $to);

        return $users->map(function (User $user) use ($dates, $data) {
            $summaries = $data['summaries']->get($user->id, collect());
            $late = $summaries->where('status', 'late');

            return [
                'user' => $this->userPayload($user),
                'totals' => [
                    'present' => $summaries->where('status', 'present')->count(),
                    'late' => $late->count(),
                    'absent' => $summaries->where('status', 'absent')->count(),
                    'incomplete' => $summaries->where('status', 'incomplete')->count(),
                    'average_late_minutes' => (int) round((float) ($late->avg('late_minutes') ?? 0)),
                ],
                'days' => $dates->mapWithKeys(function (string $date) use ($user, $data) {
                    $summary = $data['summaries']->get($user->id, collect())->get($date);
                    $comment = $data['comments']->get($user->id, collect())->get($date);
                    $methods = $data['methods']->get($user->id, collect())->get($date, collect());

                    return [$date => $this->dayPayload($summary, $this->isWorkingDay($data['schedules']->get($user->id), $date), $methods, $comment)];
                })->all(),
            ];
        });
    }

    private function branchRows(Collection $users, Collection $dates, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $data = $this->matrixData($users, $from, $to);

        return $users->groupBy(fn (User $user) => $user->branch_id ?? 0)->map(function (Collection $branchUsers) use ($dates, $data) {
            $branch = $branchUsers->first()?->branch;

            return [
                'branch' => ['id' => $branch?->id ?? 0, 'name' => $branch?->name ?? 'Без филиала'],
                'days' => $dates->mapWithKeys(function (string $date) use ($branchUsers, $data) {
                    $scheduled = $branchUsers->filter(fn (User $user) => $this->isWorkingDay($data['schedules']->get($user->id), $date));
                    $summaries = $scheduled->map(fn (User $user) => $data['summaries']->get($user->id, collect())->get($date))->filter();
                    $checkedIn = $summaries->whereIn('status', ['present', 'late', 'incomplete'])->count();
                    $scheduledCount = $scheduled->count();

                    return [$date => [
                        'scheduled_users_count' => $scheduledCount,
                        'checked_in_count' => $checkedIn,
                        'present_count' => $summaries->where('status', 'present')->count(),
                        'late_count' => $summaries->where('status', 'late')->count(),
                        'absent_count' => $summaries->where('status', 'absent')->count(),
                        'incomplete_count' => $summaries->where('status', 'incomplete')->count(),
                        'attendance_pct' => $scheduledCount > 0 ? round($checkedIn / $scheduledCount * 100, 1) : 0,
                    ]];
                })->all(),
            ];
        });
    }

    private function matrixData(Collection $users, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $users->pluck('id');
        if ($ids->isEmpty()) {
            return ['summaries' => collect(), 'comments' => collect(), 'methods' => collect(), 'schedules' => collect()];
        }
        $summaries = AttendanceDailySummary::query()->whereIn('user_id', $ids)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get()
            ->groupBy('user_id')->map(fn (Collection $rows) => $rows->keyBy(fn ($row) => $row->work_date->toDateString()));
        $comments = AttendanceDailyComment::query()->whereIn('user_id', $ids)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get()
            ->groupBy('user_id')->map(fn (Collection $rows) => $rows->keyBy(fn ($row) => $row->work_date->toDateString()));
        $timezone = (string) config('attendance.timezone', 'Asia/Dushanbe');
        $methods = AttendanceEvent::query()->whereIn('user_id', $ids)->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->get(['user_id', 'occurred_at', 'verification_method'])->groupBy('user_id')
            ->map(fn (Collection $rows) => $rows->groupBy(fn ($event) => $event->occurred_at->setTimezone($timezone)->toDateString())
                ->map(fn (Collection $events) => $events->pluck('verification_method')->unique()->values()));
        $schedules = AttendanceWorkSchedule::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');

        return compact('summaries', 'comments', 'methods', 'schedules');
    }

    private function dayPayload(?AttendanceDailySummary $summary, bool $workingDay, Collection $methods, ?AttendanceDailyComment $comment): array
    {
        return [
            'status' => $summary?->status,
            'is_working_day' => $workingDay,
            'first_in_at' => $summary?->first_in_at?->toISOString(),
            'last_out_at' => $summary?->last_out_at?->toISOString(),
            'worked_minutes' => $summary?->worked_minutes,
            'late_minutes' => $summary?->late_minutes,
            'events_count' => (int) ($summary?->events_count ?? 0),
            'verification_methods' => $methods->values(),
            'has_comment' => $comment !== null,
            'comment_preview' => $comment ? Str::limit($comment->comment, 120) : null,
        ];
    }

    private function isWorkingDay(?AttendanceWorkSchedule $settings, string $date): bool
    {
        $day = CarbonImmutable::parse($date, $settings?->timezone ?: config('attendance.timezone'));
        if ($settings && in_array($date, $settings->holidays ?? [], true)) {
            return false;
        }

        return is_array(($settings?->schedule ?? config('attendance.default_schedule', []))[(string) $day->dayOfWeekIso] ?? null);
    }

    private function summary(User $viewer, int $activeUsers, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $visibleIds = $this->access->visibleUsersQuery($viewer)->select('users.id');
        $today = CarbonImmutable::now((string) config('attendance.timezone'))->toDateString();
        $todayRows = AttendanceDailySummary::query()->whereIn('user_id', clone $visibleIds)->whereDate('work_date', $today);
        $periodRows = AttendanceDailySummary::query()->whereIn('user_id', clone $visibleIds)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()]);

        return [
            'active_users' => $activeUsers,
            'checked_in_today' => (clone $todayRows)->whereIn('status', ['present', 'late', 'incomplete'])->count(),
            'late_today' => (clone $todayRows)->where('status', 'late')->count(),
            'absent_today' => (clone $todayRows)->where('status', 'absent')->count(),
            'incomplete_days' => (clone $periodRows)->where('status', 'incomplete')->count(),
            'average_late_minutes' => (int) round((float) ((clone $periodRows)->where('late_minutes', '>', 0)->avg('late_minutes') ?? 0)),
        ];
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'photo' => $user->photo,
            'role' => $user->role?->slug,
            'branch_id' => $user->branch_id,
            'branch_name' => $user->branch?->name,
            'branch_group_id' => $user->branch_group_id,
            'branch_group_name' => $user->branchGroup?->name,
        ];
    }

    private function commentPayload(AttendanceDailyComment $comment): array
    {
        return [
            'id' => $comment->id,
            'comment' => $comment->comment,
            'version' => $comment->version,
            'author' => $comment->author ? $comment->author->only(['id', 'name']) : null,
            'updated_at' => $comment->updated_at?->toISOString(),
        ];
    }
}
