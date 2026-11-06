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
    ];

    /** Append revisions. Reopening never deletes the original closure or issued rewards. */
    public function record(Property $property, string $event, ?int $actorId = null, bool $deleted = false): void
    {
        if (! Schema::hasTable('kpi_result_facts')) {
            return;
        }

        DB::transaction(function () use ($property, $event, $actorId, $deleted) {
            DB::table('properties')->where('id', $property->id)->lockForUpdate()->first();
            if (DB::table('kpi_result_facts')->where('source_event', $event)->exists()) {
                return;
            }
            $previous = DB::table('kpi_result_facts')->where('property_id', $property->id)->latest('id')->first();
            $active = ! $deleted && in_array($property->moderation_status, ['sold', 'rented'], true);
            if (! $active && ! $previous) {
                return;
            }
            $sellerId = app(SalesAttributionService::class)->primarySellerId($property);
            $seller = $sellerId ? User::find($sellerId) : null;
            DB::table('kpi_result_facts')->insert([
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
            ]);
        });
    }

    public function currentQuery()
    {
        return DB::table('kpi_result_facts as facts')->whereNotExists(function ($query) {
            $query->selectRaw('1')->from('kpi_result_facts as newer')
                ->whereColumn('newer.property_id', 'facts.property_id')->whereColumn('newer.id', '>', 'facts.id');
        });
    }

    /** Legacy rows remain visible; ambiguous rental amounts are deliberately unknown. */
    public function forPeriod(string $from, string $to, bool $lock = false): Collection
    {
        $start = CarbonImmutable::parse($from, 'Asia/Dushanbe')->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, 'Asia/Dushanbe')->addDay()->startOfDay()->utc();
        $hasLedger = Schema::hasTable('kpi_result_facts');
        $facts = $hasLedger ? $this->currentQuery()->where('state', 'active')
            ->where('closed_at', '>=', $start)->where('closed_at', '<', $end)->when($lock, fn ($q) => $q->lockForUpdate())->get() : collect();
        if (! Schema::hasTable('properties')) {
            return $facts;
        }
        $storageTimezone = (string) config('app.timezone', 'Asia/Dushanbe');
        $legacy = DB::table('properties')->whereIn('moderation_status', ['sold', 'rented'])
            ->where('sold_at', '>=', $start->setTimezone($storageTimezone)->toDateTimeString())
            ->where('sold_at', '<', $end->setTimezone($storageTimezone)->toDateTimeString());
        if ($hasLedger) {
            $legacy->whereNotIn('id', DB::table('kpi_result_facts')->select('property_id'));
        }
        if (Schema::hasColumn('properties', 'deleted_at')) {
            $legacy->whereNull('deleted_at');
        }
        $properties = $legacy->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $attribution = app(SalesAttributionService::class);
        $users = User::whereIn('id', $properties->map(fn ($p) => $attribution->primarySellerId($p)))->get()->keyBy('id');

        return $facts->concat($properties->map(function ($property) use ($users, $attribution, $storageTimezone) {
            $userId = $attribution->primarySellerId($property);

            return (object) [
                'id' => null, 'source_event' => 'legacy-property-'.$property->id,
                'property_id' => $property->id, 'user_id' => $userId,
                'branch_id' => $users->get($userId)?->branch_id, 'branch_group_id' => $users->get($userId)?->branch_group_id,
                'kind' => $property->moderation_status === 'sold' ? 'sale' : 'rental',
                'state' => 'active', 'closed_at' => CarbonImmutable::parse($property->sold_at, $storageTimezone)->utc()->toDateTimeString(),
                'amount' => $property->actual_sale_price ?? null, 'currency' => $property->actual_sale_currency ?? null,
                'rental_monthly_amount' => $property->rental_monthly_amount ?? null,
                'rental_contract_amount' => $property->rental_contract_amount ?? null,
                'fx_rate' => $property->deal_fx_rate ?? null, 'legacy_scope' => true,
            ];
        }))->values();
    }

    public function amount(object $fact, string $currency, ?string $rentalBasis): ?float
    {
        $value = $fact->kind === 'sale' ? $fact->amount : match ($rentalBasis) {
            'monthly_rent' => $fact->rental_monthly_amount,
            'contract_total' => $fact->rental_contract_amount,
            default => null,
        };
        if ($value === null || (float) $value <= 0 || ! in_array($fact->currency, ['USD', 'TJS'], true)) {
            return null;
        }
        if ($fact->currency === $currency) {
            return round((float) $value, 2);
        }
        if (! $fact->fx_rate || (float) $fact->fx_rate <= 0) {
            return null;
        }

        return round($fact->currency === 'USD' ? (float) $value * (float) $fact->fx_rate : (float) $value / (float) $fact->fx_rate, 2);
    }
}
