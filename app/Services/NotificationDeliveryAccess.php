<?php

namespace App\Services;

use App\Models\{Notification, User};
use App\Support\Notifications\NotificationType;

/** Subscription-only events must recheck the current grant before external delivery. */
final class NotificationDeliveryAccess
{
    public function allows(Notification $notification, User $recipient): bool
    {
        $permission = match ($notification->type) {
            NotificationType::MOTIVATION_MANAGER_MORNING_DIGEST => 'notifications.receive.digest.leads.morning',
            NotificationType::MOTIVATION_MANAGER_EVENING_DIGEST => 'notifications.receive.digest.leads.evening',
            NotificationType::MOTIVATION_AGENT_DAY_PLAN => 'notifications.receive.digest.deals.morning',
            NotificationType::MOTIVATION_AGENT_EVENING_DIGEST => 'notifications.receive.digest.deals.evening',
            NotificationType::DAILY_REPORT_REMINDER => 'notifications.receive.daily_report',
            NotificationType::ATTENDANCE_DEVICE_OFFLINE => 'notifications.receive.attendance_offline',
            NotificationType::KPI_EARLY_RISK => 'notifications.receive.kpi_risk.branch',
            NotificationType::PROPERTY_CONTROL_NEW => 'notifications.receive.property_control.security',
            default => null,
        };

        return $permission === null || $recipient->hasPermission($permission);
    }
}
