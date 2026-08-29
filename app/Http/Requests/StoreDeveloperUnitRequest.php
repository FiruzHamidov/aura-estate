<?php

namespace App\Http\Requests;

use App\Services\Residential\InventoryRules;
use Illuminate\Foundation\Http\FormRequest;

/** Compatibility wrapper; permissions and cross-record invariants are enforced by InventoryWriter. */
class StoreDeveloperUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return InventoryRules::unit(false);
    }
}
