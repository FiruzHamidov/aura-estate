<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\FirebasePushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendFirebasePushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $notificationId) {}

    public function handle(FirebasePushService $firebase): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($firebase): void {
            $notification = Notification::query()->find($this->notificationId);
            // Serialize delivery with assignment/role changes on the recipient row.
            $recipient = $notification?->recipient()->lockForUpdate()->first();
            if ($notification && $recipient && app(\App\Services\GroupAccess\NotificationGroupAccess::class)->allows($notification, $recipient)) {
                $firebase->send($notification);
            }
        });
    }
}
