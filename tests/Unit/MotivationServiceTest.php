<?php

namespace Tests\Unit;

use App\Services\MotivationService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MotivationServiceTest extends TestCase
{
    public function test_validate_period_accepts_week_month_year(): void
    {
        $service = new MotivationService();

        $service->validatePeriod('week', '2026-05-04', '2026-05-10');
        $service->validatePeriod('month', '2026-05-01', '2026-05-31');
        $service->validatePeriod('year', '2026-01-01', '2026-12-31');

        $this->assertTrue(true);
    }

    public function test_validate_period_rejects_invalid_ranges(): void
    {
        $service = new MotivationService();

        $this->expectException(ValidationException::class);
        $service->validatePeriod('week', '2026-05-04', '2026-05-11');
    }
}
