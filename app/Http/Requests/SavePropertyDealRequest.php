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
            'moderation_status' => 'required|in:pending,approved,deposit,sold,sold_by_owner,rented,rejected,draft,denied,deleted',

            /**
             * =========================
             * 🟡 ЭТАП: ЗАЛОГ (deposit)
             * =========================
             */
            'buyer_full_name' => 'required_if:moderation_status,deposit|string|min:3',
            'buyer_phone'     => 'required_if:moderation_status,deposit|string|min:6',

            'deposit_amount'       => 'required_if:moderation_status,deposit|numeric|min:0.01',
            'deposit_currency'     => 'required_if:moderation_status,deposit|in:TJS,USD',
            'deposit_received_at'  => 'required_if:moderation_status,deposit|date',
            'deposit_taken_at'     => 'nullable|date',

            'money_holder' => 'required_if:moderation_status,deposit|in:company,agent,owner,developer,client',

            'company_expected_income'          => 'required_if:moderation_status,deposit|numeric|min:0',
            'company_expected_income_currency' => 'required_if:moderation_status,deposit|in:TJS,USD',

            'planned_contract_signed_at' => 'required_if:moderation_status,deposit|date',

            /**
             * =========================
             * 🟢 ЭТАП: ФИНАЛ СДЕЛКИ
             * =========================
             */
            'actual_sale_price'    => 'required_if:moderation_status,sold,sold_by_owner,rented|numeric|min:0.01',
            'actual_sale_currency' => 'required_if:moderation_status,sold,sold_by_owner,rented|in:TJS,USD',

            'company_commission_amount'   => 'required_if:moderation_status,sold,sold_by_owner,rented|numeric|min:0',
            'company_commission_currency' => 'required_if:moderation_status,sold,sold_by_owner,rented|in:TJS,USD',

            'money_received_at'  => 'nullable|date',
            'contract_signed_at' => 'nullable|date',

            /**
             * =========================
             * 👥 АГЕНТЫ
             * =========================
             */
            'agents' => 'nullable|array',
            'agents.*.agent_id' => 'nullable|exists:users,id',
            'agents.*.role' => 'nullable|in:main,assistant,partner',
            'agents.*.commission_amount' => 'nullable|numeric|min:0',
            'agents.*.commission_currency' => 'nullable|in:TJS,USD',
            'agents.*.paid_at' => 'nullable|date',
        ];
    }
}
