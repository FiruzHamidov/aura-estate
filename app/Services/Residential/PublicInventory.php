<?php

namespace App\Services\Residential;

use App\Models\DeveloperUnit;
use App\Models\NewBuilding;

/** Explicit public fields: never serialize an entire User, internal assignment, or reservation. */
final class PublicInventory
{
    public function __construct(private readonly MediaAssets $media) {}

    public function building(NewBuilding $building, bool $detail = false): array
    {
        $result = $building->only(['id', 'title', 'developer_id', 'construction_stage_id', 'material_id', 'location_id', 'installment_available', 'heating', 'has_terrace', 'floors_range', 'completion_at', 'completion_precision', 'completion_year', 'completion_quarter', 'address', 'district', 'latitude', 'longitude', 'ceiling_height', 'housing_class', 'parking', 'advantages', 'created_at', 'updated_at', 'data_verified_at', 'version']);
        $result['publication_status'] = InventoryStatus::building($building->getAttributes());
        $result['completion_at'] = $building->completion_at?->toDateString();
        $result['moderation_status'] = InventoryStatus::legacyBuilding($result['publication_status']);
        foreach (['developer', 'stage', 'location', 'material'] as $relation) {
            if ($building->relationLoaded($relation)) {
                $result[$relation] = $building->$relation?->only(match ($relation) {
                    'developer' => ['id', 'name', 'logo_path', 'description', 'website', 'founded_year', 'built_count', 'total_projects', 'under_construction_count'],
                    'location' => ['id', 'city', 'district'], default => ['id', 'name', 'slug'],
                });
            }
        }
        $result += $this->aggregates($building);
        $result['cover_url'] = $building->relationLoaded('coverPhoto') ? $this->photo($building->coverPhoto)['url'] ?? null : null;
        $result['cover_sources'] = $building->relationLoaded('coverPhoto') && $building->coverPhoto ? $this->media->sources($building->coverPhoto) : [];
        if ($detail) {
            $result['description'] = $building->description;
            $result['photos'] = $building->photos->map(fn ($photo) => $this->photo($photo));
            $result['features'] = $building->features->map(fn ($feature) => $feature->only(['id', 'name', 'slug', 'icon']));
            $result['blocks'] = $building->blocks->map(fn ($block) => array_replace($block->only(['id', 'new_building_id', 'name', 'code', 'floors_from', 'floors_to', 'completion_at', 'completion_precision', 'completion_year', 'completion_quarter', 'sort_order']), ['completion_at' => $block->completion_at?->toDateString()]));
            $consultant = $building->responsibleAgent;
            $result['consultant'] = $consultant && $consultant->status === 'active' && ! $consultant->isDeletedAccount() ? $consultant->only(['id', 'name', 'photo', 'phone']) : null;
        }

        return $result;
    }

    public function unit(DeveloperUnit $unit, bool $detail = false): array
    {
        [$publication, $availability] = InventoryStatus::unit($unit->getAttributes());
        $result = $unit->only(['id', 'new_building_id', 'block_id', 'entrance_id', 'layout_id', 'name', 'number', 'position_on_floor', 'bedrooms', 'bathrooms', 'area', 'living_area', 'kitchen_area', 'floor', 'price_per_sqm', 'total_price', 'pricing_basis', 'price_on_request', 'currency', 'finishing', 'window_view', 'ceiling_height', 'version', 'created_at', 'updated_at', 'data_verified_at']);
        $result += ['rooms' => InventoryStatus::rooms($unit->getAttributes()), 'publication_status' => $publication, 'availability_status' => $availability];
        $result += InventoryStatus::legacyUnit($publication, $availability);
        $result['currency'] = 'TJS';
        $result['price'] = $unit->total_price === null ? null : (float) $unit->total_price; // Legacy alias only; exact fields remain decimal strings.
        $result['block'] = $unit->relationLoaded('block') ? $unit->block?->only(['id', 'name', 'completion_at', 'completion_precision', 'completion_year', 'completion_quarter']) : null;
        if ($result['block']) {
            $result['block']['completion_at'] = $unit->block->completion_at?->toDateString();
        }
        $result['entrance'] = $unit->relationLoaded('entrance') ? $unit->entrance?->only(['id', 'name', 'residential_floor_from', 'residential_floor_to']) : null;
        $result['floors_total'] = $result['entrance']['residential_floor_to'] ?? null;
        $result['cover_url'] = $unit->relationLoaded('coverPhoto') ? $this->photo($unit->coverPhoto)['url'] ?? null : null;
        $result['cover_sources'] = $unit->relationLoaded('coverPhoto') && $unit->coverPhoto ? $this->media->sources($unit->coverPhoto) : [];
        if ($detail) {
            $result['description'] = $unit->description;
            $result['photos'] = $unit->photos->map(fn ($photo) => $this->photo($photo));
            $result['layout'] = $unit->layout ? $unit->layout->only(['id', 'code', 'rooms', 'typical_area', 'alt']) + ['image_url' => $this->media->url($unit->layout), 'original_url' => $this->media->url($unit->layout, 'original'), 'sources' => $this->media->sources($unit->layout)] : null;
        }

        return $result;
    }

    public function photo($photo): ?array
    {
        return $photo ? $this->media->photo($photo) : null;
    }

    public function aggregates(NewBuilding $building): array
    {
        $result = ['currency' => 'TJS', 'available_count' => (int) $building->available_count, 'reserved_count' => (int) $building->reserved_count];
        foreach (['min_total_price', 'max_total_price', 'min_price_per_sqm', 'max_price_per_sqm'] as $field) {
            $result[$field] = $building->$field === null ? null : (string) \Brick\Math\BigDecimal::of((string) $building->$field)->toScale(2);
        }

        return $result;
    }

    public function legacyStats(NewBuilding $building): array
    {
        $values = $this->aggregates($building);
        $result = [];
        foreach (['total_price' => 'total_price', 'price_per_sqm' => 'price_per_sqm'] as $key => $column) {
            $min = $values['min_'.$column];
            $max = $values['max_'.$column];
            $result[$key] = ['min' => $min === null ? null : (float) $min, 'max' => $max === null ? null : (float) $max,
                'formatted' => $min === null ? null : number_format((float) $min, 0, '.', ' ').($min === $max ? '' : ' – '.number_format((float) $max, 0, '.', ' ')).($key === 'total_price' ? ' с.' : ' с./м²')];
        }

        return $result;
    }
}
