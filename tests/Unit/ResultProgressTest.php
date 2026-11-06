<?php

namespace Tests\Unit;

use App\Services\Kpi\ResultProgress;
use PHPUnit\Framework\TestCase;

final class ResultProgressTest extends TestCase
{
    public function test_either_target_completes_without_adding_percentages(): void
    {
        $goal = ['mode' => 'count_or_amount', 'count_target' => 3, 'amount_target' => 1000];
        $result = ResultProgress::calculate($goal, 2, 800, 1);
        self::assertSame(80.0, $result['progress_percent']);
        self::assertFalse($result['achieved']);
        self::assertTrue($result['progress_is_lower_bound']);
        $result = ResultProgress::calculate($goal, 3, 800, 1);
        self::assertTrue($result['achieved']);
        self::assertFalse($result['progress_is_lower_bound']);
        self::assertFalse($result['amount_complete']);
    }

    public function test_missing_amount_is_not_reported_as_complete_zero_and_overachievement_is_visible(): void
    {
        $goal = ['mode' => 'amount', 'amount_target' => 1000];
        self::assertSame('incomplete', ResultProgress::calculate($goal, 1, 0, 1)['status']);
        self::assertSame(125.0, ResultProgress::calculate($goal, 2, 1250, 0)['progress_percent']);
        self::assertNull(ResultProgress::calculate(null, 4, 1000, 0)['progress_percent']);
        self::assertFalse(ResultProgress::calculate(['mode' => 'count', 'count_target' => 1000000], 999999, 0, 0)['achieved']);
        self::assertFalse(ResultProgress::calculate(['mode' => 'amount', 'amount_target' => 1000000], 0, 999999.99, 0)['achieved']);
    }
}
