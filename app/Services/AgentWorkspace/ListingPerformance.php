<?php

namespace App\Services\AgentWorkspace;

use App\Services\SalesAttributionService;
use App\Support\BranchVisibility;
use App\Support\DealExchangeRate;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The moderation participant list is the authority for agent commissions. */
final class ListingPerformance
{
    public function forMonth(string $month): Collection
    {
        $zone = config('app.timezone', 'Asia/Dushanbe');
        $start = CarbonImmutable::parse($month.'-01', 'Asia/Dushanbe');
        $end = $start->addMonth()->min(CarbonImmutable::now('Asia/Dushanbe'));
        $query = DB::table('properties')->whereIn('moderation_status', ['sold', 'rented'])
            ->where(function ($q) use ($start, $end, $zone) {
                $q->whereNull('sold_at')->orWhere(fn ($q) => $q
                    ->where('sold_at', '>=', $start->setTimezone($zone)->toDateTimeString())
                    ->where('sold_at', '<', $end->setTimezone($zone)->toDateTimeString()));
            });
        if (Schema::hasColumn('properties', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        BranchVisibility::records($query, 'properties');
        $columns = array_intersect(['id', 'agent_id', 'created_by', 'sale_agent_id', 'sale_user_id',
            'moderation_status', 'sold_at', 'deal_fx_rate'], Schema::getColumnListing('properties'));
        $properties = $query->get(array_values($columns));
        $participants = Schema::hasTable('property_agent_sales')
            ? DB::table('property_agent_sales')->whereIn('property_id', $properties->pluck('id'))->orderBy('id')->get()->groupBy('property_id')
            : collect();
        $attribution = app(SalesAttributionService::class);
        $userIds = $participants->flatten(1)->pluck('agent_id')->merge($properties->map(fn ($p) => $attribution->primarySellerId($p)));
        $users = DB::table('users')->whereIn('id', $userIds)->get(['id', 'branch_id', 'branch_group_id'])->keyBy('id');

        return $properties->map(function ($property) use ($participants, $users, $attribution, $zone) {
            $members = $participants->get($property->id, collect());
            if ($members->isEmpty()) {
                // Legacy/rental closings still count; never invent an agent commission
                // from the company's income or from the property's asking price.
                $members = collect([(object) ['agent_id' => $attribution->primarySellerId($property), 'role' => 'main']]);
            }
            $rows = $members->unique('agent_id')->map(function ($member) use ($property, $users) {
                $amount = $member->agent_commission_amount ?? null;
                $currency = $member->agent_commission_currency ?? null;
                $minor = $this->commissionMinor($amount, $currency, $property->deal_fx_rate ?? null);
                $invalidFx = $currency === 'USD' && is_numeric($amount) && (float) $amount > 0
                    && isset($property->deal_fx_rate) && ! DealExchangeRate::isValid($property->deal_fx_rate);
                $user = $users->get($member->agent_id);

                return ['agent_id' => (int) $member->agent_id, 'role' => $member->role ?? 'assistant',
                    'branch_id' => $user?->branch_id, 'branch_group_id' => $user?->branch_group_id,
                    'commission' => $amount === null ? null : (string) $amount, 'currency' => $currency,
                    'credit_minor' => $minor, 'commission_issue' => $invalidFx ? 'invalid_fx_rate' : null];
            })->all();

            return ['transaction_id' => (int) $property->id, 'property_id' => (int) $property->id,
                'kind' => $property->moderation_status === 'sold' ? 'sale' : 'rental',
                'closed_at' => $property->sold_at ? CarbonImmutable::parse($property->sold_at, $zone)->utc()->toDateTimeString() : null,
                'fx_rate' => isset($property->deal_fx_rate) ? (string) $property->deal_fx_rate : null, 'participants' => $rows];
        });
    }

    private function commissionMinor(mixed $amount, ?string $currency, mixed $rate): ?int
    {
        if ($amount === null || ! in_array($currency, ['TJS', 'USD'], true)) {
            return null;
        }
        try {
            $value = BigDecimal::of((string) $amount);
            if ($value->isNegative()) {
                return null;
            }
            // A recorded zero is complete even without an exchange rate.
            if ($currency === 'USD' && ! $value->isZero()) {
                if (! DealExchangeRate::isValid($rate)) {
                    return null;
                }
                $fx = BigDecimal::of((string) $rate);
                $value = $value->multipliedBy($fx);
            }

            return $value->multipliedBy(100)->toScale(0, RoundingMode::HALF_UP)->toInt();
        } catch (MathException) {
            return null;
        }
    }

    public function matchesCategory(array $closing, array $participant, string $category): bool
    {
        return match ($category) {
            'rental' => $closing['kind'] === 'rental',
            'main', 'assistant', 'partner' => $participant['role'] === $category,
            default => true,
        };
    }
}
