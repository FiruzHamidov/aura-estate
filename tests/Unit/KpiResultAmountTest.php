<?php

namespace Tests\Unit;

use App\Services\Kpi\ResultAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KpiResultAmountTest extends TestCase
{
    private function fact(array $changes = []): object
    {
        return (object) array_replace([
            'kind' => 'sale', 'amount' => 280000, 'currency' => 'TJS', 'fx_rate' => 10,
            'company_commission_amount' => 12000, 'company_commission_currency' => 'TJS',
            'participant_commissions' => [['user_id' => 1, 'amount' => 800, 'currency' => 'USD']],
            'rental_monthly_amount' => 5000, 'rental_contract_amount' => 60000,
        ], $changes);
    }

    public function test_one_deal_has_three_distinct_monetary_bases(): void
    {
        $fact = $this->fact();
        $this->assertSame(12000.0, ResultAmount::calculate($fact, 'TJS', null, 'company_income'));
        $this->assertSame(20000.0, ResultAmount::calculate($fact, 'TJS', null, 'company_and_agents'));
        $this->assertSame(280000.0, ResultAmount::calculate($fact, 'TJS', null, 'deal_value'));
        $this->assertNull(ResultAmount::calculate($this->fact(['amount' => 0]), 'TJS', null, 'deal_value'));
        $this->assertSame(2000.0, ResultAmount::calculate($fact, 'USD', null, 'company_and_agents'));
    }

    public function test_rental_period_only_changes_contract_value_not_commission(): void
    {
        $fact = $this->fact(['kind' => 'rental']);
        $this->assertSame(5000.0, ResultAmount::calculate($fact, 'TJS', 'monthly_rent', 'deal_value'));
        $this->assertSame(60000.0, ResultAmount::calculate($fact, 'TJS', 'contract_total', 'deal_value'));
        foreach (['monthly_rent', 'contract_total', null] as $period) {
            $this->assertSame(12000.0, ResultAmount::calculate($fact, 'TJS', $period, 'company_income'));
        }
    }

    public function test_missing_payout_never_counts_as_zero_and_does_not_block_company_only(): void
    {
        $fact = $this->fact(['participant_commissions' => [['user_id' => 1, 'amount' => null, 'currency' => 'TJS']]]);
        $this->assertNull(ResultAmount::calculate($fact, 'TJS', null, 'company_and_agents'));
        $this->assertSame(12000.0, ResultAmount::calculate($fact, 'TJS', null, 'company_income'));
        $fact->participant_commissions = [];
        $this->assertSame(12000.0, ResultAmount::calculate($fact, 'TJS', null, 'company_and_agents'));
        $fact->participant_commissions = null;
        $this->assertNull(ResultAmount::calculate($fact, 'TJS', null, 'company_and_agents'));
    }

    public function test_company_income_uses_its_own_currency_and_zero_is_complete(): void
    {
        $fact = $this->fact(['currency' => 'USD', 'fx_rate' => null]);
        $this->assertSame(12000.0, ResultAmount::calculate($fact, 'TJS', null, 'company_income'));
        $this->assertNull(ResultAmount::calculate($fact, 'TJS', null, 'company_and_agents'));
        $fact->company_commission_amount = 0;
        $this->assertSame(0.0, ResultAmount::calculate($fact, 'USD', null, 'company_income'));
        $fact->company_commission_amount = null;
        $this->assertNull(ResultAmount::calculate($fact, 'TJS', null, 'company_income'));
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_financial_values_are_not_counted(mixed $amount, ?string $currency, mixed $fx): void
    {
        $this->assertNull(ResultAmount::convert($amount, $currency, 'TJS', $fx));
    }

    public static function invalidValues(): array
    {
        return [[null, 'TJS', 10], [-1, 'TJS', 10], ['oops', 'TJS', 10], [INF, 'TJS', 10],
            [100, 'EUR', 10], [100, null, 10], [100, 'USD', null], [100, 'USD', 0], [100, 'USD', -1]];
    }
}
