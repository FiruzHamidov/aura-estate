<?php

namespace App\Support\Notifications;

final class NotificationPriority
{
    public const LOW = 1;
    public const MEDIUM = 2;
    public const HIGH = 3;
    public const URGENT = 4;

    public static function all(): array
    {
        return [
            self::LOW,
            self::MEDIUM,
            self::HIGH,
            self::URGENT,
        ];
    }
}
