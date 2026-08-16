<?php

namespace Tests\Unit;

use App\Services\Attendance\ZktecoTaPushProtocol;
use PHPUnit\Framework\TestCase;

class ZktecoTaPushProtocolTest extends TestCase
{
    public function test_it_parses_tab_and_space_separated_attlog_rows(): void
    {
        $result = (new ZktecoTaPushProtocol)->parse(
            "163\t2026-08-16 09:05:00\t0\t15\t0\t0\n164 2026-08-16 09:10:00 1 2 0 0",
            'Asia/Dushanbe'
        );

        $this->assertCount(2, $result['events']);
        $this->assertSame([], $result['rejected']);
        $this->assertSame('163', $result['events'][0]['device_user_id']);
        $this->assertSame('2026-08-16 09:05:00', $result['events'][0]['occurred_at_local']->format('Y-m-d H:i:s'));
        $this->assertSame('0', $result['events'][0]['attendance_status']);
        $this->assertSame('15', $result['events'][0]['verify_mode']);
    }

    public function test_it_rejects_bad_rows_without_dropping_valid_rows(): void
    {
        $result = (new ZktecoTaPushProtocol)->parse(
            "bad row\n163\t2026-02-31 09:05:00\t0\t15\n163\t2026-08-16 09:05:00\t0\t15",
            'Asia/Dushanbe'
        );

        $this->assertCount(1, $result['events']);
        $this->assertCount(2, $result['rejected']);
        $this->assertSame(1, $result['rejected'][0]['line']);
        $this->assertSame(2, $result['rejected'][1]['line']);
    }

    public function test_it_parses_real_zam230_firmware_fixture(): void
    {
        $payload = file_get_contents(
            dirname(__DIR__).'/Fixtures/zkteco/zam230_wcf3254200047_attlog.txt'
        );

        $result = (new ZktecoTaPushProtocol)->parse($payload, 'Asia/Dushanbe');

        $this->assertCount(1, $result['events']);
        $this->assertSame([], $result['rejected']);
        $this->assertSame('1', $result['events'][0]['device_user_id']);
        $this->assertSame('2026-08-16 19:50:49', $result['events'][0]['occurred_at_local']->format('Y-m-d H:i:s'));
        $this->assertSame('0', $result['events'][0]['attendance_status']);
        $this->assertSame('15', $result['events'][0]['verify_mode']);
        $this->assertSame('0', $result['events'][0]['work_code']);
        $this->assertSame(['0', '0', '255', '0', '0'], $result['events'][0]['reserved']);
    }
}
