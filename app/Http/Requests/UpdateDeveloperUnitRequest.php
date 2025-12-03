<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeveloperUnitRequest extends FormRequest
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
            // Примеры полей — отредактируйте под фактические поля вашей модели
            'block_id'        => ['sometimes', 'nullable', 'integer', 'exists:blocks,id'],
            'new_building_id' => ['sometimes', 'nullable', 'integer', 'exists:new_buildings,id'],

            // номер юнита — допустим уникален в рамках блока
            'number' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
                // если у вас действительно уникальность number внутри блока, раскомментируйте Rule::unique...
                // Rule::unique('developer_units')->where(fn($q) => $q->where('block_id', $this->input('block_id') ?? ($unit->block_id ?? null)))->ignore($unit?->id),
            ],

            'floor'       => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rooms'       => ['sometimes', 'nullable', 'integer', 'min:0'],
            'area'        => ['sometimes', 'nullable', 'numeric', 'min:0'], // м²
            'price'       => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency'    => ['sometimes', 'nullable', 'string', 'max:8'],

            'is_available' => ['sometimes', 'boolean'],

            // даты
            'completion_at' => ['sometimes', 'nullable', 'date'], // либо 'date_format:Y-m-d' если нужен строгий формат
            'available_at'  => ['sometimes', 'nullable', 'date'],

            // текстовые поля
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'meta'        => ['sometimes', 'nullable', 'array'], // если храните доп. данные в JSON
            // пример вложенного JSON: meta->facing, meta->layout и т.д.
            'meta.*'      => ['sometimes', 'nullable'],

            // например флаги/теги
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
        ];
    }

    /**
     * Optionally sanitize / cast inputs before validation.
     */
    protected function prepareForValidation(): void
    {
        // Приводим булевые значения корректно (полезно если приходят "0"/"1" или "true"/"false")
        if ($this->has('is_available')) {
            $this->merge([
                'is_available' => filter_var($this->input('is_available'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }

        // Пример: если приходит пустая строка для числового поля — приводим к null
        foreach (['price', 'area', 'floor', 'rooms'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }
}
