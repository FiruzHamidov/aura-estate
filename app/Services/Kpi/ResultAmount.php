<?php

namespace App\Services\Kpi;

/** One monetary definition for goals, reports, deal drill-down and rewards. */
final class ResultAmount
{
    public const BASES = ['company_income', 'company_and_agents', 'deal_value'];

    public static function basis(?array $condition): string
    {
        // Conditions saved before monetary bases existed retain their meaning.
        return $condition['amount_basis'] ?? 'deal_value';
    }

    public static function convert(mixed $amount, ?string $from, string $to, mixed $rate, bool $allowZero = true): ?float
    {
        if ($amount === null || ! is_numeric($amount) || ! is_finite((float) $amount)
            || (float) $amount < 0 || (! $allowZero && (float) $amount === 0.0)
            || ! in_array($from, ['TJS', 'USD'], true) || ! in_array($to, ['TJS', 'USD'], true)) {
            return null;
        }
        // A declared zero needs no exchange rate; a missing amount is never zero.
        if ($from === $to || (float) $amount === 0.0) {
            return round((float) $amount, 2);
        }
        if (! is_numeric($rate) || ! is_finite((float) $rate) || (float) $rate <= 0) {
            return null;
        }

        return round($from === 'USD' ? (float) $amount * (float) $rate : (float) $amount / (float) $rate, 2);
    }

    public static function calculate(object $fact, string $currency, ?string $rentalBasis, string $basis): ?float
    {
        if ($basis === 'deal_value') {
            $value = $fact->kind === 'sale' ? $fact->amount : match ($rentalBasis) {
                'monthly_rent' => $fact->rental_monthly_amount ?? null,
                'contract_total' => $fact->rental_contract_amount ?? null,
                default => null,
            };

            return self::convert($value, $fact->currency ?? null, $currency, $fact->fx_rate ?? null, false);
        }
        if (! in_array($basis, self::BASES, true)) {
            return null;
        }
        $company = self::convert($fact->company_commission_amount ?? null,
            $fact->company_commission_currency ?? null, $currency, $fact->fx_rate ?? null);
        if ($company === null || $basis === 'company_income') {
            return $company;
        }
        $participants = $fact->participant_commissions ?? null;
        if (is_string($participants)) {
            $participants = json_decode($participants, true);
        }
        if (! is_array($participants)) {
            return null;
        }
        foreach ($participants as $participant) {
            $amount = self::convert($participant['amount'] ?? null, $participant['currency'] ?? null,
                $currency, $fact->fx_rate ?? null);
            if ($amount === null) {
                return null;
            }
            $company += $amount;
        }

        return round($company, 2);
    }
}
