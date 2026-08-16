<?php

namespace App\Services\Attendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class AttendanceParticipantService
{
    public function query(): Builder
    {
        return User::query()->where(function (Builder $query) {
            $query->where('status', User::STATUS_ACTIVE)->orWhereNull('status');
        });
    }

    public function isEligible(User $user): bool
    {
        return $user->status === User::STATUS_ACTIVE;
    }

    public function assertEligible(User $user): void
    {
        if (! $this->isEligible($user)) {
            throw ValidationException::withMessages([
                'user_id' => ['Учёт посещаемости доступен только активным пользователям.'],
            ]);
        }
    }
}
