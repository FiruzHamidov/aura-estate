<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDevice;
use App\Services\Attendance\AttendanceIngestionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ZktecoAttendanceController extends Controller
{
    public function __construct(private readonly AttendanceIngestionService $ingestion) {}

    public function cdata(Request $request): Response
    {
        $device = $this->resolveDevice($request);
        if ($request->isMethod('get')) {
            $this->ingestion->touch($device, $request->ip());

            return $request->query('options') !== null
                ? $this->optionsResponse($device)
                : $this->plain('OK');
        }

        $payload = (string) $request->getContent();
        if (strlen($payload) > (int) config('attendance.device_request_max_bytes', 262144)) {
            return $this->plain('ERROR: PAYLOAD TOO LARGE', 413);
        }

        $table = strtoupper((string) $request->query('table', 'ATTLOG'));
        if ($table !== 'ATTLOG') {
            $this->ingestion->touch($device, $request->ip());

            return $this->plain('OK');
        }

        $result = $this->ingestion->ingest($device, $payload, [
            'table' => $table,
            'stamp' => $request->query('Stamp') ?? $request->query('stamp'),
            'op_stamp' => $request->query('OpStamp') ?? $request->query('opstamp'),
            'query' => $this->sanitizedQuery($request),
        ], $request->ip());
        $processed = $result['accepted'] + $result['duplicates'] + $result['unmapped'];

        return $this->plain('OK: '.$processed);
    }

    public function getRequest(Request $request): Response
    {
        $device = $this->resolveDevice($request);
        $this->ingestion->touch($device, $request->ip());

        return $this->plain('OK');
    }

    public function deviceCommand(Request $request): Response
    {
        $device = $this->resolveDevice($request);
        $this->ingestion->touch($device, $request->ip());

        return $this->plain('OK');
    }

    public function registry(Request $request): Response
    {
        $device = $this->resolveDevice($request);
        $this->ingestion->touch($device, $request->ip());

        return $this->plain('OK');
    }

    public function ping(Request $request): Response
    {
        $device = $this->resolveDevice($request);
        $this->ingestion->touch($device, $request->ip());

        return $this->plain('OK');
    }

    private function resolveDevice(Request $request): AttendanceDevice
    {
        $serial = trim((string) ($request->query('SN') ?? $request->query('sn') ?? ''));
        $device = $serial === '' ? null : AttendanceDevice::query()
            ->where('serial_number', $serial)
            ->where('is_active', true)
            ->first();
        if ($device === null) {
            abort($this->plain('ERROR: UNKNOWN DEVICE', 403));
        }

        $allowedIps = config('attendance.allowed_ips', []);
        if ($allowedIps !== [] && ! in_array($request->ip(), $allowedIps, true)) {
            abort($this->plain('ERROR: IP NOT ALLOWED', 403));
        }

        $expectedKey = $device->communication_key;
        if (is_string($expectedKey) && $expectedKey !== '') {
            $providedKey = (string) (
                $request->query('PushCommKey')
                ?? $request->query('pushcommkey')
                ?? $request->header('X-Communication-Key', '')
            );
            if ($providedKey === '' || ! hash_equals($expectedKey, $providedKey)) {
                abort($this->plain('ERROR: INVALID COMMUNICATION KEY', 403));
            }
        }

        return $device;
    }

    private function optionsResponse(AttendanceDevice $device): Response
    {
        $lines = [
            'GET OPTION FROM: '.$device->serial_number,
            'Stamp=0',
            'OpStamp=0',
            'ErrorDelay=60',
            'Delay=10',
            'TransInterval=1',
            'TransFlag=AttLog',
            'Realtime=1',
            'Encrypt=0',
        ];

        return $this->plain(implode("\n", $lines)."\n");
    }

    private function sanitizedQuery(Request $request): array
    {
        $query = $request->query();
        foreach (['PushCommKey', 'pushcommkey'] as $secret) {
            if (array_key_exists($secret, $query)) {
                $query[$secret] = '[redacted]';
            }
        }

        return $query;
    }

    private function plain(string $content, int $status = 200): Response
    {
        return response($content, $status)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
