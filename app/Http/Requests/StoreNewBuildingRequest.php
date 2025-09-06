<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNewBuildingRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title' => ['required','string','max:255'],
            'developer_id' => ['nullable','exists:developers,id'],
            'construction_stage_id' => ['nullable','exists:construction_stages,id'],
            'material_id' => ['nullable','exists:materials,id'],
            'location_id' => ['nullable','exists:locations,id'],
            'installment_available' => ['boolean'],
            'heating' => ['boolean'],
            'has_terrace' => ['boolean'],
            'floors_range' => ['nullable','string','max:32'],
            'completion_at' => ['nullable','date'],
            'address' => ['nullable','string','max:255'],
            'latitude' => ['nullable','numeric','between:-90,90'],
            'longitude' => ['nullable','numeric','between:-180,180'],
            'moderation_status' => ['nullable','in:pending,approved,rejected,draft,deleted'],
            'features' => ['array'],
            'features.*' => ['integer','exists:features,id'],
        ];
    }
}
