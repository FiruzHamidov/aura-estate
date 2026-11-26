<?php

namespace App\Services\Kpi;

use App\Models\Property;
use App\Models\User;
use App\Services\SalesAttributionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ResultFactService
{
    public const CLOSING_FIELDS = [
        'moderation_status', 'sold_at', 'sale_agent_id', 'sale_user_id', 'actual_sale_price',
        'actual_sale_currency', 'rental_monthly_amount', 'rental_contract_amount', 'deal_fx_rate',
        'company_commission_amount', 'company_commission_currency', 'agent_id', 'created_by',
    ];

    /** Append revisions. Reopening never deletes the original closure or issued rewards. */
    public function record(Property $property, string $event, ?int $actorId = null, bool $deleted = false, bool $financialChangeOnly = false): void
    {
        if (! Schema::hasTable('kpi_result_facts')) {
            return;
        }

        DB::transaction(function () use ($property, $event, $actorId, $deleted, $financialChangeOnly) {
            DB::table('properties')->where('id', $property->id)->lockForUpdate()->first();
            if (DB::table('kpi_result_facts')->where('source_event', $event)->exists()) {
                return;
            }
            $previous = DB::table('kpi_result_facts')->where('property_id', $property->id)->latest('id')->first();
            $active = ! $deleted && in_array($property->moderation_status, ['sold', 'rented'], true);
            if (! $active && ! $previous) {
                return;
            }
            $financials = Schema::hasColumn('kpi_result_facts', 'participant_commissions') ? $this->financialSnapshot($property) : [];
            if ($financialChangeOnly && $previous) {
                if (! $active) {
                    return; // The property observer owns reversals.
                }
                $changed = false;
                foreach ($financials as $key => $value) {
                    $before = $previous->{$key} ?? null;
                    $changed = $changed || (($before === null) !== ($value === null))
                        || ($key === 'participant_commissions'
                            ? json_decode($before ?? 'null', true) !== json_decode($value ?? 'null', true)
                            : $before != $value);
                }
                if (! $changed) {
                    return;
                }
            }
            $sellerId = app(SalesAttributionService::class)->primarySellerId($property);
            $seller = $sellerId ? User::find($sellerId) : null;
            $fact = [
                'property_id' => $property->id, 'source_event' => $event,
                'supersedes_id' => $previous?->id, 'user_id' => $active ? ($sellerId ?: null) : $previous?->user_id,
                // Corrections retain attribution scope unless the seller changes explicitly.
                'branch_id' => $previous && $previous->state === 'active' && (int) $previous->user_id === $sellerId ? $previous->branch_id : $seller?->branch_id,
                'branch_group_id' => $previous && $previous->state === 'active' && (int) $previous->user_id === $sellerId ? $previous->branch_group_id : $seller?->branch_group_id,
                'kind' => $active ? ($property->moderation_status === 'sold' ? 'sale' : 'rental') : $previous->kind,
                'state' => $active ? 'active' : 'reversed',
                'closed_at' => $property->sold_at ? CarbonImmutable::parse($property->sold_at, (string) config('app.timezone', 'Asia/Dushanbe'))->utc()->toDateTimeString() : $previous?->closed_at,
                'amount' => $property->actual_sale_price,
                'rental_monthly_amount' => $property->rental_monthly_amount,
                'rental_contract_amount' => $property->rental_contract_amount,
                'currency' => $property->actual_sale_currency,
                'fx_rate' => $property->deal_fx_rate,
                'recorded_by' => $actorId, 'reason' => mb_substr((string) $property->status_comment, 0, 500) ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ] + $financials;
            if (Schema::hasColumn('kpi_result_facts', 'sale_credits')) {
                $ownerId = app(SalesAttributionService::class)->listingAgentId($property);
                $users = User::whereIn('id', [$sellerId, $ownerId])->get()->keyBy('id');
                $fact['sale_credits'] = $active && $fact['kind'] === 'sale'
                    ? json_encode($this->saleCreditRows($property, (object) $fact, $users, $previous), JSON_THROW_ON_ERROR)
                    : null;
            }
            DB::table('kpi_result_facts')->insert($fact);
            if (Schema::hasTable('agent_rating_state')) {
                DB::table('agent_rating_state')->where('key', 'synced_at')->update(['value' => 0]);
            }
        });
    }

    public function currentQuery()
    {
        return DB::table('kpi_result_facts as facts')->whereNotExists(function ($query) {
            $query->selectRaw('1')->from('kpi_result_facts as newer')
                ->whereColumn('newer.property_id', 'facts.property_id')->whereColumn('newer.id', '>', 'facts.id');
        });
    }

    private function financialSnapshot(object $property, ?Collection $participants = null): array
    {
        $participants ??= Schema::hasTable('property_agent_sales')
            ? DB::table('property_agent_sales')->where('property_id', $property->id)->orderBy('agent_id')->get()
            : collect();
        $snapshot = $participants->map(fn ($row) => ['user_id' => $row->agent_id,
            'amount' => $row->agent_commission_amount ?? null, 'currency' => $row->agent_commission_currency ?? null])->all();

        return ['company_commission_amount' => $property->company_commission_amount ?? null,
            'company_commission_currency' => $property->company_commission_currency ?? null,
            'participant_commissions' => json_encode($snapshot, JSON_THROW_ON_ERROR)];
    }

    /** Legacy rows remain visible; ambiguous rental amounts are deliberately unknown. */
    public function forPeriod(string $from, string $to, bool $lock = false, bool $includeUndated = false): Collection
    {
        $start = CarbonImmutable::parse($from, 'Asia/Dushanbe')->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, 'Asia/Dushanbe')->addDay()->startOfDay()->utc();
        $hasLedger = Schema::hasTable('kpi_result_facts');
        $facts = $hasLedger ? $this->currentQuery()->where('state', 'active')
            ->where(function ($q) use ($start, $end, $includeUndated) {
                $q->where(fn ($range) => $range->where('closed_at', '>=', $start)->where('closed_at', '<', $end));
                if ($includeUndated) $q->orWhereNull('closed_at');
            })->when($lock, fn ($q) => $q->lockForUpdate())->get() : collect();
        if (! Schema::hasTable('properties')) {
            return $this->withSalesCredits($facts);
        }
        $storageTimezone = (string) config('app.timezone', 'Asia/Dushanbe');
        $legacy = DB::table('properties')->whereIn('moderation_status', ['sold', 'rented'])
            ->where(function ($q) use ($start, $end, $storageTimezone, $includeUndated) {
                $q->where(fn ($range) => $range->where('sold_at', '>=', $start->setTimezone($storageTimezone)->toDateTimeString())
                    ->where('sold_at', '<', $end->setTimezone($storageTimezone)->toDateTimeString()));
                if ($includeUndated) $q->orWhereNull('sold_at');
            });
        if ($hasLedger) {
            $legacy->whereNotIn('id', DB::table('kpi_result_facts')->select('property_id'));
        }
        if (Schema::hasColumn('properties', 'deleted_at')) {
            $legacy->whereNull('deleted_at');
        }
        $properties = $legacy->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $attribution = app(SalesAttributionService::class);
        $users = User::whereIn('id', $properties->map(fn ($p) => $attribution->primarySellerId($p)))->get()->keyBy('id');
        $includeFinancials = $hasLedger && Schema::hasColumn('kpi_result_facts', 'participant_commissions');
        $participants = $includeFinancials && Schema::hasTable('property_agent_sales')
            ? DB::table('property_agent_sales')->whereIn('property_id', $properties->pluck('id'))->orderBy('agent_id')->get()->groupBy('property_id')
            : collect();

        $facts = $facts->concat($properties->map(function ($property) use ($users, $attribution, $storageTimezone, $participants, $includeFinancials) {
            $userId = $attribution->primarySellerId($property);

            return (object) ([
                'id' => null, 'source_event' => 'legacy-property-'.$property->id,
                'property_id' => $property->id, 'user_id' => $userId,
                'branch_id' => $users->get($userId)?->branch_id, 'branch_group_id' => $users->get($userId)?->branch_group_id,
                'kind' => $property->moderation_status === 'sold' ? 'sale' : 'rental',
                'state' => 'active', 'closed_at' => $property->sold_at ? CarbonImmutable::parse($property->sold_at, $storageTimezone)->utc()->toDateTimeString() : null,
                'amount' => $property->actual_sale_price ?? null, 'currency' => $property->actual_sale_currency ?? null,
                'rental_monthly_amount' => $property->rental_monthly_amount ?? null,
                'rental_contract_amount' => $property->rental_contract_amount ?? null,
                'fx_rate' => $property->deal_fx_rate ?? null, 'legacy_scope' => true,
            ] + ($includeFinancials ? $this->financialSnapshot($property, $participants->get($property->id, collect())) : []));
        }))->values();

        return $this->withSalesCredits($facts);
    }

    /** Expand each sale into its KPI shares before applying employee/group filters. */
    private function withSalesCredits(Collection $facts): Collection
    {
        $legacySales = $facts->filter(fn ($fact) => $fact->kind === 'sale' && ($fact->sale_credits ?? null) === null);
        $attribution = app(SalesAttributionService::class);
        $properties = $legacySales->isNotEmpty() && Schema::hasTable('properties')
            ? DB::table('properties')->whereIn('id', $legacySales->pluck('property_id'))
                ->get(['id', ...$attribution->sellerColumns()])->keyBy('id') : collect();
        $users = $properties->isNotEmpty()
            ? User::whereIn('id', $properties->map(fn ($property) => $attribution->listingAgentId($property)))->get()->keyBy('id')
            : collect();

        return $facts->flatMap(function ($fact) use ($properties, $users) {
            if ($fact->kind !== 'sale') {
                return [$fact];
            }
            $credits = json_decode($fact->sale_credits ?? 'null', true);
            if ($credits === null && ($property = $properties->get($fact->property_id))) {
                // Older facts have no attribution snapshot; retain their recorded seller.
                $property = (object) array_replace((array) $property, [
                    'sale_agent_id' => $fact->user_id, 'sale_user_id' => $fact->user_id,
                    'moderation_status' => 'sold',
                ]);
                $credits = $this->saleCreditRows($property, $fact, $users);
            }
            if (! $credits) {
                return [$fact];
            }

            return array_map(fn ($credit) => (object) array_replace((array) $fact, $credit, [
                // Counts are shared. The existing financial result belongs to the seller.
                'amount_credit' => $credit['credit_role'] === 'listing_agent' ? 0 : 1,
                'legacy_scope' => ($fact->legacy_scope ?? false)
                    || (($fact->sale_credits ?? null) === null && $credit['credit_role'] === 'listing_agent'),
            ]), $credits);
        })->values();
    }

    private function saleCreditRows(object $property, object $fact, Collection $users, ?object $previous = null): array
    {
        $service = app(SalesAttributionService::class);
        $credits = $service->kpiCreditsByProperty(collect([$property]))[(int) $property->id] ?? [];
        $old = $previous && $previous->state === 'active'
            ? collect(json_decode($previous->sale_credits ?? '[]', true))->keyBy('user_id') : collect();
        $rows = [];
        foreach ($credits as $userId => $credit) {
            $seller = (int) $userId === (int) $fact->user_id;
            $prior = $old->get($userId);
            $rows[] = [
                'user_id' => (int) $userId,
                'branch_id' => $seller ? $fact->branch_id : ($prior ? $prior['branch_id'] : $users->get($userId)?->branch_id),
                'branch_group_id' => $seller ? $fact->branch_group_id : ($prior ? $prior['branch_group_id'] : $users->get($userId)?->branch_group_id),
                'sale_credit' => $credit, 'credit_role' => $seller ? 'seller' : 'listing_agent',
            ];
        }

        return $rows;
    }

    public function amount(object $fact, string $currency, ?string $rentalBasis, string $basis = 'deal_value'): ?float
    {
        if (($fact->amount_credit ?? 1) === 0) {
            return 0.0;
        }

        return ResultAmount::calculate($fact, $currency, $rentalBasis, $basis);
    }
}
