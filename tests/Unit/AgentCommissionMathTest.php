<?php

namespace Tests\Unit;

use App\Services\AgentWorkspace\CommissionMath;
use PHPUnit\Framework\TestCase;

final class AgentCommissionMathTest extends TestCase
{
    public function test_integer_allocation_preserves_the_last_diram_and_stable_ties(): void
    {
        $rows = CommissionMath::allocate(1, [
            ['agent_id' => 2, 'side' => 'listing', 'share_bps' => 5000],
            ['agent_id' => 1, 'side' => 'closing', 'share_bps' => 5000],
        ]);
        self::assertSame([0, 1], array_column($rows, 'credit_minor'));
        self::assertSame(1, array_sum(array_column($rows, 'credit_minor')));
        self::assertSame(110000, CommissionMath::base('100', '0', 'USD', '11'));
        self::assertSame(88000, CommissionMath::base('100', '20', 'USD', '11'));
        self::assertSame(1, CommissionMath::base('0.01', '0', 'USD', '0.5'));
    }

    public function test_ranking_uses_exact_amount_not_count_or_delta(): void
    {
        $rows = CommissionMath::rank([
            ['agent_id' => 1, 'score_minor' => 4000000, 'deal_count' => 2],
            ['agent_id' => 2, 'score_minor' => 3000000, 'deal_count' => 3],
            ['agent_id' => 3, 'score_minor' => 4000000, 'deal_count' => 8],
            ['agent_id' => 4, 'score_minor' => 0, 'deal_count' => 100],
        ]);
        self::assertSame([3, 1, 2, 4], array_column($rows, 'agent_id'));
        self::assertSame([1, 1, 3, null], array_column($rows, 'rank'));
    }
}
