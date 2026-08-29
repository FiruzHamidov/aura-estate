<?php

namespace App\Services\Residential;

use Illuminate\Support\Facades\Validator;

final class InventoryFilters
{
    public const UNIT_FIELDS = ['block_id', 'entrance_id', 'rooms', 'rooms_from', 'rooms_to', 'price_min', 'price_max', 'area_min', 'area_max', 'floor_min', 'floor_max', 'kitchen_min', 'kitchen_max', 'exclude_first_floor', 'exclude_last_floor', 'only_last_floor', 'finishing', 'window_view'];

    public function validate(array $input, bool $catalog = false): array
    {
        $rules = [
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|between:1,100',
            'block_id' => 'nullable|integer|min:1', 'entrance_id' => 'nullable|integer|min:1',
            'rooms' => 'sometimes|array|max:21', 'rooms.*' => 'required|in:studio,0,1,2,3,4+,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20',
            'include_reserved' => 'sometimes|boolean', 'available' => 'sometimes|boolean',
            'exclude_first_floor' => 'sometimes|boolean', 'exclude_last_floor' => 'sometimes|boolean', 'only_last_floor' => 'sometimes|boolean',
            'finishing' => 'nullable|array|max:20', 'finishing.*' => 'string|max:100',
            'window_view' => 'nullable|array|max:20', 'window_view.*' => 'in:courtyard,street,park,mountains,city,panoramic',
            'sort' => $catalog ? 'sometimes|in:newest,price_asc,price_desc,completion' : 'sometimes|in:newest,price_asc,price_desc,area_asc,area_desc,floor_asc,floor_desc',
        ];
        foreach (['price', 'area', 'kitchen'] as $field) {
            foreach (['min', 'max'] as $bound) {
                $key = $field.'_'.$bound;
                if (isset($input[$key]) && is_string($input[$key])) {
                    $input[$key] = str_replace(',', '.', trim($input[$key]));
                }
                $rules[$key] = 'nullable|numeric|min:0|max:9999999999999.99';
            }
        }
        foreach (['floor_min', 'floor_max', 'rooms_from', 'rooms_to'] as $key) {
            $rules[$key] = 'nullable|integer|between:0,200';
        }
        if ($catalog) {
            $rules += [
                'search' => 'nullable|string|max:255', 'developer_id' => 'nullable|integer|min:1',
                'stage_id' => 'nullable|integer|min:1', 'material_id' => 'nullable|integer|min:1',
                'location_id' => 'nullable|integer|min:1', 'district' => 'nullable|string|max:255',
                'installment_available' => 'sometimes|boolean',
                'completion_year' => 'nullable|integer|between:1900,2200',
                'ceiling_height_min' => 'nullable|numeric|between:0,10', 'ceiling_height_max' => 'nullable|numeric|between:0,10',
                'bbox' => 'sometimes|array|size:4', 'bbox.*' => 'numeric|between:-180,180',
                'mode' => 'sometimes|in:list,map',
            ];
        }
        $validator = Validator::make($input, $rules);
        $validator->after(function ($validator) use ($input) {
            foreach (['price', 'area', 'kitchen', 'floor', 'ceiling_height'] as $field) {
                $min = $input[$field.'_min'] ?? null;
                $max = $input[$field.'_max'] ?? null;
                if (is_numeric($min) && is_numeric($max) && (float) $min > (float) $max) {
                    $validator->errors()->add($field.'_max', 'Верхняя граница должна быть не меньше нижней.');
                }
            }
            if (isset($input['rooms_from'], $input['rooms_to']) && is_numeric($input['rooms_from']) && is_numeric($input['rooms_to']) && $input['rooms_from'] > $input['rooms_to']) {
                $validator->errors()->add('rooms_to', 'Верхняя граница комнатности должна быть не меньше нижней.');
            }
            if (($input['exclude_last_floor'] ?? false) && ($input['only_last_floor'] ?? false)) {
                $validator->errors()->add('only_last_floor', 'Нельзя одновременно исключить и выбрать последний этаж.');
            }
            $bbox = $input['bbox'] ?? null;
            if (is_array($bbox) && count($bbox) === 4 && count(array_filter($bbox, 'is_numeric')) === 4) {
                [$west, $south, $east, $north] = array_values($bbox);
                if ($west >= $east || $south >= $north || $south < -90 || $north > 90) {
                    $validator->errors()->add('bbox', 'Некорректная область карты.');
                }
            }
        });

        return $validator->validate();
    }

    public function hasUnitFilters(array $filters): bool
    {
        foreach (self::UNIT_FIELDS as $field) {
            if (array_key_exists($field, $filters) && $filters[$field] !== null && $filters[$field] !== [] && (! in_array($field, ['exclude_first_floor', 'exclude_last_floor', 'only_last_floor'], true) || (bool) $filters[$field])) {
                return true;
            }
        }

        return false;
    }
}
