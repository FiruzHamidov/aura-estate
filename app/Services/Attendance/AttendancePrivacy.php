<?php

namespace App\Services\Attendance;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** Attendance privacy is independent of configurable role permissions and historical scope. */
final class AttendancePrivacy
{
    public const FIELD = 'attendance_hidden_from_others';

    public function isSuperadmin(User $viewer): bool
    {
        return $viewer->role?->slug === 'superadmin';
    }

    private function applies(User $viewer): bool
    {
        // Older installations have no private records until the migration is applied.
        return ! $this->isSuperadmin($viewer) && Schema::hasColumn('users', self::FIELD);
    }

    public function users(Builder $query, User $viewer): Builder
    {
        return $this->applies($viewer) ? $query->where('users.'.self::FIELD, false) : $query;
    }

    public function records(Builder $query, User $viewer): Builder
    {
        return $this->applies($viewer)
            ? $query->whereNotIn($query->getModel()->qualifyColumn('user_id'), $this->hiddenIds())
            : $query;
    }

    /** Inactive mappings and historical terminal links also identify private raw marks. */
    public function terminalRecords(Builder $query, User $viewer): Builder
    {
        if (! $this->applies($viewer)) {
            return $query;
        }
        $table = $query->getModel()->getTable();
        foreach (['attendance_device_users', 'attendance_events'] as $links) {
            $query->whereNotExists(fn ($link) => $link->selectRaw('1')->from($links.' as private_link')
                ->whereColumn('private_link.device_id', $table.'.device_id')
                ->whereColumn('private_link.device_user_id', $table.'.device_user_id')
                ->whereIn('private_link.user_id', $this->hiddenIds()));
        }

        return $query;
    }

    public function assertCanAccessTerminal(User $viewer, int $deviceId, string $terminalUserId): void
    {
        if (! $this->applies($viewer)) {
            return;
        }
        foreach ([\App\Models\AttendanceDeviceUser::class, \App\Models\AttendanceEvent::class] as $model) {
            abort_if($model::query()->where('device_id', $deviceId)->where('device_user_id', $terminalUserId)
                ->whereIn('user_id', $this->hiddenIds())->exists(), 403, 'Нет доступа к посещениям сотрудника.');
        }
    }

    public function assertExportable(User $viewer, array $userIds): void
    {
        $fresh = User::query()->with('role')->find($viewer->id);
        abort_unless($fresh && $fresh->status === User::STATUS_ACTIVE, 409, 'ACCESS_SCOPE_VERSION_CONFLICT');
        if ($this->applies($fresh)) {
            abort_if($this->hiddenIds()->whereIn('users.id', $userIds)->exists(), 409, 'ACCESS_SCOPE_VERSION_CONFLICT');
        }
    }

    public function settingsForMutation(Request $request, User $viewer, ?Role $targetRole, ?User $target = null): array
    {
        if (! $request->exists(self::FIELD)) {
            return [];
        }
        abort_unless($this->isSuperadmin($viewer), 403, 'Только суперадминистратор может менять видимость посещаемости.');
        // A role change must not implicitly disclose old attendance. A superadmin can clear the retained flag.
        abort_unless($targetRole?->slug === 'superadmin' || ($target?->attendance_hidden_from_others && ! $request->boolean(self::FIELD)),
            422, 'Настройка скрытия посещаемости доступна для суперадминистраторов.');

        return [self::FIELD => $request->boolean(self::FIELD)];
    }

    public function exposeSetting(User $target, User $viewer): User
    {
        return $this->isSuperadmin($viewer) ? $target->makeVisible(self::FIELD) : $target->makeHidden(self::FIELD);
    }

    private function hiddenIds(): Builder
    {
        return User::query()->where(self::FIELD, true)->select('users.id');
    }
}
