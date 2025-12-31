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
            'moderation_status' => 'required|in:pending,approved,sold,sold_by_owner,rented,denied,deleted',

            // === ОБЯЗАТЕЛЬНО если sold / rented ===
            'actual_sale_price' => 'required_if:moderation_status,sold,sold_by_owner,rented|numeric|min:0.01',
            'actual_sale_currency' => 'required_if:moderation_status,sold,sold_by_owner,rented|in:TJS,USD',

            'company_commission_amount' => 'required_if:moderation_status,sold,sold_by_owner,rented|numeric|min:0',
            'company_commission_currency' => 'required_if:moderation_status,sold,sold_by_owner,rented|in:TJS,USD',

            'money_holder' => 'required_if:moderation_status,sold,sold_by_owner,rented|in:company,agent,owner,developer,client',

            'money_received_at' => 'nullable|date',
            'contract_signed_at' => 'nullable|date',

            // депозит
            'deposit_amount' => 'nullable|numeric|min:0',
            'deposit_currency' => 'nullable|in:TJS,USD',
            'deposit_received_at' => 'nullable|date',
            'deposit_taken_at' => 'nullable|date',

            // агенты — ОБЯЗАТЕЛЬНО при sold агентом
            'agents' => 'required_if:moderation_status,sold|array|min:1',
            'agents.*.agent_id' => 'required|exists:users,id',
            'agents.*.role' => 'nullable|in:main,assistant,partner',
            'agents.*.commission_amount' => 'nullable|numeric|min:0',
            'agents.*.commission_currency' => 'nullable|in:TJS,USD',
            'agents.*.paid_at' => 'nullable|date',
        ];
    }
}
