<?php

namespace Tests\Unit;

use App\Models\PaymentProgram;
use App\Services\Residential\PaymentCalculator;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentCalculatorTest extends TestCase
{
    private function program(array $values = []): PaymentProgram
    {
        return new PaymentProgram(['calculation_method' => 'equal_installment', 'fees_verified' => true, 'annual_rate' => '0', 'period_months' => 1, 'term_min_months' => 1, 'term_max_months' => 360, 'min_down_percent' => 0, 'upfront_fee_percent' => 0, 'upfront_fee_fixed' => 0, ...$values]);
    }

    public function test_zero_interest_installments_adjust_last_payment_and_preserve_exact_principal(): void
    {
        $result = app(PaymentCalculator::class)->calculate($this->program(), '120.00', '20.00', 3);
        $this->assertSame('33.33', $result['first_payment']);
        $this->assertSame('33.34', $result['last_payment']);
        $this->assertSame('100.00', $result['total_payments']);
        $this->assertSame('120.00', $result['total_cost']);
        $this->assertSame('0.00', $result['schedule'][2]['balance']);
    }

    public function test_annuity_and_differentiated_schedules_have_exact_sums_and_declining_balance(): void
    {
        foreach (['annuity', 'differentiated'] as $method) {
            $program = $this->program(['calculation_method' => $method, 'annual_rate' => 12, 'upfront_fee_percent' => 1, 'upfront_fee_fixed' => '25.50']);
            $result = app(PaymentCalculator::class)->calculate($program, '120000', '20000', 12);
            $this->assertSame($method === 'annuity' ? '8884.88' : '9333.33', $result['first_payment']);
            $this->assertSame('1025.50', $result['upfront_fees']);
            $principal = BigDecimal::zero();
            $total = BigDecimal::zero();
            $lastBalance = BigDecimal::of('100000');
            foreach ($result['schedule'] as $row) {
                $principal = $principal->plus($row['principal']);
                $total = $total->plus($row['payment']);
                $this->assertTrue($lastBalance->isGreaterThanOrEqualTo($row['balance']));
                $lastBalance = BigDecimal::of($row['balance']);
                $this->assertTrue(BigDecimal::of($row['principal'])->plus($row['interest'])->isEqualTo($row['payment']));
            }
            $this->assertTrue($principal->isEqualTo('100000'));
            $this->assertTrue($lastBalance->isZero());
            $this->assertTrue($total->isEqualTo($result['total_payments']));
            $this->assertTrue($total->plus('21025.50')->isEqualTo($result['total_cost']));
        }
    }

    public function test_unknown_fees_unsupported_method_and_nonzero_interest_installment_are_not_faked(): void
    {
        foreach ([['fees_verified' => false], ['upfront_fee_fixed' => null], ['calculation_method' => 'manual'], ['annual_rate' => 10]] as $values) {
            try {
                app(PaymentCalculator::class)->calculate($this->program($values), '100000', '20000', 12);
                $this->fail('Must reject incomplete/unsupported program');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('program_id', $error->errors());
            }
        }
    }

    public function test_program_limits_and_payment_period_are_enforced(): void
    {
        foreach ([['min_down_percent' => 30], ['period_months' => 3], ['max_principal' => '1000'], ['term_max_months' => 5]] as $values) {
            try {
                app(PaymentCalculator::class)->calculate($this->program($values), '100000', '20000', 10);
                $this->fail('Must enforce program limits');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
        $quarterly = app(PaymentCalculator::class)->calculate($this->program(['period_months' => 3]), '100000', '20000', 12);
        $this->assertSame(4, $quarterly['payment_count']);
        $this->assertSame('20000.00', $quarterly['first_payment']);
    }

    public function test_percentage_conversion_preserves_the_minimum_and_cent_exact_schedules_at_boundaries(): void
    {
        $calculator = app(PaymentCalculator::class);
        $program = $this->program(['min_down_percent' => '20.00']);
        foreach (['0.06', '510000.01', '510000.03', '9999999999999.99'] as $price) {
            $result = $calculator->calculateWithPercent($program, $price, '20.00', 12);
            $down = BigDecimal::of($result['down_payment']);
            $this->assertTrue($down->multipliedBy(100)->isGreaterThanOrEqualTo(BigDecimal::of($price)->multipliedBy(20)));
            $this->assertTrue($down->minus('0.01')->multipliedBy(100)->isLessThan(BigDecimal::of($price)->multipliedBy(20)));
            $this->assertSame($price, $result['total_cost']);
            $this->assertSame('0.00', $result['schedule'][11]['balance']);
            $this->assertSame($result['principal'], $result['total_payments']);
        }
        $this->assertSame('0.00', $calculator->calculateWithPercent($this->program(), '100.00', '0', 12)['down_payment']);
        $this->expectException(ValidationException::class);
        $calculator->calculateWithPercent($program, '0.06', '19', 12);
    }

    public function test_long_term_high_precision_schedule_remains_bounded_and_fully_repaid(): void
    {
        $result = app(PaymentCalculator::class)->calculate($this->program(['calculation_method' => 'annuity', 'annual_rate' => '0.001']), '9999999999999.99', '2000000000000.00', 360);
        $this->assertCount(360, $result['schedule']);
        $this->assertSame('0.00', $result['schedule'][359]['balance']);
        $total = BigDecimal::zero();
        foreach ($result['schedule'] as $row) {
            $total = $total->plus($row['principal']);
        }
        $this->assertTrue($total->isEqualTo('7999999999999.99'));
    }
}
