<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDevice;
use App\Services\Attendance\AttendanceAccessService;
use App\Services\Attendance\AttendanceAuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AttendanceDeviceController extends Controller
{
    public function __construct(
        private readonly AttendanceAccessService $access,
        private readonly AttendanceAuditService $audit,
    ) {}

    public function index(Request $request)
    {
        $this->access->assertCanViewDevices($request->user());
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = AttendanceDevice::query()->with(['branch', 'branchGroup'])->withCount('userMappings');
        if (isset($validated['branch_id'])) {
            $query->where('branch_id', $validated['branch_id']);
        }
        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', (bool) $validated['is_active']);
        }
        $page = $query->orderBy('name')->paginate($validated['per_page'] ?? 50);
        $page->getCollection()->transform(fn (AttendanceDevice $device) => $this->payload($device));

        return response()->json($page);
    }

    public function store(Request $request)
    {
        $this->access->assertCanAdminister($request->user());
        $data = $this->validatedPayload($request);
        $this->validateBranchGroup($data);
        $device = AttendanceDevice::query()->create($data);
        $this->audit->record($request->user(), 'attendance_device.created', $device, [], $this->auditableValues($device), $request);

        return response()->json(['data' => $this->payload($device->load(['branch', 'branchGroup']))], 201);
    }

    public function update(Request $request, AttendanceDevice $device)
    {
        $this->access->assertCanAdminister($request->user());
        $data = $this->validatedPayload($request, $device);
        $this->validateBranchGroup([
            'branch_id' => array_key_exists('branch_id', $data) ? $data['branch_id'] : $device->branch_id,
            'branch_group_id' => array_key_exists('branch_group_id', $data) ? $data['branch_group_id'] : $device->branch_group_id,
        ]);
        $old = $this->auditableValues($device);
        $device->fill($data)->save();
        $this->audit->record($request->user(), 'attendance_device.updated', $device, $old, $this->auditableValues($device), $request);

        return response()->json(['data' => $this->payload($device->fresh(['branch', 'branchGroup']))]);
    }

    private function validatedPayload(Request $request, ?AttendanceDevice $device = null): array
    {
        $sometimes = $device ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$sometimes, 'string', 'max:150'],
            'serial_number' => [$sometimes, 'string', 'max:100', Rule::unique('attendance_devices')->ignore($device?->id)],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'branch_group_id' => ['nullable', 'integer', 'exists:branch_groups,id'],
            'protocol' => ['sometimes', Rule::in(['ta_push'])],
            'timezone' => ['sometimes', 'timezone'],
            'firmware_version' => ['nullable', 'string', 'max:100'],
            'platform' => ['sometimes', 'string', 'max:50'],
            'device_model' => ['sometimes', 'string', 'max:100'],
            'communication_key' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function validateBranchGroup(array $data): void
    {
        if (empty($data['branch_group_id'])) {
            return;
        }
        if (empty($data['branch_id'])) {
            throw ValidationException::withMessages(['branch_id' => ['Для группы необходимо выбрать филиал.']]);
        }
        $valid = \DB::table('branch_groups')
            ->where('id', $data['branch_group_id'])
            ->where('branch_id', $data['branch_id'])
            ->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['branch_group_id' => ['Группа не принадлежит выбранному филиалу.']]);
        }
    }

    private function auditableValues(AttendanceDevice $device): array
    {
        return $device->only([
            'name', 'serial_number', 'branch_id', 'branch_group_id', 'protocol', 'timezone',
            'firmware_version', 'platform', 'device_model', 'is_active',
        ]);
    }

    private function payload(AttendanceDevice $device): array
    {
        $lastSeen = $device->last_seen_at;
        $online = $lastSeen !== null && $lastSeen->gte(now()->subMinutes((int) config('attendance.offline_threshold_minutes', 10)));

        return [
            ...$device->only([
                'id', 'name', 'serial_number', 'branch_id', 'branch_group_id', 'protocol', 'timezone',
                'firmware_version', 'platform', 'device_model', 'is_active', 'last_ip', 'last_error',
            ]),
            'last_seen_at' => $lastSeen?->toISOString(),
            'last_event_at' => $device->last_event_at?->toISOString(),
            'clock_drift_seconds' => $device->clock_drift_seconds,
            'clock_status' => $device->clock_drift_seconds !== null
                && abs($device->clock_drift_seconds) > (int) config('attendance.clock_drift_warning_seconds', 300)
                    ? 'warning'
                    : 'ok',
            'connection_status' => $online ? 'online' : 'offline',
            'user_mappings_count' => (int) ($device->user_mappings_count ?? $device->userMappings()->count()),
            'branch' => $device->branch ? ['id' => $device->branch->id, 'name' => $device->branch->name] : null,
            'branch_group' => $device->branchGroup ? ['id' => $device->branchGroup->id, 'name' => $device->branchGroup->name] : null,
        ];
    }
}
