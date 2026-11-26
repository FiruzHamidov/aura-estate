<?php

namespace Tests\Unit;

use App\Services\SalesAttributionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SalesAttributionTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'own listing' => [['agent_id' => 1, 'sale_user_id' => 1], [1 => 1.0]],
            'another agent sells' => [['agent_id' => 1, 'sale_user_id' => 2], [2 => 0.5, 1 => 0.5]],
            'explicit seller wins' => [['agent_id' => 1, 'sale_user_id' => 2, 'sale_agent_id' => 3], [3 => 0.5, 1 => 0.5]],
            'assigned agent wins over creator' => [['agent_id' => 1, 'created_by' => 3, 'sale_user_id' => 2], [2 => 0.5, 1 => 0.5]],
            'legacy creator' => [['created_by' => 1, 'sale_user_id' => 2], [2 => 0.5, 1 => 0.5]],
            'no owner' => [['sale_user_id' => 2], [2 => 1.0]],
            'no seller' => [['agent_id' => 1], [1 => 1.0]],
            'rental unchanged' => [['moderation_status' => 'rented', 'agent_id' => 1, 'sale_user_id' => 2], [2 => 1.0]],
            'owner sale unchanged' => [['moderation_status' => 'sold_by_owner', 'agent_id' => 1, 'sale_user_id' => 2], [2 => 1.0]],
        ];
    }

    #[DataProvider('cases')]
    public function test_kpi_counts_keep_exactly_one_sale(array $fields, array $expected): void
    {
        $properties = collect([(object) array_replace(['id' => 10, 'moderation_status' => 'sold'], $fields)]);
        $service = new SalesAttributionService;
        $this->assertSame([10 => $expected], $service->kpiCreditsByProperty($properties));
        $this->assertEquals(1, array_sum($expected));
        $this->assertSame([10 => [$service->primarySellerId($properties->first()) => 1.0]], $service->creditsByProperty($properties));
    }
}
