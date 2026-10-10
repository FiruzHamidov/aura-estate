<?php

namespace App\Services;

use App\Models\{Deal, Lead, Notification, Property, User};
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

        if ($permission !== null) return $recipient->hasPermission($permission);

        $resolver = app(NotificationRecipientResolver::class);
        $leadEvents = [NotificationType::LEAD_NEW, NotificationType::LEAD_STATUS_CHANGED,
            NotificationType::LEAD_QUALIFIED, NotificationType::LEAD_LOST, NotificationType::LEAD_CONVERTED,
            NotificationType::LEAD_SLA_DUE_SOON, NotificationType::LEAD_SLA_OVERDUE,
            NotificationType::LEAD_FOLLOW_UP_DUE, NotificationType::LEAD_FOLLOW_UP_OVERDUE,
            NotificationType::LEAD_INACTIVE, NotificationType::MOTIVATION_MANAGER_LEAD_HOT];
        if (in_array($notification->type, $leadEvents, true)) {
            $lead = $notification->subject;
            if (! $lead instanceof Lead) return false;
            $users = $resolver->leadManagers($lead);
            if ($notification->type === NotificationType::LEAD_NEW) {
                $audience = $notification->data['delivery_audience'] ?? null;
                if ($audience === 'public_lead') $users = $resolver->publicLeadSubscribers($lead);
                elseif ($audience === null) $users = $users->merge($resolver->publicLeadSubscribers($lead));
            }
            return $users->contains('id', $recipient->id);
        }
        if (in_array($notification->type, [NotificationType::PROPERTY_CONTROL_BRANCH_CLARIFICATION,
            NotificationType::PROPERTY_CONTROL_VERIFIED, NotificationType::PROPERTY_CONTROL_FLAGGED], true)) {
            $deal = $notification->subject;
            if (! $deal instanceof Deal) return false;
            $users = match ($notification->type) {
                NotificationType::PROPERTY_CONTROL_BRANCH_CLARIFICATION => $resolver->propertyControlBranchRecipients($deal),
                NotificationType::PROPERTY_CONTROL_VERIFIED => $resolver->propertyControlParticipants($deal),
                default => $resolver->propertyControlEscalationRecipients($deal),
            };
            return $users->contains('id', $recipient->id);
        }
        if (in_array($notification->type, [NotificationType::PROPERTY_STALE_21D, NotificationType::PROPERTY_STALE_30D], true)) {
            $property = $notification->subject;
            return $property instanceof Property && $resolver->propertyEscalationRecipients($property)->contains('id', $recipient->id);
        }
        // Personal assignments, chat messages and other non-subscription events keep their own policy.
        return true;
    }
}
