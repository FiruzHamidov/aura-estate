<?php

namespace App\Services\Attendance;

use App\Models\AttendanceAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AttendanceAuditService
{
    public function record(
        ?User $actor,
        string $action,
        Model $auditable,
        array $oldValues,
        array $newValues,
        Request $request
    ): AttendanceAuditLog {
        return AttendanceAuditLog::query()->create([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $auditable::class,
            'auditable_id' => $auditable->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
