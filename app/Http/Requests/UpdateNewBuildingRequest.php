<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNewBuildingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Use `sometimes` so fields are optional on update but validated when present.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes','required','string','max:255'],
            'description' => ['sometimes','nullable','string'],
            'developer_id' => ['sometimes','nullable','exists:developers,id'],
            'construction_stage_id' => ['sometimes','nullable','exists:construction_stages,id'],
            'material_id' => ['sometimes','nullable','exists:materials,id'],
            'location_id' => ['sometimes','nullable','exists:locations,id'],
            'installment_available' => ['sometimes','boolean'],
            'heating' => ['sometimes','boolean'],
            'has_terrace' => ['sometimes','boolean'],
            'floors_range' => ['sometimes','nullable','string','max:32'],
            'completion_at' => ['sometimes','nullable','date'],
            'address' => ['sometimes','nullable','string','max:255'],
            'latitude' => ['sometimes','nullable','numeric','between:-90,90'],
            'longitude' => ['sometimes','nullable','numeric','between:-180,180'],
            'moderation_status' => ['sometimes','nullable','in:pending,approved,rejected,draft,deleted'],
            'features' => ['sometimes','array'],
            'features.*' => ['integer','exists:features,id'],
            'district' => ['sometimes','nullable','string','max:255'],
        ];
    }
}
