<?php

namespace App\Services;

use App\Models\BuildingType;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class PropertyBuildingType
{
    /** Run after acquiring the property lock; never convert an ambiguous old status. */
    public function normalizeWrite(array $data, ?Property $property = null): array
    {
        if (! Schema::hasColumn('properties', 'building_type_id')) {
            if (array_key_exists('building_type_id', $data)) {
                throw ValidationException::withMessages(['building_type_id' => 'Сохранение типа дома временно недоступно. Повторите после обновления сервиса.']);
            }
            return $data;
        }
        if (array_key_exists('status_id', $data)) {
            $same = $property && ($data['status_id'] === null ? $property->status_id === null : (string) $data['status_id'] === (string) $property->status_id);
            $safeCreateDefault = ! $property && ($data['status_id'] === null
                || DB::table('property_statuses')->where('id', $data['status_id'])->value('slug') === 'available');
            if (! $same && ! $safeCreateDefault) {
                throw ValidationException::withMessages(['status_id' => 'Тип дома передан в устаревшем формате. Обновите страницу или приложение и повторите сохранение.']);
            }
            if ($property) unset($data['status_id']); // tolerate unchanged echoes from old clients
        }
        if (! $property) $data['building_type_id'] ??= null;
        if (array_key_exists('building_type_id', $data)
            && (! $property || $property->building_type_resolved_at === null || $property->building_type_id != $data['building_type_id'])) {
            $data['building_type_resolved_at'] = now();
        }
        return $data;
    }

    /** Preserve old display data as explicitly unverified, without assigning it to the new field. */
    public function present(Property $property, array $payload): array
    {
        if (! array_key_exists('building_type_id', $property->getAttributes())) return $payload;
        $resolved = $property->building_type_resolved_at !== null || $property->building_type_id !== null;
        $payload['building_type_id'] = $property->building_type_id;
        $payload['building_type_source'] = $resolved ? 'confirmed' : 'legacy_unverified';
        $payload['building_type'] = $resolved
            ? $property->buildingType?->toArray()
            : ($property->status_id ? BuildingType::find($property->status_id)?->toArray() : null);
        return $payload;
    }
}
