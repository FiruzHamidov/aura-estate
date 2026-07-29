<?php

use App\Models\User;
use App\Services\LocationTracking\LocationAccessService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('location.user.{targetUserId}', function (User $viewer, int $targetUserId) {
    $target = User::query()->find($targetUserId);

    return $target !== null && app(LocationAccessService::class)->canView($viewer, $target);
});
