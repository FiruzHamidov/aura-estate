<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDeviceUser;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRawEvent;
use App\Models\User;
use App\Services\Attendance\AttendanceAccessService;
use App\Services\Attendance\AttendanceAuditService;
use App\Services\Attendance\AttendanceIngestionService;
use App\Services\Attendance\AttendanceParticipantService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AttendanceMappingController extends Controller
{
    public function __construct(
        private readonly AttendanceAccessService $access,
        private readonly AttendanceAuditService $audit,
        private readonly AttendanceIngestionService $ingestion,
        private readonly AttendanceParticipantService $participants,
    ) {}

    public function index(Request $request)
    {
        $this->access->assertCanManageMappings($request->user());
        $validated = $request->validate([
            'device_id' => ['nullable', 'integer', 'exists:attendance_devices,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = AttendanceDeviceUser::query()->with(['device.branch', 'user.role', 'user.branch', 'mappedBy:id,name'])
            ->addSelect(['processed_events_count' => AttendanceEvent::query()->selectRaw('COUNT(*)')
                ->whereColumn('attendance_events.device_id', 'attendance_device_users.device_id')
                ->whereColumn('attendance_events.device_user_id', 'attendance_device_users.device_user_id')]);
        if ($request->user()->hasRole('rop')) {
            $query->whereIn('user_id', $this->access->mappingUsersQuery($request->user())->select('users.id'))
                ->whereIn('device_id', $this->access->mappingDevicesQuery($request->user())->select('id'));
        }
        foreach (['device_id', 'user_id'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }

        return response()->json($query->orderByDesc('id')->paginate($validated['per_page'] ?? 50));
    }

    public function upsert(Request $request)
    {
        $this->access->assertCanManageMappings($request->user());
        $data = $request->validate([
            'device_id' => ['required', 'integer', 'exists:attendance_devices,id'],
            'device_user_id' => ['required', 'string', 'max:100'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'card_number' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $data) {
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $this->access->assertCanManageMappings($actor);
            $target = ($actor->hasRole('rop') ? $this->access->mappingUsersQuery($actor) : User::query())->lockForUpdate()->findOrFail($data['user_id']);
            $this->access->mappingDevicesQuery($actor)->lockForUpdate()->findOrFail($data['device_id']);
            $this->participants->assertEligible($target);

            $mapping = AttendanceDeviceUser::query()
                ->where('device_id', $data['device_id'])
                ->where('device_user_id', $data['device_user_id'])
                ->lockForUpdate()->first();
            if ($actor->hasRole('rop')) {
                abort_if($mapping && (int) $mapping->user_id !== (int) $target->id, 403, 'Этот ZKTeco ID уже связан с другим сотрудником. Обратитесь к HR.');
                abort_if(AttendanceEvent::query()->where('device_id', $data['device_id'])
                    ->where('device_user_id', $data['device_user_id'])->where('user_id', '!=', $target->id)->exists(),
                    403, 'У этого ZKTeco ID есть история другого сотрудника. Обратитесь к HR.');
            }
            if ($mapping !== null
                && (int) $mapping->user_id !== (int) $data['user_id']
                && AttendanceEvent::query()
                    ->where('device_id', $mapping->device_id)
                    ->where('device_user_id', $mapping->device_user_id)
                    ->exists()) {
                throw ValidationException::withMessages([
                    'user_id' => ['Нельзя переназначить ID терминала после сохранения посещений. Создайте новый ID сотрудника.'],
                ]);
            }
            $old = $mapping?->only(['user_id', 'card_number', 'is_active']) ?? [];
            $mapping ??= new AttendanceDeviceUser([
                'device_id' => $data['device_id'],
                'device_user_id' => $data['device_user_id'],
            ]);
            $mapping->fill([
                'user_id' => $data['user_id'],
                'card_number' => $data['card_number'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'mapped_by' => $request->user()->id,
                'mapped_at' => now(),
            ])->save();
            $this->audit->record(
                $request->user(),
                $old === [] ? 'attendance_mapping.created' : 'attendance_mapping.updated',
                $mapping,
                $old,
                $mapping->only(['user_id', 'card_number', 'is_active']),
                $request
            );

            $reprocessed = ['processed' => 0, 'unmapped' => 0, 'already_processed' => 0];
            AttendanceRawEvent::query()
                ->where('device_id', $mapping->device_id)
                ->where('device_user_id', $mapping->device_user_id)
                ->where('processing_status', 'unmapped')
                ->orderBy('id')
                ->each(function (AttendanceRawEvent $raw) use (&$reprocessed) {
                    $reprocessed[$this->ingestion->reprocess($raw)]++;
                });

            return response()->json(['data' => $mapping->fresh(['device', 'user.role']), 'reprocessed' => $reprocessed]);
        });
    }

    public function destroy(Request $request, AttendanceDeviceUser $mapping)
    {
        $this->access->assertCanManageMappings($request->user());
        abort_if($request->user()->hasRole('rop'), 403, 'Удалить связь может HR или администратор.');
        $old = $mapping->only([
            'device_id',
            'device_user_id',
            'user_id',
            'card_number',
            'is_active',
            'mapped_by',
            'mapped_at',
        ]);
        $this->audit->record(
            $request->user(),
            'attendance_mapping.deleted',
            $mapping,
            $old,
            [],
            $request
        );
        $mapping->delete();

        return response()->json([
            'message' => 'Сопоставление удалено. ZKTeco ID можно назначить другому сотруднику.',
        ]);
    }
}
