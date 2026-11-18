<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class PublicTeamController extends Controller
{

    public function index(Request $request): JsonResponse
    {
        $agents = $this->publicAgentsQuery()
            ->select(['id', 'name', 'phone', 'photo', 'role_id'])
            ->with('role:id,slug')
            ->get()
            ->sortBy(fn (User $user) => $user->role?->slug === 'mop' ? 0 : 1)
            ->values();

        $viewer = $request->user() ?? $request->user('sanctum');
        $canViewAll = $viewer?->status === User::STATUS_ACTIVE
            && $viewer->role !== null && $viewer->role->slug !== 'client';

        if (! $canViewAll) {
            $agents = $this->publicPreview($agents);
        }

        return response()->json($agents->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'photo' => $user->photo,
        ]))
            ->header('Cache-Control', 'no-store')
            ->header('Vary', 'Authorization');
    }

    private function publicPreview(Collection $agents): Collection
    {
        if ($agents->count() <= 10) {
            return $agents;
        }

        [$from, $to] = $this->resolveDateRange([]);
        // Use the same current-month rankings and tie breakers as the honor board.
        $rankings = [
            $this->salesByCountRows($from, $to, null, 10),
            $this->showsRows($from, $to, null, 10),
            $this->addedRows($from, $to, null, 10),
        ];
        $ids = collect();
        foreach ($rankings as $rows) {
            $ids = $ids->merge($rows->take(3)->pluck('agent_id'));
        }
        // A person can lead several nominations. Fill their duplicate slots with
        // the next ranked people, without repeating anyone in the team grid.
        for ($rank = 3; $rank < 10; $rank++) {
            foreach ($rankings as $rows) {
                if ($row = $rows->get($rank)) {
                    $ids->push($row->agent_id);
                }
            }
        }
        $byId = $agents->keyBy('id');
        $featured = $ids->unique()->map(fn ($id) => $byId->get($id))->filter()->take(9)->values();
        $remaining = $agents->whereNotIn('id', $featured->pluck('id'))->sortBy('id')->values();

        // Rotate the extra person daily; keep the same selection on reload.
        // At the start of a quiet month, fill any missing nominees the same way.
        $offset = intdiv(Carbon::today()->getTimestamp(), 86400) % $remaining->count();
        $slots = 10 - $featured->count();
        for ($index = 0; $index < $slots; $index++) {
            $featured->push($remaining->get(($offset + $index) % $remaining->count()));
        }

        return $featured;
    }

    private function resolveSaleAgentColumn(): ?string
    {
        if (Schema::hasColumn('properties', 'sale_agent_id')) {
            return 'sale_agent_id';
        }

        if (Schema::hasColumn('properties', 'sale_user_id')) {
            return 'sale_user_id';
        }

        return null;
    }

    public function hallOfFame(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['today', 'week', 'month', 'quarter', 'year', 'all'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'sale_currency' => ['nullable', Rule::in(['TJS', 'USD'])],
        ]);

        [$from, $to] = $this->resolveDateRange($validated);
        $limit = (int) ($validated['limit'] ?? 10);
        $branchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;
        $saleCurrency = $validated['sale_currency'] ?? 'TJS';

        $salesByCountRows = $this->salesByCountRows($from, $to, $branchId, $limit);
        $salesByAmountRows = $this->salesByAmountRows($from, $to, $branchId, $saleCurrency, $limit);
        $showsRows = $this->showsRows($from, $to, $branchId, $limit);
        $addedRows = $this->addedRows($from, $to, $branchId, $limit);

        $userIds = collect()
            ->merge($salesByCountRows->pluck('agent_id'))
            ->merge($salesByAmountRows->pluck('agent_id'))
            ->merge($showsRows->pluck('agent_id'))
            ->merge($addedRows->pluck('agent_id'))
            ->filter()
            ->unique()
            ->values();

        $users = $this->loadPublicUsers($userIds, $branchId);

        return response()
            ->json([
                'period' => [
                    'type' => $validated['period'] ?? ($request->filled('date_from') || $request->filled('date_to') ? 'custom' : 'month'),
                    'date_from' => $from->toDateString(),
                    'date_to' => $to->toDateString(),
                ],
                'filters' => [
                    'branch_id' => $branchId,
                    'limit' => $limit,
                    'sale_currency' => $saleCurrency,
                ],
                'nominations' => [
                    'best_sales_by_count' => $this->buildNomination(
                        'Лучший продажник',
                        'sold_count',
                        $salesByCountRows,
                        $users
                    ),
                    'most_showings_added' => $this->buildNomination(
                        'Лидер по добавленным показам',
                        'shows_count',
                        $showsRows,
                        $users
                    ),
                    'most_properties_added' => $this->buildNomination(
                        'Лидер по добавленным объектам',
                        'added_count',
                        $addedRows,
                        $users
                    ),
                ],
            ])
            ->header('Cache-Control', 'no-store');
    }

    private function resolveDateRange(array $validated): array
    {
        if (!empty($validated['date_from']) || !empty($validated['date_to'])) {
            $from = !empty($validated['date_from'])
                ? Carbon::parse($validated['date_from'])->startOfDay()
                : Carbon::now()->startOfMonth();
            $to = !empty($validated['date_to'])
                ? Carbon::parse($validated['date_to'])->endOfDay()
                : Carbon::now()->endOfDay();

            return [$from, $to];
        }

        $now = Carbon::now();
        $period = $validated['period'] ?? 'month';

        return match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'all' => [Carbon::create(2000, 1, 1)->startOfDay(), $now->copy()->endOfDay()],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    private function publicAgentsQuery(?int $branchId = null)
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('role', fn ($query) => $query->withPermission('public_team.subject.profile'))
            ->when(
                $branchId && Schema::hasColumn('users', 'branch_id'),
                fn ($query) => $query->where('branch_id', $branchId)
            );
    }

    private function salesByCountRows(Carbon $from, Carbon $to, ?int $branchId, int $limit): Collection
    {
        $saleAgentColumn = $this->resolveSaleAgentColumn();
        $fallbackAgentExpr = $saleAgentColumn
            ? "COALESCE(properties.{$saleAgentColumn}, properties.agent_id)"
            : 'properties.agent_id';

        $activitySub = Property::query()
            ->joinSub(
                $this->publicAgentsQuery($branchId)->select('users.id'),
                'agents',
                fn ($join) => $join->on('agents.id', '=', 'properties.created_by')
            )
            ->whereBetween('properties.created_at', [$from, $to])
            ->whereNotIn('properties.moderation_status', ['sold', 'rented', 'sold_by_owner'])
            ->selectRaw('properties.created_by as agent_id')
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw("SUM(CASE WHEN properties.moderation_status = 'approved' THEN 1 ELSE 0 END) as approved_count")
            ->groupBy('properties.created_by');

        $salesSub = DB::table('property_agent_sales as sales')
            ->join('properties', 'properties.id', '=', 'sales.property_id')
            ->joinSub(
                $this->publicAgentsQuery($branchId)->select('users.id'),
                'agents',
                fn ($join) => $join->on('agents.id', '=', 'sales.agent_id')
            )
            ->whereIn('properties.moderation_status', ['sold', 'rented'])
            ->whereBetween('properties.sold_at', [$from, $to])
            ->selectRaw('sales.agent_id as agent_id, sales.property_id as property_id');

        $fallbackSub = DB::table('properties')
            ->leftJoin('property_agent_sales as sales', 'sales.property_id', '=', 'properties.id')
            ->joinSub(
                $this->publicAgentsQuery($branchId)->select('users.id'),
                'agents',
                fn ($join) => $join->on('agents.id', '=', DB::raw($fallbackAgentExpr))
            )
            ->whereIn('properties.moderation_status', ['sold', 'rented'])
            ->whereBetween('properties.sold_at', [$from, $to])
            ->whereNull('sales.property_id')
            ->selectRaw("$fallbackAgentExpr as agent_id, properties.id as property_id");

        return DB::query()
            ->fromSub($salesSub->unionAll($fallbackSub), 'sales_union')
            ->leftJoinSub(
                $activitySub,
                'activity',
                fn ($join) => $join->on('activity.agent_id', '=', 'sales_union.agent_id')
            )
            ->selectRaw('sales_union.agent_id, COUNT(DISTINCT sales_union.property_id) as sold_count')
            ->selectRaw('MAX(COALESCE(activity.approved_count, 0)) as approved_count')
            ->selectRaw('MAX(COALESCE(activity.total_count, 0)) as total_count')
            ->groupBy('sales_union.agent_id')
            ->orderByDesc('sold_count')
            ->orderByDesc('approved_count')
            ->orderByDesc('total_count')
            ->orderByDesc('sales_union.agent_id')
            ->limit($limit)
            ->get();
    }

    private function salesByAmountRows(Carbon $from, Carbon $to, ?int $branchId, string $currency, int $limit): Collection
    {
        return DB::table('property_agent_sales as sales')
            ->join('properties', 'properties.id', '=', 'sales.property_id')
            ->joinSub(
                $this->publicAgentsQuery($branchId)->select('users.id'),
                'agents',
                fn ($join) => $join->on('agents.id', '=', 'sales.agent_id')
            )
            ->where('properties.moderation_status', 'sold')
            ->whereBetween('properties.sold_at', [$from, $to])
            ->where('properties.actual_sale_currency', $currency)
            ->whereNotNull('properties.actual_sale_price')
            ->selectRaw('sales.agent_id, ROUND(SUM(properties.actual_sale_price), 2) as sale_amount')
            ->groupBy('sales.agent_id')
            ->orderByDesc('sale_amount')
            ->orderByDesc('sales.agent_id')
            ->limit($limit)
            ->get();
    }

    private function showsRows(Carbon $from, Carbon $to, ?int $branchId, int $limit): Collection
    {
        return Booking::query()
            ->joinSub(
                $this->publicAgentsQuery($branchId)->select('users.id'),
                'agents',
                fn ($join) => $join->on('agents.id', '=', 'bookings.agent_id')
            )
            ->whereBetween('bookings.created_at', [$from, $to])
            ->selectRaw('bookings.agent_id, COUNT(*) as shows_count')
            ->groupBy('bookings.agent_id')
            ->orderByDesc('shows_count')
            ->orderByDesc('bookings.agent_id')
            ->limit($limit)
            ->get();
    }

    private function addedRows(Carbon $from, Carbon $to, ?int $branchId, int $limit): Collection
    {
        return Property::query()
            ->joinSub(
                $this->publicAgentsQuery($branchId)->select('users.id'),
                'agents',
                fn ($join) => $join->on('agents.id', '=', 'properties.created_by')
            )
            ->whereBetween('properties.created_at', [$from, $to])
            ->where(function ($query) {
                $query->whereNull('properties.moderation_status')
                    ->orWhere('properties.moderation_status', '!=', 'deleted');
            })
            ->selectRaw('properties.created_by as agent_id, COUNT(*) as added_count')
            ->groupBy('properties.created_by')
            ->orderByDesc('added_count')
            ->orderByDesc('properties.created_by')
            ->limit($limit)
            ->get();
    }

    private function loadPublicUsers(Collection $userIds, ?int $branchId): Collection
    {
        if ($userIds->isEmpty()) {
            return collect();
        }

        $columns = ['id', 'name', 'status', 'role_id'];

        if (Schema::hasColumn('users', 'photo')) {
            $columns[] = 'photo';
        }

        if (Schema::hasColumn('users', 'branch_id')) {
            $columns[] = 'branch_id';
        }

        if (Schema::hasColumn('users', 'position')) {
            $columns[] = 'position';
        }

        return $this->publicAgentsQuery($branchId)
            ->select($columns)
            ->with('role:id,name,slug')
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');
    }

    private function buildNomination(
        string $title,
        string $metricKey,
        Collection $rows,
        Collection $users,
        array $meta = []
    ): array {
        $leaders = $rows
            ->map(function ($row) use ($users, $metricKey) {
                $user = $users->get((int) $row->agent_id);

                if (!$user) {
                    return null;
                }

                return [
                    'agent' => $this->transformUser($user),
                    $metricKey => $this->normalizeMetricValue($row->{$metricKey} ?? 0),
                ];
            })
            ->filter()
            ->values();

        return [
            'title' => $title,
            'metric' => $metricKey,
            'winner' => $leaders->first(),
            'leaders' => $leaders->all(),
            'meta' => (object) $meta,
        ];
    }

    private function transformUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'photo' => Schema::hasColumn('users', 'photo') ? $user->getAttribute('photo') : null,
            'position' => $this->resolvePosition($user),
            'branch_id' => Schema::hasColumn('users', 'branch_id') ? $user->getAttribute('branch_id') : null,
        ];
    }

    private function resolvePosition(User $user): ?string
    {
        if (Schema::hasColumn('users', 'position')) {
            return $user->getAttribute('position');
        }

        return match ($user->role?->slug) {
            'agent' => 'Специалист по недвижимости',
            'manager' => 'Менеджер',
            default => $user->role?->name,
        };
    }

    private function normalizeMetricValue(mixed $value): int|float
    {
        $number = (float) $value;

        if (fmod($number, 1.0) === 0.0) {
            return (int) $number;
        }

        return round($number, 2);
    }
}
