<?php

namespace Tests\Unit;

use App\Services\PropertyLiquidity\LiquidityMath;
use PHPUnit\Framework\TestCase;

class LiquidityMathTest extends TestCase
{
    public function test_percentile_handles_ties_and_can_reach_both_ends(): void
    {
        $this->assertSame(0, LiquidityMath::percentile(0, [0, 0, 10, 20]));
        $this->assertSame(60, LiquidityMath::percentile(10, [0, 0, 10, 20]));
        $this->assertSame(100, LiquidityMath::percentile(20, [0, 0, 10, 20]));
        $this->assertSame(50, LiquidityMath::percentile(10, [10, 10, 10]));
    }

    public function test_missing_components_reweight_but_real_zero_is_not_discarded(): void
    {
        $weights = ['market' => .25, 'price' => .45, 'demand' => .15, 'fit' => .15];
        $this->assertSame(100, LiquidityMath::weightedScore(['market' => null, 'price' => 100, 'demand' => null, 'fit' => 100], $weights));
        $this->assertSame(60, LiquidityMath::weightedScore(['market' => 0, 'price' => 100, 'demand' => 0, 'fit' => 100], $weights));
    }
}
