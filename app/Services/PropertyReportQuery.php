<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BranchGroup;
use App\Models\Property;
use App\Models\User;
use App\Support\RbacBranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shared inventory for report cards, employees, charts and object drilldowns. */
class PropertyReportQuery
{
    public const CLOSED = ['sold', 'rented', 'sold_by_owner'];

    public function __construct(
        private readonly RbacBranchScope $branchScope,
        private readonly SalesAttributionService $salesAttributionService,
    ) {}

    public function ownerExpression(): string
    {
        return "CASE WHEN properties.moderation_status IN ('sold','rented','sold_by_owner') THEN "
            .$this->salesAttributionService->primarySellerExpression().' ELSE '.$this->propertyAuthorExpression().' END';
    }

    public function ownerId(object $property): int
    {
        return in_array($property->moderation_status, self::CLOSED, true)
            ? $this->salesAttributionService->primarySellerId($property)
            : ((int) $property->created_by > 0 ? (int) $property->created_by : (int) $property->agent_id);
    }

    public function inventory(Request $request): Builder
    {
        $filters = $request->except(['date_from', 'date_to', 'from', 'to', 'sold_at_from', 'sold_at_to',
            'deposit_received_at_from', 'deposit_received_at_to', 'date_field']);
        if ($request->filled('report_employee_id')) $filters['agent_id'] = $request->input('report_employee_id');
        $scoped = new Request($filters);
        $scoped->setUserResolver(fn () => $request->user());
        [$query] = $this->applyCommonFilters($scoped, Property::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q)));
        $request->validate(['include_inactive' => 'nullable|boolean',
            'date_from' => 'nullable|date_format:Y-m-d', 'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
            'sold_at_from' => 'nullable|date_format:Y-m-d', 'sold_at_to' => 'nullable|date_format:Y-m-d|after_or_equal:sold_at_from',
            'deposit_received_at_from' => 'nullable|date_format:Y-m-d', 'deposit_received_at_to' => 'nullable|date_format:Y-m-d|after_or_equal:deposit_received_at_from',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        abort_if($request->boolean('include_inactive') && ! $request->user()?->hasPermission('kpi.inactive.view'), 403);
        if (! $request->boolean('include_inactive')) {
            // Retain unattributed records and non-KPI employees. Only the explicit
            // inactive status is excluded, identically in every report surface.
            $owner = $this->ownerExpression();
            $query->where(fn ($q) => $q->whereNull(DB::raw($owner))->orWhereNotIn(DB::raw($owner),
                User::query()->where('status', 'inactive')->select('id')));
        }
        $from = $request->input('from', $request->input('date_from'));
        $to = $request->input('to', $request->input('date_to'));
        $soldFrom = $request->input('sold_at_from') ?: $from;
        $soldTo = $request->input('sold_at_to') ?: $to;
        $depositFrom = $request->input('deposit_received_at_from') ?: $from;
        $depositTo = $request->input('deposit_received_at_to') ?: $to;
        $range = function ($q, string $column, ?string $start, ?string $end) {
            $zone = (string) config('app.timezone', 'Asia/Dushanbe');
            if ($start) $q->where($column, '>=', Carbon::parse($start, 'Asia/Dushanbe')->startOfDay()->setTimezone($zone)->toDateTimeString());
            if ($end) $q->where($column, '<=', Carbon::parse($end, 'Asia/Dushanbe')->endOfDay()->setTimezone($zone)->toDateTimeString());
        };
        $query->where(function ($q) use ($range, $from, $to, $soldFrom, $soldTo, $depositFrom, $depositTo) {
            $q->where(function ($open) use ($range, $from, $to) {
                $open->where(fn ($status) => $status->whereNotIn('properties.moderation_status', [...self::CLOSED, 'deposit'])->orWhereNull('properties.moderation_status'));
                $range($open, 'properties.created_at', $from, $to);
            })->orWhere(function ($closed) use ($range, $soldFrom, $soldTo) {
                $closed->whereIn('properties.moderation_status', self::CLOSED);
                $range($closed, 'properties.sold_at', $soldFrom, $soldTo);
            })->orWhere(function ($deposit) use ($range, $depositFrom, $depositTo) {
                $deposit->where('properties.moderation_status', 'deposit');
                $range($deposit, 'properties.deposit_received_at', $depositFrom, $depositTo);
            });
        });
        return $query;
    }

    public function records(Request $request): Collection
    {
        // Reports need no descriptions, contacts or other large property fields.
        $columns = array_values(array_intersect([
            'id', 'moderation_status', 'contract_type_id', 'offer_type', 'rooms', 'price', 'total_area',
            'created_by', 'agent_id', 'sale_user_id', 'sale_agent_id', 'created_at', 'sold_at', 'deposit_received_at',
        ], Schema::getColumnListing('properties')));
        return $this->inventory($request)->get($columns);
    }

    public function summary(Collection $properties): array
    {
        $properties = collect($properties->all());
        $statuses = $properties->groupBy(fn ($p) => $p->moderation_status ?: 'unknown');
        $statusRows = fn ($groups) => $groups->map(fn ($items, $status) => ['moderation_status' => $status, 'cnt' => $items->count()])->values();
        return [
            'total' => $properties->count(),
            'published_sale' => $properties->where('moderation_status', 'approved')->where('offer_type', 'sale')->count(),
            'published_rent' => $properties->where('moderation_status', 'approved')->where('offer_type', 'rent')->count(),
            'by_status' => $statusRows($statuses->except([...self::CLOSED, 'approved'])),
            'sold_status' => $statusRows($statuses->only(self::CLOSED)),
            'by_offer_type' => $properties->groupBy('offer_type')->map(fn ($items, $offer) => ['offer_type' => $offer, 'cnt' => $items->count()])->values(),
            'avg_price' => round((float) $properties->avg('price'), 2), 'sum_price' => round((float) $properties->sum('price'), 2),
            'avg_total_area' => round((float) $properties->avg('total_area'), 2), 'sum_total_area' => round((float) $properties->sum('total_area'), 2),
        ];
    }

    public function rooms(Collection $properties): Collection
    {
        return collect($properties->all())->whereNotNull('rooms')->groupBy('rooms')->map(fn ($items, $rooms) => ['rooms' => $rooms, 'cnt' => $items->count()])->sortBy('rooms')->values();
    }

    private function toArray($value): array
    {
        if ($value === null || $value === '') return [];
        return is_array($value) ? array_values(array_filter($value, fn ($v) => $v !== '' && $v !== null))
            : array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
    }

    public function ensureReportsAllowed(Request $request): void
    {
        $actor = $request->user() ?? auth()->user();
        $actor?->loadMissing('role');
        abort_unless($actor?->hasPermission('property_reports.view'), 403, 'Forbidden');
    }

    public function applyBranchAccessFilter(Request $request, $query, string $creatorColumn = 'created_by', ?string $reportOwnerExpression = null): void
    {
        $this->validateReportScope($request);

        $authUser = auth()->user();
        $authUser?->loadMissing('role');
        if (! collect(['all', 'branch', 'group'])->contains(fn ($scope) => $authUser?->hasPermission('property_reports.scope.'.$scope))) {
            $query->whereRaw('1 = 0');
            return;
        }
        if ($authUser?->usesAssignedGroupScope()) {
            $access = app(\App\Support\RopGroupAccess::class);
            $isBooking = $query->getModel() instanceof Booking;
            $groupColumn = $isBooking ? 'bookings.branch_group_id' : 'properties.branch_group_id';
            $access->scope($query, $authUser, $groupColumn, $isBooking ? null : 'properties.branch_id');
            if ($request->filled('branch_group_id')) {
                $query->whereIn($groupColumn, $this->toArray($request->input('branch_group_id')));
            }
            if ($isBooking && $request->hasAny([
                'type_id', 'status_id', 'location_id', 'repair_type_id', 'currency', 'offer_type',
                'listing_type', 'contract_type_id', 'moderation_status', 'district',
                'sold_at_from', 'sold_at_to', 'deposit_received_at_from', 'deposit_received_at_to',
                'priceFrom', 'priceTo', 'roomsFrom', 'roomsTo', 'total_areaFrom', 'total_areaTo',
                'living_areaFrom', 'living_areaTo', 'floorFrom', 'floorTo', 'total_floorsFrom',
                'total_floorsTo', 'year_builtFrom', 'year_builtTo',
            ])) {
                // Filtering by a related property's private attributes must not reveal a foreign property.
                $query->whereIn('properties.id', $access->scope(Property::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q)), $authUser,
                    'properties.branch_group_id', 'properties.branch_id')->select('properties.id'));
            }
            return;
        }


        if ($query->getModel() instanceof Booking) {
            $this->applyBranchAccessByUserColumn($request, $query, $creatorColumn);
            return;
        }

        if ($request->filled('branch_group_id')) {
            $branchGroupIds = array_values(array_filter(
                array_map('intval', $this->toArray($request->input('branch_group_id')))
            ));

            if (!empty($branchGroupIds)) {
                $this->applyPropertyBranchGroupFilter($query, $branchGroupIds);
            }
        }

        // Явный фильтр по филиалу для админских/общих отчётов
        if ($request->filled('branch_id')) {
            $branchIds = $this->toArray($request->input('branch_id'));
            if (!empty($branchIds)) {
                // An explicit report filter follows the credited employee. The
                // mandatory record access restrictions below remain independent.
                $query->whereIn($reportOwnerExpression ? DB::raw($reportOwnerExpression) : $creatorColumn,
                    User::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->whereIn('branch_id', $branchIds)->select('id'));
            }
        }

        // Принудительное ограничение для branch-scoped ролей: только свой филиал
        if ($authUser?->hasPermission('property_reports.scope.branch')) {
            if (empty($authUser->branch_id)) {
                $query->whereRaw('1 = 0');
                return;
            }

            $query->whereIn($creatorColumn, User::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->where('branch_id', $authUser->branch_id)->select('id'));
        }

        if ($authUser?->hasPermission('property_reports.scope.group')) {
            if (empty($authUser->branch_group_id)) {
                $query->whereRaw('1 = 0');
                return;
            }

            $this->applyPropertyBranchGroupFilter($query, [(int) $authUser->branch_group_id]);
        }
    }

    public function applyBranchAccessByUserColumn(Request $request, $query, string|\Illuminate\Database\Query\Expression $userColumn): void
    {
        $this->validateReportScope($request);

        $authUser = auth()->user();
        $authUser?->loadMissing('role');
        if (! collect(['all', 'branch', 'group'])->contains(fn ($scope) => $authUser?->hasPermission('property_reports.scope.'.$scope))) {
            $query->whereRaw('1 = 0');
            return;
        }
        if ($authUser?->usesAssignedGroupScope()) {
            $table = $query instanceof \Illuminate\Database\Eloquent\Builder ? $query->getModel()->getTable() : $query->from;
            abort_unless(in_array($table, ['users', 'clients', 'bookings'], true), 403, 'GROUP_SCOPE_UNAVAILABLE');
            app(\App\Support\RopGroupAccess::class)->scope($query, $authUser, $table.'.branch_group_id', $table === 'bookings' ? null : $table.'.branch_id');
            if ($table === 'users') {
                $query->whereIn('users.id', app(\App\Support\RopGroupAccess::class)->employees($authUser)->select('users.id'));
            }
            if ($request->filled('branch_group_id')) {
                $query->whereIn($table.'.branch_group_id', $this->toArray($request->input('branch_group_id')));
            }
            return;
        }


        if ($request->filled('branch_group_id')) {
            $branchGroupIds = $this->toArray($request->input('branch_group_id'));
            if (!empty($branchGroupIds)) {
                if (!Schema::hasColumn('users', 'branch_group_id')) {
                    $query->whereRaw('1 = 0');
                    return;
                }

                $query->whereIn($userColumn, User::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->whereIn('branch_group_id', $branchGroupIds)->select('id'));
            }
        }

        if ($request->filled('branch_id')) {
            $branchIds = $this->toArray($request->input('branch_id'));
            if (!empty($branchIds)) {
                $query->whereIn($userColumn, User::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->whereIn('branch_id', $branchIds)->select('id'));
            }
        }

        if ($authUser?->hasPermission('property_reports.scope.branch')) {
            if (empty($authUser->branch_id)) {
                $query->whereRaw('1 = 0');
                return;
            }

            $query->whereIn($userColumn, User::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->where('branch_id', $authUser->branch_id)->select('id'));
        }

        if ($authUser?->hasPermission('property_reports.scope.group')) {
            if (empty($authUser->branch_group_id)) {
                $query->whereRaw('1 = 0');
                return;
            }

            if (!Schema::hasColumn('users', 'branch_group_id')) {
                $query->whereRaw('1 = 0');
                return;
            }

            $query->whereIn($userColumn, User::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->where('branch_group_id', $authUser->branch_group_id)->select('id'));
        }
    }

    public function applyPropertyBranchGroupFilter($query, array $branchGroupIds): void
    {
        $branchGroupIds = array_values(array_filter(array_map('intval', $branchGroupIds)));

        if (empty($branchGroupIds) || !Schema::hasColumn('users', 'branch_group_id')) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function ($branchGroupQuery) use ($branchGroupIds) {
            $hasPropertyBranchGroupId = Schema::hasColumn('properties', 'branch_group_id');

            if ($hasPropertyBranchGroupId) {
                $branchGroupQuery->whereIn('properties.branch_group_id', $branchGroupIds);
            }

            $branchGroupQuery->orWhere(function ($agentQuery) use ($branchGroupIds, $hasPropertyBranchGroupId) {
                if ($hasPropertyBranchGroupId) {
                    $agentQuery->whereNull('properties.branch_group_id');
                }

                $agentQuery
                    ->whereNotNull('properties.agent_id')
                    ->whereIn('properties.agent_id', User::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))
                        ->whereIn('branch_group_id', $branchGroupIds)
                        ->select('id'));
            });

            $branchGroupQuery->orWhere(function ($creatorQuery) use ($branchGroupIds, $hasPropertyBranchGroupId) {
                if ($hasPropertyBranchGroupId) {
                    $creatorQuery->whereNull('properties.branch_group_id');
                }

                $creatorQuery
                    ->whereNotNull('properties.created_by')
                    ->where(function ($agentFallbackQuery) {
                        $agentFallbackQuery
                            ->whereNull('properties.agent_id')
                            ->orWhereNotIn('properties.agent_id', User::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))
                                ->whereNotNull('branch_group_id')
                                ->select('id'));
                    })
                    ->whereIn('properties.created_by', User::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))
                        ->whereIn('branch_group_id', $branchGroupIds)
                        ->select('id'));
            });
        });
    }

    public function propertyAuthorExpression(): string
    {
        return 'COALESCE(NULLIF(properties.created_by, 0), NULLIF(properties.agent_id, 0))';
    }

    public function applyCommonFilters(Request $request, $query, string $creatorColumn = 'created_by')
    {
        $this->ensureReportsAllowed($request);
        $this->validateReportScope($request);

        // Диапазон дат и поле даты
        // created_at | updated_at | sold_at
        $dateField = $request->input('date_field', null);

        // Если не указано поле даты и фильтруем по закрытым статусам — используем sold_at
        if ($dateField === null) {
            $statusVals = $this->toArray($request->input('moderation_status'));
            $closed = ['sold', 'rented', 'sold_by_owner'];
            $dateField = array_intersect($statusVals, $closed) ? 'sold_at' : 'created_at';
        }

        $allowedDateFields = [
            'created_at',
            'updated_at',
            'sold_at',
            'deposit_received_at',
            'bookings.created_at',
            'properties.created_at',
        ];

        if (!in_array($dateField, $allowedDateFields, true)) {
            $dateField = 'created_at';
        }
        $qualifiedDateField = str_contains($dateField, '.') ? $dateField : 'properties.'.$dateField;

        $dateFrom = $request->input('date_from'); // '2025-01-01'
        $dateTo   = $request->input('date_to');   // '2025-01-31'

        $soldFrom = $request->input('sold_at_from');
        $soldTo   = $request->input('sold_at_to');
        if ($dateFrom) $query->whereDate($qualifiedDateField, '>=', $dateFrom);
        if ($dateTo)   $query->whereDate($qualifiedDateField, '<=', $dateTo);

        if ($soldFrom || $soldTo) {
//            $query->whereIn('moderation_status', ['sold','rented','sold_by_owner']);

            if ($soldFrom) $query->whereDate('properties.sold_at', '>=', $soldFrom);
            if ($soldTo)   $query->whereDate('properties.sold_at', '<=', $soldTo);
        }

        $depositFrom = $request->input('deposit_received_at_from');
        $depositTo   = $request->input('deposit_received_at_to');
        if ($depositFrom) {
            $query->whereDate('properties.deposit_received_at', '>=', $depositFrom);
        }
        if ($depositTo) {
            $query->whereDate('properties.deposit_received_at', '<=', $depositTo);
        }

        // Мультиселекты (включая agent_id для отчётов по агентам)
        $multiFields = [
            'type_id', 'status_id', 'location_id', 'repair_type_id',
            'currency', 'offer_type', 'listing_type', 'contract_type_id',
            'created_by', 'moderation_status', 'district', 'document_type_id'
        ];
        foreach ($multiFields as $f) {
            if ($request->has($f)) {
                $vals = $this->toArray($request->input($f));
                if (!empty($vals)) {
                    $column = $f === 'created_by' ? $creatorColumn : $f;
                    if ($f === 'moderation_status' && in_array('unknown', $vals, true)) {
                        $query->where(fn ($q) => $q->whereIn($column, $vals)->orWhereNull($column)->orWhere($column, ''));
                    } else {
                        $query->whereIn($column, $vals);
                    }
                }
            }
        }

        // Фильтр по агенту:
        // - для закрытых сделок используем единый приоритет продавца SalesAttributionService
        // - для остальных случаев сравниваем по целевой creator-колонке
        if ($request->filled('agent_id')) {
            $agentIds = array_map('intval', $this->toArray($request->input('agent_id')));
            if (!empty($agentIds)) {
                $closedStatuses = ['sold', 'rented', 'sold_by_owner'];
                $statusVals = $this->toArray($request->input('moderation_status'));
                $isSalesColumn = in_array($creatorColumn, ['sale_user_id', 'sale_agent_id'], true);

                $qualifiedCreatorColumn = in_array($creatorColumn, ['created_by', 'properties.created_by'], true)
                    ? DB::raw($this->propertyAuthorExpression())
                    : (str_contains($creatorColumn, '.') ? $creatorColumn : 'properties.'.$creatorColumn);
                $salesUserExpression = $this->salesAttributionService->primarySellerExpression();
                $applySalesFilter = fn ($salesFilter) => $salesFilter->whereIn(DB::raw($salesUserExpression), $agentIds);

                $containsClosed = $statusVals === [] || array_intersect($statusVals, $closedStatuses) !== [];
                $containsOpen = $statusVals === [] || array_diff($statusVals, $closedStatuses) !== [];

                if (str_starts_with($creatorColumn, 'bookings.')) {
                    $query->whereIn($creatorColumn, $agentIds);
                } elseif ($isSalesColumn || ($containsClosed && !$containsOpen)) {
                    $query->where($applySalesFilter);
                } elseif ($containsClosed && $containsOpen) {
                    $query->where(function ($mixedAgentFilter) use ($agentIds, $closedStatuses, $qualifiedCreatorColumn, $applySalesFilter) {
                        $mixedAgentFilter
                            ->where(function ($openAgentFilter) use ($agentIds, $closedStatuses, $qualifiedCreatorColumn) {
                                $openAgentFilter->where(fn ($status) => $status->whereNotIn('properties.moderation_status', $closedStatuses)->orWhereNull('properties.moderation_status'))
                                    ->whereIn($qualifiedCreatorColumn, $agentIds);
                            })
                            ->orWhere(function ($closedAgentFilter) use ($closedStatuses, $applySalesFilter) {
                                $closedAgentFilter->whereIn('properties.moderation_status', $closedStatuses)
                                    ->where($applySalesFilter);
                            });
                    });
                } else {
                    $query->whereIn($qualifiedCreatorColumn, $agentIds);
                }
            }
        }

        $scopeColumn = in_array($creatorColumn, ['sale_user_id', 'sale_agent_id'], true)
            ? 'created_by'
            : $creatorColumn;

        $reportOwnerExpression = $query->getModel() instanceof Property
            ? "CASE WHEN properties.moderation_status IN ('sold', 'rented', 'sold_by_owner') THEN "
                .$this->salesAttributionService->primarySellerExpression().' ELSE '.$this->propertyAuthorExpression().' END'
            : null;
        $this->applyBranchAccessFilter($request, $query, $scopeColumn, $reportOwnerExpression);

        // Диапазоны
        foreach ([
                     'price' => 'price',
                     'rooms' => 'rooms',
                     'total_area' => 'total_area',
                     'living_area' => 'living_area',
                     'floor' => 'floor',
                     'total_floors' => 'total_floors',
                     'year_built' => 'year_built',
                 ] as $param => $col) {
            $from = $request->input($param . 'From');
            $to = $request->input($param . 'To');
            if ($from !== null && $from !== '') $query->where($col, '>=', $from);
            if ($to !== null && $to !== '') $query->where($col, '<=', $to);
        }

        return [$query, $dateField];
    }

    public function validateReportScope(Request $request): void
    {
        /** @var User|null $authUser */
        $authUser = $request->user();

        if (! $authUser) {
            return;
        }

        if (! $authUser->usesAssignedGroupScope() && ! $authUser->hasPermission('property_reports.scope.branch')) {
            return;
        }

        if (! $request->filled('branch_id') && ! empty($authUser->branch_id)) {
            $request->merge(['branch_id' => (string) $authUser->branch_id]);
        }

        if ($request->filled('branch_id')) {
            foreach ($this->toArray($request->input('branch_id')) as $branchId) {
                $this->branchScope->ensureSameBranchOrDeny((int) $branchId, $authUser);
            }
        }

        if ($request->filled('branch_group_id')) {
            foreach ($this->toArray($request->input('branch_group_id')) as $branchGroupId) {
                $belongs = BranchGroup::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))
                    ->whereKey((int) $branchGroupId)
                    ->where('branch_id', $authUser->branch_id)
                    ->exists();

                if (! $belongs) {
                    $this->branchScope->denyBranchScopeViolation();
                }
            }
        }

        if ($request->filled('agent_id')) {
            $agentIds = array_map('intval', $this->toArray($request->input('agent_id')));

            if (! empty($agentIds)) {
                $count = User::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))
                    ->whereIn('id', $agentIds)
                    ->where('branch_id', $authUser->branch_id)
                    ->count();

                if ($count !== count(array_unique($agentIds))) {
                    $this->branchScope->denyBranchScopeViolation();
                }
            }
        }
    }
}
