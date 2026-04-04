<?php

namespace App\Support\Notifications;

final class NotificationCategory
{
    public const CRITICAL = 'critical';
    public const WORKFLOW = 'workflow';
    public const INFO = 'info';
    public const MOTIVATION = 'motivation';

    public static function all(): array
    {
        return [
            self::CRITICAL,
            self::WORKFLOW,
            self::INFO,
            self::MOTIVATION,
        ];
    }
}
