<?php

namespace App\Support\Notifications;

final class NotificationChannel
{
    public const IN_APP = 'in_app';
    public const PUSH = 'push';
    public const TELEGRAM = 'telegram';
    public const SMS = 'sms';
    public const EMAIL = 'email';

    public static function all(): array
    {
        return [
            self::IN_APP,
            self::PUSH,
            self::TELEGRAM,
            self::SMS,
            self::EMAIL,
        ];
    }
}
