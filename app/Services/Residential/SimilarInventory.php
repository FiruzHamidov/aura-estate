<?php

namespace App\Services\Residential;

use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use Illuminate\Database\Eloquent\Builder;

final class SimilarInventory
{
    public function __construct(private readonly InventoryQuery $inventory, private readonly PublicInventory $public) {}

    public function units(NewBuilding $building, DeveloperUnit $source): array
    {
        $rooms = InventoryStatus::rooms($source->getAttributes());
        $city = $building->location?->city;
        $base = DeveloperUnit::query()->available()->whereKeyNot($source->id)->with(['block', 'entrance', 'coverPhoto']);
        if ($rooms !== null) {
            $base->whereRaw(InventoryQuery::ROOMS_SQL.' = ?', [$rooms]);
        }
        $results = collect();
        $tiers = [
            ['key' => 'same_complex', 'area' => .2, 'price' => .25, 'city' => false, 'label' => 'Тот же ЖК: такая же комнатность, площадь ±20%, цена ±25%.'],
            ['key' => 'expanded_complex', 'area' => .4, 'price' => .4, 'city' => false, 'label' => 'Расширенный поиск в том же ЖК: такая же комнатность, площадь и цена ±40%.'],
            ['key' => 'same_city', 'area' => .2, 'price' => .25, 'city' => true, 'label' => 'Другие ЖК того же города: такая же комнатность, площадь ±20%, цена ±25%.'],
            ['key' => 'expanded', 'area' => .4, 'price' => .4, 'city' => true, 'label' => 'Расширенный поиск по городу: такая же комнатность, площадь и цена ±40%.'],
        ];
        foreach ($tiers as $tier) {
            if ($results->count() >= 6 || ($tier['city'] && ! $city)) {
                continue;
            }
            $query = clone $base;
            $query->whereNotIn('developer_units.id', $results->pluck('id')->all());
            if ($tier['city']) {
                $query->whereHas('newBuilding.location', fn (Builder $location) => $location->where('city', $city));
                $query->where('developer_units.new_building_id', '!=', $building->id);
            } else {
                $query->where('developer_units.new_building_id', $building->id);
            }
            if ((float) $source->area > 0) {
                $query->whereBetween('developer_units.area', [(float) $source->area * (1 - $tier['area']), (float) $source->area * (1 + $tier['area'])]);
            }
            if ((float) $source->total_price > 0) {
                $query->whereBetween('developer_units.total_price', [(float) $source->total_price * (1 - $tier['price']), (float) $source->total_price * (1 + $tier['price'])]);
            }
            $found = $query->orderByRaw('ABS(developer_units.area - ?)', [(float) $source->area])->orderBy('developer_units.id')->limit(6 - $results->count())->get();
            foreach ($found as $unit) {
                $results->push($this->public->unit($unit) + ['similarity_rule' => $tier['key']]);
            }
        }

        return ['data' => $results->all(), 'rules' => $tiers, 'relaxations' => ['rooms_not_confirmed' => $rooms === null, 'price_unknown' => ! ((float) $source->total_price > 0), 'city_unknown' => ! $city], 'as_of' => now()->toIso8601String()];
    }

    public function buildings(NewBuilding $source): array
    {
        $city = $source->location?->city;
        if (! $city) {
            return ['data' => [], 'rule' => 'Город не подтверждён: похожие ЖК не подбираются по вымышленной локации.'];
        }
        $query = $this->inventory->withAggregates($this->inventory->buildings([]))->whereKeyNot($source->id)
            ->whereHas('location', fn (Builder $location) => $location->where('city', $city))
            ->whereHas('units', fn (Builder $units) => $units->available())->with(['coverPhoto', 'developer', 'location']);
        $records = $query->orderByDesc('published_at')->orderBy('new_buildings.id')->limit(6)->get();

        return ['data' => $records->map(fn ($building) => $this->public->building($building)), 'rule' => 'Опубликованные ЖК того же города со свободными квартирами. Сначала недавно опубликованные.'];
    }
}
