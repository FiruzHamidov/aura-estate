<?php

namespace App\Services\Residential;

use App\Models\DeveloperUnit;
use App\Models\Property;
use Illuminate\Support\Facades\Validator;

/** Whitelisted, batched public object resolver shared by saved lists and comparison. */
final class FavoriteObjects
{
    public const TYPES = ['property', 'new_building', 'developer_unit'];

    public function validate(array $input): array
    {
        return Validator::make($input, ['items' => 'required|array|max:200', 'items.*' => 'array:type,id',
            'items.*.type' => 'required|in:property,new_building,developer_unit', 'items.*.id' => 'required|integer|min:1'])->validate()['items'];
    }

    public function resolve(array $items): array
    {
        $groups = collect($items)->groupBy('type');
        $found = [];
        $serializer = app(PublicInventory::class);
        foreach ($groups as $type => $references) {
            $ids = $references->pluck('id')->unique()->all();
            $query = match ($type) {
                'new_building' => app(InventoryQuery::class)->withAggregates(app(InventoryQuery::class)->buildings([]))->with(['coverPhoto', 'developer', 'location']),
                'developer_unit' => DeveloperUnit::query()->availability(['available', 'reserved', 'sold'])->with(['block', 'entrance', 'coverPhoto']),
                'property' => Property::query()->publicSearchable(),
                default => abort(422),
            };
            foreach ($query->whereKey($ids)->get() as $record) {
                $value = match ($type) {
                    'new_building' => ['label' => $record->title, 'href' => '/new-buildings/'.$record->id, 'building' => $serializer->building($record)],
                    'developer_unit' => ['label' => $record->name, 'href' => '/new-buildings/'.$record->new_building_id.'/units/'.$record->id, 'unit' => $serializer->unit($record)],
                    'property' => ['label' => 'Объявление №'.$record->id, 'href' => '/apartment/'.$record->id],
                };
                $found[$type.':'.$record->id] = ['type' => $type, 'id' => (int) $record->id, 'available' => true, ...$value];
            }
        }

        return collect($items)->unique(fn ($item) => $item['type'].':'.$item['id'])->map(fn ($item) => $found[$item['type'].':'.$item['id']] ?? ['type' => $item['type'], 'id' => (int) $item['id'], 'available' => false])->values()->all();
    }
}
