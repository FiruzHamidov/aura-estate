<?php

namespace App\Services\Residential;

use Illuminate\Validation\Rule;

final class InventoryRules
{
    public static function building(bool $updating = false): array
    {
        return [
            'title' => 'sometimes|required|string|max:255', 'description' => 'nullable|string|max:50000',
            'developer_id' => 'nullable|integer|exists:developers,id', 'construction_stage_id' => 'nullable|integer|exists:construction_stages,id',
            'material_id' => 'nullable|integer|exists:materials,id', 'location_id' => 'nullable|integer|exists:locations,id',
            'features' => 'sometimes|array|max:100', 'features.*' => 'integer|distinct|exists:features,id',
            'installment_available' => 'sometimes|boolean', 'heating' => 'sometimes|nullable|boolean', 'has_terrace' => 'sometimes|nullable|boolean',
            'floors_range' => 'nullable|string|max:32', 'completion_at' => 'nullable|date',
            'completion_precision' => 'sometimes|in:unknown,date,quarter,year', 'completion_year' => 'nullable|integer|between:1900,2200', 'completion_quarter' => 'nullable|integer|between:1,4',
            'address' => 'nullable|string|max:255', 'district' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180',
            'ceiling_height' => 'nullable|numeric|gt:0|max:10', 'housing_class' => 'nullable|string|max:80',
            'parking' => 'nullable|string|max:255', 'advantages' => 'nullable|array|max:30', 'advantages.*' => 'string|max:500',
            'branch_id' => 'nullable|integer|exists:branches,id', 'responsible_agent_id' => 'nullable|integer|exists:users,id',
            'data_verified_at' => 'nullable|date|before_or_equal:now',
            'publication_status' => ['sometimes', Rule::in(InventoryStatus::PUBLICATION)],
            'moderation_status' => 'sometimes|in:pending,approved,rejected,draft,deleted',
            'version' => [$updating ? 'required' : 'sometimes', 'integer', 'min:1'],
            'change_reason' => 'nullable|string|max:1000',
        ];
    }

    public static function unit(bool $updating = false): array
    {
        return [
            'new_building_id' => 'sometimes|integer|min:1', 'block_id' => 'nullable|integer|min:1', 'entrance_id' => 'nullable|integer|min:1', 'layout_id' => 'nullable|integer|min:1',
            'name' => 'sometimes|required|string|max:100', 'number' => 'nullable|string|max:50', 'position_on_floor' => 'nullable|integer|between:1,500',
            'bedrooms' => 'nullable|integer|between:0,20', 'rooms' => 'nullable|integer|between:0,20', 'bathrooms' => 'nullable|integer|between:0,20',
            'area' => 'sometimes|numeric|gt:0|max:99999999.99', 'living_area' => 'nullable|numeric|gt:0|max:99999999.99', 'kitchen_area' => 'nullable|numeric|gt:0|max:99999999.99',
            'floor' => 'nullable|integer|between:0,200', 'ceiling_height' => 'nullable|numeric|gt:0|max:10',
            'price_per_sqm' => 'nullable|numeric|gt:0|max:9999999999999.99', 'total_price' => 'nullable|numeric|gt:0|max:9999999999999.99',
            'price_on_request' => 'sometimes|boolean', 'pricing_basis' => 'sometimes|in:total,per_sqm', 'currency' => 'sometimes|in:TJS',
            'description' => 'nullable|string|max:50000', 'is_available' => 'sometimes|boolean',
            'publication_status' => ['sometimes', Rule::in(InventoryStatus::PUBLICATION)],
            'availability_status' => ['sometimes', Rule::in(InventoryStatus::AVAILABILITY)],
            'moderation_status' => 'sometimes|in:pending,available,approved,sold,reserved,draft,rejected,deleted',
            'window_view' => 'nullable|in:courtyard,street,park,mountains,city,panoramic', 'finishing' => 'nullable|string|max:100',
            'data_verified_at' => 'nullable|date|before_or_equal:now', 'external_id' => 'nullable|string|max:100',
            'version' => [$updating ? 'required' : 'sometimes', 'integer', 'min:1'], 'change_reason' => 'nullable|string|max:1000',
        ];
    }
}
