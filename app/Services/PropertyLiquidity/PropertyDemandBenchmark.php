<?php

namespace App\Services\PropertyLiquidity;

use App\Models\ClientNeed;
use App\Models\Property;
use App\Models\User;
use App\Support\RopGroupAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class PropertyDemandBenchmark
{
    // Request/job-local only: never share a ROP's benchmark across actors or requests.
    private array $contexts = [];

    public function score(Property $property, bool $usesRooms, ?User $actor): array
    {
        $key = implode(':', [$actor?->id ?? 'global', $property->location_id, $property->currency,
            $property->type_id, $usesRooms ? $property->rooms : '-',
            $property->type?->slug === 'apartment' ? (int) $property->is_from_developer : '-']);
        if (! isset($this->contexts[$key])) {
            $needs = ClientNeed::query()
                ->when($actor, fn (Builder $query) => $query->whereHas('client', fn (Builder $clients) => app(RopGroupAccess::class)->scope($clients, $actor, 'clients.branch_group_id', 'clients.branch_id')))
                ->where('updated_at', '>=', now()->subDays((int) config('property-liquidity.demand_freshness_days', 90)))
                ->whereNull('closed_at')->where('currency', $property->currency)
                ->where('location_id', $property->location_id)
                ->where(fn (Builder $query) => $query->whereNotNull('district_id')->orWhere(fn (Builder $named) => $named->whereNotNull('district')->where('district', '!=', '')))
                ->whereHas('type', fn (Builder $query) => $query->whereIn('slug', ['buy', 'invest']))
                ->whereHas('status', fn (Builder $query) => $query->where('is_closed', false))
                ->when(Schema::hasTable('client_need_property_type'), fn (Builder $query) => $query->with('propertyTypes'))
                ->orderByDesc('updated_at')->get()->map(function (ClientNeed $need) {
                    $row = $need->getAttributes();
                    $row['types'] = $need->relationLoaded('propertyTypes') ? $need->propertyTypes->modelKeys() : [];
                    if ($row['types'] === [] && $need->property_type_id) $row['types'] = [(int) $need->property_type_id];
                    $age = $need->updated_at->diffInDays(now());
                    $row['weight'] = $age <= 30 ? 1.0 : ($age <= 60 ? 0.7 : 0.4);

                    return $row;
                })->filter(fn ($need) => $need['types'] === [] || in_array((int) $property->type_id, $need['types'], true))->values()->all();

            $peers = Property::query()->publicSearchable()->where('offer_type', 'sale')->whereNull('sold_at')
                ->where('location_id', $property->location_id)->where('currency', $property->currency)
                ->where('type_id', $property->type_id)->where('total_area', '>', 0)
                ->when($usesRooms, fn (Builder $query) => $query->where('rooms', $property->rooms))
                ->when($property->type?->slug === 'apartment', fn (Builder $query) => $query->where('is_from_developer', (bool) $property->is_from_developer))
                ->when($actor, fn (Builder $query) => app(RopGroupAccess::class)->scope($query, $actor, 'properties.branch_group_id', 'properties.branch_id'))
                ->get(['id', 'price', 'discount_price', 'total_area', 'rooms', 'district_id', 'district'])->map->getAttributes()->all();
            $ratios = [];
            foreach ($peers as $peer) $ratios[] = ['area' => (float) $peer['total_area'], 'ratio' => $this->measure($peer, $peers, $needs, $usesRooms)[0]];
            $this->contexts[$key] = compact('needs', 'peers', 'ratios');
        }
        ['needs' => $needs, 'peers' => $peers, 'ratios' => $ratios] = $this->contexts[$key];
        [$ratio, $count] = $this->measure($property->getAttributes(), $peers, $needs, $usesRooms);
        $area = (float) $property->total_area;
        $ratios = array_column(array_filter($ratios, fn ($row) => $row['area'] >= $area * .8 && $row['area'] <= $area * 1.2), 'ratio');
        $available = count($ratios) >= 3 && count($needs) > 0;

        if (! in_array($property->id, array_column($peers, 'id'))) $ratios[] = $ratio;

        return [$available ? LiquidityMath::percentile($ratio, $ratios) : 50, $count, $available];
    }

    private function measure(array $property, array $peers, array $needs, bool $usesRooms): array
    {
        $districtMatches = static function (array $row) use ($property): bool {
            return ! empty($property['district_id'])
                ? (int) ($row['district_id'] ?? 0) === (int) $property['district_id']
                : mb_strtolower(trim((string) ($row['district'] ?? ''))) === mb_strtolower(trim((string) ($property['district'] ?? '')));
        };
        $price = (float) ($property['discount_price'] ?? 0) > 0 ? (float) $property['discount_price'] : (float) $property['price'];
        $area = (float) $property['total_area'];
        $clients = [];
        foreach ($needs as $need) {
            if (isset($clients[$need['client_id']]) || ! $districtMatches($need)) continue;
            $ceiling = $need['budget_to'] ?? $need['budget_total'] ?? null;
            if (($need['budget_from'] !== null && (float) $need['budget_from'] > $price * 1.10)
                || ($ceiling !== null && (float) $ceiling < $price * 0.90)
                || ($need['area_from'] !== null && (float) $need['area_from'] > $area)
                || ($need['area_to'] !== null && (float) $need['area_to'] < $area)
                || ($usesRooms && (($need['rooms_from'] !== null && (int) $need['rooms_from'] > (int) $property['rooms'])
                    || ($need['rooms_to'] !== null && (int) $need['rooms_to'] < (int) $property['rooms'])))) continue;
            $clients[$need['client_id']] = $need['weight'];
        }
        $supply = 0;
        foreach ($peers as $peer) {
            if ($districtMatches($peer) && (float) $peer['total_area'] >= $area * .8 && (float) $peer['total_area'] <= $area * 1.2) $supply++;
        }

        return [array_sum($clients) / max(1, $supply), count($clients)];
    }
}
