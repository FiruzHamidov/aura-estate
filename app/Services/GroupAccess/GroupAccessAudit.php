<?php

namespace App\Services\GroupAccess;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class GroupAccessAudit
{
    public function record(?User $actor, string $event, string $type, int $id, array $before, array $after, ?string $reason = null): void
    {
        DB::table('group_access_audit_logs')->insert([
            'actor_id' => $actor?->id,
            'event' => $event,
            'subject_type' => $type,
            'subject_id' => $id,
            'old_values' => json_encode($before, JSON_THROW_ON_ERROR),
            'new_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'trace_id' => app()->bound('request') ? request()->attributes->get('trace_id') : null,
            'created_at' => now(),
        ]);
    }
}
