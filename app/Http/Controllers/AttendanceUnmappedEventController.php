<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRawEvent;
use App\Models\User;
use App\Services\Attendance\AttendanceAccessService;
use App\Services\Attendance\AttendanceAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AttendanceUnmappedEventController extends Controller
{
    public function __construct(
        private readonly AttendanceAccessService $access,
        private readonly AttendanceAuditService $audit,
    ) {}

    public function destroy(Request $request)
    {
        $this->assertCanDelete($request->user());
        $data = $request->validate([
            'device_id' => ['required', 'integer', 'exists:attendance_devices,id'],
            'device_user_id' => ['required', 'string', 'max:100'],
            'last_raw_event_id' => ['required', 'integer', 'min:1'],
        ]);

        return DB::transaction(function () use ($request, $data) {
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $this->assertCanDelete($actor);
            // Ingestion uses the same device lock, so a concurrent re-upload cannot
            // slip between the deduplication marker and removal of the raw event.
            $device = $this->access->mappingDevicesQuery($actor)->lockForUpdate()->findOrFail($data['device_id']);
            $deleted = 0;
            AttendanceRawEvent::query()
                ->where('device_id', $device->id)
                ->where('device_user_id', $data['device_user_id'])
                ->where('id', '<=', $data['last_raw_event_id'])
                ->where('processing_status', 'unmapped')
                ->whereDoesntHave('event')
                ->lockForUpdate()
                ->chunkById(200, function ($events) use ($device, &$deleted) {
                    DB::table('attendance_discarded_events')->insertOrIgnore($events->map(fn ($event) => [
                        'event_hash' => $event->event_hash,
                        'device_id' => $device->id,
                        'deleted_at' => now(),
                    ])->all());
                    $deleted += AttendanceRawEvent::query()->whereKey($events->modelKeys())->delete();
                }, 'id');

            if ($deleted > 0) {
                $this->audit->record($actor, 'attendance_unmapped_events.deleted', $device, [
                    'device_user_id' => $data['device_user_id'],
                    'last_raw_event_id' => $data['last_raw_event_id'],
                    'events_count' => $deleted,
                ], ['deleted_count' => $deleted], $request);
            }

            return response()->json([
                'message' => $deleted > 0 ? 'Несопоставленные события удалены.' : 'Нет несопоставленных событий для удаления.',
                'deleted_count' => $deleted,
            ]);
        }, 3);
    }

    private function assertCanDelete(User $user): void
    {
        $this->access->assertCanManageMappings($user);
        // Shared terminal IDs have no employee scope until they are linked.
        // Keep the same deletion restriction as employee mappings.
        abort_if($user->usesAssignedGroupScope(), 403, 'Удалить несопоставленные события может HR или администратор.');
    }
}
