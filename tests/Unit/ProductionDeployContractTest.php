<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionDeployContractTest extends TestCase
{
    private string $deploy;

    protected function setUp(): void
    {
        parent::setUp();

        $deploy = file_get_contents(dirname(__DIR__, 2).'/scripts/deploy-production.sh');
        $this->assertIsString($deploy);
        $this->deploy = $deploy;
    }

    public function test_preflight_and_post_switch_verify_the_residential_catalog_contract(): void
    {
        $this->assertStringContainsString('check_public_api()', $this->deploy);
        $this->assertStringContainsString(
            "'https://backend.aura.tj/api/new-buildings?per_page=1'",
            $this->deploy,
        );
        $this->assertStringContainsString('JSON_THROW_ON_ERROR', $this->deploy);
        $this->assertStringContainsString('is_array($payload["data"])', $this->deploy);
        $this->assertSame(3, substr_count($this->deploy, 'check_public_api'));

        $postSwitchCheck = strrpos($this->deploy, 'check_public_api');
        $maintenanceEnds = strpos($this->deploy, 'maintenance=0');
        $queueVerification = strpos($this->deploy, 'supervisorctl status');

        $this->assertIsInt($postSwitchCheck);
        $this->assertIsInt($maintenanceEnds);
        $this->assertIsInt($queueVerification);
        $this->assertGreaterThan($maintenanceEnds, $postSwitchCheck);
        $this->assertLessThan($queueVerification, $postSwitchCheck);
    }
}
