<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeveloperUnitRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'new_building_id' => ['required','exists:new_buildings,id'],
            'block_id' => ['nullable','exists:new_building_blocks,id'],
            'name' => ['required','string','max:100'],
            'bedrooms' => ['nullable','integer','min:0','max:20'],
            'bathrooms' => ['nullable','integer','min:0','max:20'],
            'area' => ['required','numeric','min:0'],
            'floor' => ['nullable','integer','min:0','max:200'],
            'price_per_sqm' => ['nullable','numeric','min:0'],
            'total_price' => ['nullable','numeric','min:0'],
            'description' => ['nullable','string'],
            'is_available' => ['boolean'],
        ];
    }
}
