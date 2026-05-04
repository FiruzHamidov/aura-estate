<?php

namespace Tests\Unit;

use App\Services\KpiModuleService;
use Tests\TestCase;

class KpiModuleServiceTest extends TestCase
{
    public function test_plans_returns_config_backed_rows(): void
    {
        $rows = app(KpiModuleService::class)->plans('mop');

        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('metric_key', $rows->first());
        $this->assertArrayHasKey('daily_plan', $rows->first());
    }
}
