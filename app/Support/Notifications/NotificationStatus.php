<?php

namespace App\Support\Notifications;

final class NotificationStatus
{
    public const UNREAD = 'unread';
    public const READ = 'read';

    public static function all(): array
    {
        return [
            self::UNREAD,
            self::READ,
        ];
    }
}
