<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeveloperUnitRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // при необходимости можно добавить логику проверки прав
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Получаем текущий unit из маршрута (если он есть) для уникальных правил
        $unit = $this->route('unit');

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

            // moderation_status and window_view
            'moderation_status' => ['nullable', Rule::in([
                'pending','available','sold','reserved'
            ])],

            'window_view' => ['nullable', Rule::in([
                'courtyard','street','park','mountains','city','panoramic'
            ])],
        ];
    }
}
