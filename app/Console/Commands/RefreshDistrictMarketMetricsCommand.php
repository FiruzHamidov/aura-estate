<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Services\PropertyLiquidity\PropertyMarketDays;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RefreshDistrictMarketMetricsCommand extends Command
{
    protected $signature = 'properties:refresh-liquidity-market';

    protected $description = 'Refresh daily district market aggregates used by liquidity reporting';

    public function handle(PropertyMarketDays $marketDays): int
    {
        $date = now()->toDateString();
        $segments = Property::query()
            ->whereNotNull('district_id')
            ->where('offer_type', 'sale')
            ->where('created_at', '>=', now()->subMonths((int) config('property-liquidity.lookback_months', 12)))
            ->select(['location_id', 'district_id', 'type_id', 'rooms', 'is_from_developer', 'currency'])
            ->distinct()
            ->get();

        foreach ($segments as $segment) {
            $base = fn (): Builder => Property::query()
                ->where('location_id', $segment->location_id)
                ->where('district_id', $segment->district_id)
                ->where('type_id', $segment->type_id)
                ->where('rooms', $segment->rooms)
                ->where('is_from_developer', (bool) $segment->is_from_developer)
                ->where('currency', $segment->currency)
                ->where('offer_type', 'sale')
                ->where('created_at', '>=', now()->subMonths((int) config('property-liquidity.lookback_months', 12)));

            $active = $base()->where('moderation_status', Property::PUBLIC_MODERATION_STATUS)->whereNull('sold_at')->get();
            $sold = $base()->with('statusHistory')->where('moderation_status', 'sold')->whereNotNull('sold_at')->get();
            $priceSamples = $active->merge($sold)->map(function (Property $property): ?float {
                $price = $property->sold_at && (float) $property->actual_sale_price > 0
                    ? (float) $property->actual_sale_price
                    : ((float) $property->discount_price > 0 ? (float) $property->discount_price : (float) $property->price);

                return (float) $property->total_area > 0 ? $price / (float) $property->total_area : null;
            })->filter()->values();
            $dom = $sold->map(fn (Property $property): ?int => $marketDays->calculate($property))
                ->filter(fn (?int $days) => $days !== null)
                ->values();
            $soldCount = $sold->count();
            $activeCount = $active->count();

            DB::table('district_market_metrics')->updateOrInsert([
                'metric_date' => $date,
                'district_id' => $segment->district_id,
                'property_type_id' => $segment->type_id,
                'rooms' => $segment->rooms,
                'is_from_developer' => (bool) $segment->is_from_developer,
                'currency' => $segment->currency,
            ], [
                'location_id' => $segment->location_id,
                'active_count' => $activeCount,
                'sold_count' => $soldCount,
                'median_dom' => $dom->isEmpty() ? null : (int) round((float) $dom->median()),
                'median_price_sqm' => $priceSamples->isEmpty() ? null : round((float) $priceSamples->median(), 2),
                'sell_through_rate' => round($soldCount / max(1, $soldCount + $activeCount), 4),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->info("Updated {$segments->count()} district market segments.");

        return self::SUCCESS;
    }
}
