<?php

namespace App\Services\Attendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** Attendance privacy is independent of configurable role permissions and historical scope. */
final class AttendancePrivacy
{
    public const FIELD = 'attendance_hidden_from_others';
    public const HIDDEN_FROM_EVERYONE = 'attendance_hidden_from_everyone';

    public function isSuperadmin(User $viewer): bool
    {
        return $viewer->role?->slug === 'superadmin';
    }

    private function applies(User $viewer): bool
    {
        return Schema::hasColumn('users', self::HIDDEN_FROM_EVERYONE)
            || (! $this->isSuperadmin($viewer) && Schema::hasColumn('users', self::FIELD));
    }

    public function users(Builder $query, User $viewer): Builder
    {
        return $this->applies($viewer) ? $query->whereNotIn('users.id', $this->hiddenIds($viewer)) : $query;
    }

    public function records(Builder $query, User $viewer): Builder
    {
        return $this->applies($viewer)
            ? $query->whereNotIn($query->getModel()->qualifyColumn('user_id'), $this->hiddenIds($viewer))
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
                ->whereIn('private_link.user_id', $this->hiddenIds($viewer)));
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
                ->whereIn('user_id', $this->hiddenIds($viewer))->exists(), 403, 'Нет доступа к посещениям сотрудника.');
        }
    }

    public function assertExportable(User $viewer, array $userIds): void
    {
        $fresh = User::query()->with('role')->find($viewer->id);
        abort_unless($fresh && $fresh->status === User::STATUS_ACTIVE, 409, 'ACCESS_SCOPE_VERSION_CONFLICT');
        if ($this->applies($fresh)) {
            abort_if($this->hiddenIds($fresh)->whereIn('users.id', $userIds)->exists(), 409, 'ACCESS_SCOPE_VERSION_CONFLICT');
        }
    }

    public function settingsForMutation(Request $request, User $viewer): array
    {
        $fields = array_filter([self::FIELD, self::HIDDEN_FROM_EVERYONE], fn ($field) => $request->exists($field));
        if ($fields === []) {
            return [];
        }
        abort_unless($this->isSuperadmin($viewer), 403, 'Только суперадминистратор может менять видимость посещаемости.');

        $settings = [];
        foreach ($fields as $field) $settings[$field] = $request->boolean($field);
        return $settings;
    }

    public function exposeSetting(User $target, User $viewer): User
    {
        $fields = [self::FIELD, self::HIDDEN_FROM_EVERYONE];
        return $this->isSuperadmin($viewer) ? $target->makeVisible($fields) : $target->makeHidden($fields);
    }

    private function hiddenIds(User $viewer): Builder
    {
        return User::query()->where(function (Builder $users) use ($viewer) {
            $users->whereRaw('1 = 0');
            if (Schema::hasColumn('users', self::HIDDEN_FROM_EVERYONE)) {
                $users->orWhere(self::HIDDEN_FROM_EVERYONE, true);
            }
            if (! $this->isSuperadmin($viewer) && Schema::hasColumn('users', self::FIELD)) {
                $users->orWhere(self::FIELD, true);
            }
        })->select('users.id');
    }
}
