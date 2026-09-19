<?php

namespace App\Services\GroupAccess;

use App\Models\{BranchGroup, User};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Schema};

/** Resolve delayed facts from recorded organization changes; never guess yesterday from today. */
final class HistoricalUserGroup
{
    public function at(User $user, CarbonImmutable $occurredAt): array
    {
        $snapshot = $this->snapshot($user, $occurredAt);
        $group = isset($snapshot['branch_group_id']) ? BranchGroup::query()->find($snapshot['branch_group_id']) : null;
        if (! $group || (int) $group->branch_id !== (int) ($snapshot['branch_id'] ?? 0)) {
            return ['branch_id' => null, 'branch_group_id' => null];
        }
        return ['branch_id' => (int) $group->branch_id, 'branch_group_id' => (int) $group->id];
    }

    public function roleAt(User $user, CarbonImmutable $occurredAt): ?string
    {
        $snapshot = $this->snapshot($user, $occurredAt);
        return isset($snapshot['role_id']) ? DB::table('roles')->where('id', $snapshot['role_id'])->value('slug') : null;
    }

    private function snapshot(User $user, CarbonImmutable $occurredAt): ?array
    {
        $snapshot = null;
        $auditTime = $occurredAt->setTimezone(config('app.timezone'));
        if (Schema::hasTable('group_access_audit_logs')) {
            $changes = DB::table('group_access_audit_logs')->where('subject_type', 'user')->where('subject_id', $user->id)
                ->whereIn('event', ['user_organization_changed', 'employee_group_transferred']);
            $before = (clone $changes)->where('created_at', '<=', $auditTime)->orderByDesc('created_at')->orderByDesc('id')->first();
            if ($before) {
                $snapshot = json_decode($before->new_values, true, flags: JSON_THROW_ON_ERROR);
            } else {
                $after = (clone $changes)->where('created_at', '>', $auditTime)->orderBy('created_at')->orderBy('id')->first();
                // The first audit cannot establish an unlimited history before rollout.
                if ($after && CarbonImmutable::parse($after->created_at, config('app.timezone'))->setTimezone('Asia/Dushanbe')->toDateString()
                    === $occurredAt->setTimezone('Asia/Dushanbe')->toDateString()) {
                    $snapshot = json_decode($after->old_values, true, flags: JSON_THROW_ON_ERROR);
                }
            }
        }
        if ($snapshot === null && $occurredAt->setTimezone('Asia/Dushanbe')->toDateString() === now('Asia/Dushanbe')->toDateString()) {
            $snapshot = $user->only(['branch_id', 'branch_group_id', 'role_id']);
        }
        return $snapshot;
    }
}
