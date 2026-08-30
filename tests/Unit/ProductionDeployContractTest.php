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
        $this->assertGreaterThan($queueVerification, $postSwitchCheck);
    }

    public function test_deploy_rotates_recovery_backups_and_removes_stale_builds(): void
    {
        $this->assertStringContainsString('prune_backend_artifacts()', $this->deploy);
        $this->assertStringContainsString('if (( kept < 2 ))', $this->deploy);
        $this->assertStringContainsString("-name 'backend-*'", $this->deploy);
        $this->assertStringContainsString('find "$build_root"', $this->deploy);

        $preflightPrune = strpos($this->deploy, "prune_backend_artifacts\navailable_kb=");
        $diskGate = strpos($this->deploy, 'available_kb=$(df -Pk /var/www');
        $deployedState = strpos($this->deploy, 'backend.current');
        $postDeployPrune = strrpos($this->deploy, 'prune_backend_artifacts');

        $this->assertIsInt($preflightPrune);
        $this->assertIsInt($diskGate);
        $this->assertIsInt($deployedState);
        $this->assertIsInt($postDeployPrune);
        $this->assertLessThan($diskGate, $preflightPrune);
        $this->assertGreaterThan($deployedState, $postDeployPrune);
    }

    public function test_reverb_is_verified_before_enablement_and_failures_restore_the_environment(): void
    {
        $this->assertStringContainsString('aura-estate-reverb.conf', $this->deploy);
        $this->assertStringContainsString('wait_for_supervisor_program aura-estate-reverb', $this->deploy);
        $this->assertStringContainsString('php scripts/verify-reverb-runtime.php)', $this->deploy);
        $this->assertStringContainsString('php scripts/verify-reverb-runtime.php --expect-enabled', $this->deploy);
        $this->assertStringContainsString('php scripts/enable-messaging-realtime.php', $this->deploy);
        $this->assertStringContainsString('check_realtime_auth_boundaries', $this->deploy);
        $this->assertStringContainsString('pre-realtime-environment', $this->deploy);
        $this->assertStringContainsString('result != 0 && realtime_env_changed == 1', $this->deploy);

        $preEnableProbe = strpos($this->deploy, '(cd "$stage" && php scripts/verify-reverb-runtime.php)');
        $enable = strpos($this->deploy, 'php scripts/enable-messaging-realtime.php');
        $enabledProbe = strpos($this->deploy, 'php scripts/verify-reverb-runtime.php --expect-enabled');
        $deployedState = strpos($this->deploy, 'backend.current');

        $this->assertIsInt($preEnableProbe);
        $this->assertIsInt($enable);
        $this->assertIsInt($enabledProbe);
        $this->assertIsInt($deployedState);
        $this->assertLessThan($enable, $preEnableProbe);
        $this->assertLessThan($enabledProbe, $enable);
        $this->assertLessThan($deployedState, $enabledProbe);
    }
}
