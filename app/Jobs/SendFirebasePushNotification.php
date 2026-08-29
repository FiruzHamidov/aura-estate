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
        $notification = Notification::query()->find($this->notificationId);

        if ($notification) {
            $firebase->send($notification);
        }
    }
}
