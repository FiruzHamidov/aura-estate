<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePropertyDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // дальше можно ограничить ролями
    }

    public function rules(): array
    {
        return [
            'actual_sale_price' => 'nullable|numeric|min:0',
            'actual_sale_currency' => 'nullable|in:TJS,USD',

            'company_commission_amount' => 'nullable|numeric|min:0',
            'company_commission_currency' => 'nullable|in:TJS,USD',

            'money_holder' => 'nullable|in:company,agent,owner,developer,client',
            'money_received_at' => 'nullable|date',
            'contract_signed_at' => 'nullable|date',

            'deposit_amount' => 'nullable|numeric|min:0',
            'deposit_currency' => 'nullable|in:TJS,USD',
            'deposit_received_at' => 'nullable|date',
            'deposit_taken_at' => 'nullable|date',

            // агенты
            'agents' => 'required|array|min:1',
            'agents.*.agent_id' => 'required|exists:users,id',
            'agents.*.role' => 'nullable|in:main,assistant,partner',
            'agents.*.commission_amount' => 'nullable|numeric|min:0',
            'agents.*.commission_currency' => 'nullable|in:TJS,USD',
            'agents.*.paid_at' => 'nullable|date',
        ];
    }
}
