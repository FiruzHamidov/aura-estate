<?php

namespace App\Services\Residential;

use App\Models\PaymentProgram;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/** Nominal fixed-rate periodic schedules. Unsupported/daily/indexed schemes require manual consultation. */
final class PaymentCalculator
{
    private const PRECISION = 24;

    public function calculateWithPercent(PaymentProgram $program, string $price, string $percent, int $months): array
    {
        $percent = BigDecimal::of($percent);
        if ($percent->isLessThan(0) || $percent->isGreaterThanOrEqualTo(100)
            || ($program->min_down_percent !== null && $percent->isLessThan($program->min_down_percent))) {
            throw ValidationException::withMessages(['down_percent' => 'Процент взноса должен быть не ниже минимума программы и меньше 100%.']);
        }
        // A minimum percentage may fall between cents. Never round below the promised percentage.
        $price = BigDecimal::of($price)->toScale(2, RoundingMode::HALF_UP);
        $down = $price->multipliedBy($percent)->dividedBy(100, 2, RoundingMode::CEILING);
        $result = $this->calculate($program, (string) $price, (string) $down, $months);
        $result['assumptions'][] = 'Взнос, заданный процентом, округляется вверх до 0,01 TJS, чтобы не оказаться ниже указанного процента.';

        return $result;
    }

    public function calculate(PaymentProgram $program, string $price, string $down, int $months): array
    {
        if ($program->calculation_method === 'manual' || ! $program->fees_verified) {
            throw ValidationException::withMessages(['program_id' => 'Для этой программы нет полного подтверждённого алгоритма или комиссий. Уточните условия у консультанта.']);
        }
        foreach (['period_months', 'term_min_months', 'term_max_months', 'min_down_percent', 'annual_rate', 'upfront_fee_percent', 'upfront_fee_fixed'] as $field) {
            if ($program->$field === null) {
                throw ValidationException::withMessages(['program_id' => 'Условия программы не заполнены для расчёта.']);
            }
        }
        $period = (int) $program->period_months;
        if ($period < 1 || $months < $program->term_min_months || $months > $program->term_max_months || $months % $period !== 0 || $months > 360) {
            throw ValidationException::withMessages(['months' => 'Выберите допустимый срок, кратный периоду платежа.']);
        }
        $price = BigDecimal::of($price)->toScale(2, RoundingMode::HALF_UP);
        $down = BigDecimal::of($down)->toScale(2, RoundingMode::HALF_UP);
        if ($price->isLessThanOrEqualTo(0) || $price->isGreaterThan('9999999999999.99') || $down->isLessThan(0) || $down->isGreaterThanOrEqualTo($price)) {
            throw ValidationException::withMessages(['down_payment' => 'Стоимость должна быть положительной, а взнос — от нуля до стоимости, не включая её. При полной оплате кредит не нужен.']);
        }
        if ($down->multipliedBy(100)->isLessThan($price->multipliedBy($program->min_down_percent))) {
            throw ValidationException::withMessages(['down_payment' => 'Первоначальный взнос меньше установленного программой.']);
        }
        $principal = $price->minus($down);
        if (($program->min_principal !== null && $principal->isLessThan($program->min_principal)) || ($program->max_principal !== null && $principal->isGreaterThan($program->max_principal))) {
            throw ValidationException::withMessages(['price' => 'Сумма финансирования выходит за пределы программы.']);
        }
        $rate = BigDecimal::of($program->annual_rate)->multipliedBy($period)->dividedBy(1200, self::PRECISION, RoundingMode::HALF_UP);
        $count = intdiv($months, $period);
        $equalPrincipal = $principal->dividedBy($count, 2, RoundingMode::HALF_UP);
        if ($program->calculation_method === 'equal_installment' && ! $rate->isZero()) {
            throw ValidationException::withMessages(['program_id' => 'Беспроцентная равномерная рассрочка должна иметь нулевую ставку.']);
        }
        if (! in_array($program->calculation_method, ['equal_installment', 'annuity', 'differentiated'], true) || $rate->isLessThan(0)) {
            throw ValidationException::withMessages(['program_id' => 'Метод расчёта не поддерживается.']);
        }
        $regular = $equalPrincipal;
        if ($program->calculation_method === 'annuity' && ! $rate->isZero()) {
            // Round intermediate powers at fixed precision to bound CPU/memory for 360 periods.
            $factor = $this->power(BigDecimal::one()->plus($rate), $count);
            $regular = $principal->multipliedBy($rate)->multipliedBy($factor)->dividedBy($factor->minus(1), 2, RoundingMode::HALF_UP);
        }
        $balance = $principal;
        $total = BigDecimal::zero()->toScale(2);
        $interestTotal = BigDecimal::zero()->toScale(2);
        $schedule = [];
        for ($number = 1; $number <= $count; $number++) {
            $interest = $balance->multipliedBy($rate)->toScale(2, RoundingMode::HALF_UP);
            $part = $program->calculation_method === 'annuity' ? $regular->minus($interest) : $equalPrincipal;
            if ($number === $count || $part->isGreaterThan($balance)) {
                $part = $balance;
            }
            if ($part->isLessThan(0)) {
                throw ValidationException::withMessages(['price' => 'Для этой суммы округление не позволяет построить корректный график.']);
            }
            $payment = $part->plus($interest);
            $balance = $balance->minus($part);
            $total = $total->plus($payment);
            $interestTotal = $interestTotal->plus($interest);
            $schedule[] = ['number' => $number, 'month' => $number * $period, 'payment' => (string) $payment, 'principal' => (string) $part, 'interest' => (string) $interest, 'balance' => (string) $balance];
        }
        $fees = $principal->multipliedBy($program->upfront_fee_percent)->dividedBy(100, 2, RoundingMode::HALF_UP)->plus($program->upfront_fee_fixed);

        return ['currency' => 'TJS', 'price' => (string) $price, 'down_payment' => (string) $down, 'principal' => (string) $principal, 'months' => $months, 'period_months' => $period, 'payment_count' => $count,
            'first_payment' => $schedule[0]['payment'], 'last_payment' => $schedule[$count - 1]['payment'], 'total_interest' => (string) $interestTotal,
            'upfront_fees' => (string) $fees, 'total_payments' => (string) $total, 'total_cost' => (string) $down->plus($total)->plus($fees), 'schedule' => $schedule,
            'assumptions' => ['Номинальная фиксированная годовая ставка; проценты начисляются на остаток с равным периодом в месяцах.', 'Платежи и проценты округляются до 0,01 TJS; последний платёж погашает остаток.', 'Указанные разовые комиссии начисляются на сумму финансирования и оплачиваются отдельно, не включаются в кредит.', 'Страховка, оценка, нотариус, штрафы и иные расходы вне указанной программы не включены. Расчёт предварительный, не одобрение банка и не оферта.']];
    }

    private function power(BigDecimal $base, int $exponent): BigDecimal
    {
        $result = BigDecimal::one();
        while ($exponent > 0) {
            if ($exponent % 2 === 1) {
                $result = $result->multipliedBy($base)->toScale(self::PRECISION, RoundingMode::HALF_UP);
            }
            $exponent = intdiv($exponent, 2);
            if ($exponent > 0) {
                $base = $base->multipliedBy($base)->toScale(self::PRECISION, RoundingMode::HALF_UP);
            }
        }

        return $result;
    }
}
