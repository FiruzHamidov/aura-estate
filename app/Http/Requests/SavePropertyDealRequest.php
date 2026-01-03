<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePropertyDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'moderation_status' => 'nullable|in:pending,approved,sold,sold_by_owner,rented,denied,deleted',

            // === фактическая цена сделки ===
            'actual_sale_price' => 'nullable|numeric|min:0.01',
            'actual_sale_currency' => 'nullable|in:TJS,USD',

            // === комиссия компании ===
            'company_commission_amount' => 'nullable|numeric|min:0',
            'company_commission_currency' => 'nullable|in:TJS,USD',

            // === у кого деньги ===
            'money_holder' => 'nullable|in:company,agent,owner,developer,client',

            // === даты ===
            'money_received_at' => 'nullable|date',
            'contract_signed_at' => 'nullable|date',

            // === депозит ===
            'deposit_amount' => 'nullable|numeric|min:0',
            'deposit_currency' => 'nullable|in:TJS,USD',
            'deposit_received_at' => 'nullable|date',
            'deposit_taken_at' => 'nullable|date',

            // === агенты (НЕ обязательны) ===
            'agents' => 'nullable|array',
            'agents.*.agent_id' => 'nullable|exists:users,id',
            'agents.*.role' => 'nullable|in:main,assistant,partner',
            'agents.*.commission_amount' => 'nullable|numeric|min:0',
            'agents.*.commission_currency' => 'nullable|in:TJS,USD',
            'agents.*.paid_at' => 'nullable|date',
        ];
    }
}
