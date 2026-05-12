<?php

namespace App\Support\Notifications;

final class NotificationType
{
    public const LEAD_NEW = 'lead_new';
    public const LEAD_ASSIGNED = 'lead_assigned';
    public const LEAD_SLA_DUE_SOON = 'lead_sla_due_soon';
    public const LEAD_SLA_OVERDUE = 'lead_sla_overdue';
    public const LEAD_INACTIVE = 'lead_inactive';
    public const LEAD_FOLLOW_UP_DUE = 'lead_follow_up_due';
    public const LEAD_FOLLOW_UP_OVERDUE = 'lead_follow_up_overdue';
    public const LEAD_STATUS_CHANGED = 'lead_status_changed';
    public const LEAD_QUALIFIED = 'lead_qualified';
    public const LEAD_LOST = 'lead_lost';
    public const LEAD_CONVERTED = 'lead_converted';

    public const DEAL_CREATED = 'deal_created';
    public const DEAL_ASSIGNED = 'deal_assigned';
    public const DEAL_STAGE_CHANGED = 'deal_stage_changed';
    public const DEAL_INACTIVE = 'deal_inactive';
    public const DEAL_DEADLINE_SOON = 'deal_deadline_soon';
    public const DEAL_ACTIVITY_OVERDUE = 'deal_activity_overdue';
    public const DEAL_WON = 'deal_won';
    public const DEAL_LOST = 'deal_lost';

    public const BOOKING_CREATED = 'booking_created';
    public const BOOKING_UPDATED = 'booking_updated';
    public const BOOKING_REMINDER_24H = 'booking_reminder_24h';
    public const BOOKING_REMINDER_30M = 'booking_reminder_30m';

    public const CHAT_NEW_MESSAGE = 'chat_new_message';
    public const CHAT_RESPONSE_OVERDUE = 'chat_response_overdue';

    public const SELECTION_VIEWED = 'selection_viewed';
    public const SELECTION_OPENED = 'selection_opened';
    public const SELECTION_SHOWING_REQUESTED = 'selection_showing_requested';
    public const FAVORITE_ADDED = 'favorite_added';

    public const MOTIVATION_MANAGER_LEAD_HOT = 'motivation_manager_lead_hot';
    public const MOTIVATION_MANAGER_LEAD_NO_NEXT_STEP = 'motivation_manager_lead_no_next_step';
    public const MOTIVATION_MANAGER_LOW_ACTIVITY = 'motivation_manager_low_activity';
    public const MOTIVATION_MANAGER_PLAN_ALMOST_DONE = 'motivation_manager_plan_almost_done';
    public const MOTIVATION_MANAGER_STREAK = 'motivation_manager_streak';
    public const MOTIVATION_MANAGER_MORNING_DIGEST = 'motivation_manager_morning_digest';
    public const MOTIVATION_MANAGER_EVENING_DIGEST = 'motivation_manager_evening_digest';

    public const MOTIVATION_AGENT_NEW_DEAL = 'motivation_agent_new_deal';
    public const MOTIVATION_AGENT_CLIENT_INTEREST = 'motivation_agent_client_interest';
    public const MOTIVATION_AGENT_DAY_PLAN = 'motivation_agent_day_plan';
    public const MOTIVATION_AGENT_DEAL_CLOSE_SOON = 'motivation_agent_deal_close_soon';
    public const MOTIVATION_AGENT_PLAN_ALMOST_DONE = 'motivation_agent_plan_almost_done';
    public const MOTIVATION_AGENT_DEAL_WON = 'motivation_agent_deal_won';
    public const MOTIVATION_AGENT_STREAK = 'motivation_agent_streak';
    public const MOTIVATION_AGENT_EVENING_DIGEST = 'motivation_agent_evening_digest';
    public const KPI_EARLY_RISK = 'kpi_early_risk';
    public const DAILY_REPORT_REMINDER = 'daily_report_reminder';

    public static function all(): array
    {
        return [
            self::LEAD_NEW,
            self::LEAD_ASSIGNED,
            self::LEAD_SLA_DUE_SOON,
            self::LEAD_SLA_OVERDUE,
            self::LEAD_INACTIVE,
            self::LEAD_FOLLOW_UP_DUE,
            self::LEAD_FOLLOW_UP_OVERDUE,
            self::LEAD_STATUS_CHANGED,
            self::LEAD_QUALIFIED,
            self::LEAD_LOST,
            self::LEAD_CONVERTED,
            self::DEAL_CREATED,
            self::DEAL_ASSIGNED,
            self::DEAL_STAGE_CHANGED,
            self::DEAL_INACTIVE,
            self::DEAL_DEADLINE_SOON,
            self::DEAL_ACTIVITY_OVERDUE,
            self::DEAL_WON,
            self::DEAL_LOST,
            self::BOOKING_CREATED,
            self::BOOKING_UPDATED,
            self::BOOKING_REMINDER_24H,
            self::BOOKING_REMINDER_30M,
            self::CHAT_NEW_MESSAGE,
            self::CHAT_RESPONSE_OVERDUE,
            self::SELECTION_VIEWED,
            self::SELECTION_OPENED,
            self::SELECTION_SHOWING_REQUESTED,
            self::FAVORITE_ADDED,
            self::MOTIVATION_MANAGER_LEAD_HOT,
            self::MOTIVATION_MANAGER_LEAD_NO_NEXT_STEP,
            self::MOTIVATION_MANAGER_LOW_ACTIVITY,
            self::MOTIVATION_MANAGER_PLAN_ALMOST_DONE,
            self::MOTIVATION_MANAGER_STREAK,
            self::MOTIVATION_MANAGER_MORNING_DIGEST,
            self::MOTIVATION_MANAGER_EVENING_DIGEST,
            self::MOTIVATION_AGENT_NEW_DEAL,
            self::MOTIVATION_AGENT_CLIENT_INTEREST,
            self::MOTIVATION_AGENT_DAY_PLAN,
            self::MOTIVATION_AGENT_DEAL_CLOSE_SOON,
            self::MOTIVATION_AGENT_PLAN_ALMOST_DONE,
            self::MOTIVATION_AGENT_DEAL_WON,
            self::MOTIVATION_AGENT_STREAK,
            self::MOTIVATION_AGENT_EVENING_DIGEST,
            self::KPI_EARLY_RISK,
            self::DAILY_REPORT_REMINDER,
        ];
    }

    public static function category(string $type): string
    {
        return match ($type) {
            self::LEAD_SLA_OVERDUE,
            self::LEAD_FOLLOW_UP_OVERDUE,
            self::DEAL_ACTIVITY_OVERDUE,
            self::KPI_EARLY_RISK,
            self::CHAT_NEW_MESSAGE,
            self::CHAT_RESPONSE_OVERDUE => NotificationCategory::CRITICAL,

            self::MOTIVATION_MANAGER_LEAD_HOT,
            self::MOTIVATION_MANAGER_LEAD_NO_NEXT_STEP,
            self::MOTIVATION_MANAGER_LOW_ACTIVITY,
            self::MOTIVATION_MANAGER_PLAN_ALMOST_DONE,
            self::MOTIVATION_MANAGER_STREAK,
            self::MOTIVATION_MANAGER_MORNING_DIGEST,
            self::MOTIVATION_MANAGER_EVENING_DIGEST,
            self::MOTIVATION_AGENT_NEW_DEAL,
            self::MOTIVATION_AGENT_CLIENT_INTEREST,
            self::MOTIVATION_AGENT_DAY_PLAN,
            self::MOTIVATION_AGENT_DEAL_CLOSE_SOON,
            self::MOTIVATION_AGENT_PLAN_ALMOST_DONE,
            self::MOTIVATION_AGENT_DEAL_WON,
            self::MOTIVATION_AGENT_STREAK,
            self::MOTIVATION_AGENT_EVENING_DIGEST,
            self::DAILY_REPORT_REMINDER => NotificationCategory::MOTIVATION,

            self::SELECTION_VIEWED,
            self::SELECTION_OPENED,
            self::SELECTION_SHOWING_REQUESTED,
            self::FAVORITE_ADDED => NotificationCategory::INFO,

            default => NotificationCategory::WORKFLOW,
        };
    }

    public static function defaultChannels(string $type): array
    {
        return match (self::category($type)) {
            NotificationCategory::CRITICAL => [NotificationChannel::IN_APP, NotificationChannel::PUSH],
            NotificationCategory::MOTIVATION => [NotificationChannel::IN_APP],
            NotificationCategory::INFO => [NotificationChannel::IN_APP],
            default => [NotificationChannel::IN_APP],
        };
    }

    public static function defaultPriority(string $type): int
    {
        return match (self::category($type)) {
            NotificationCategory::CRITICAL => NotificationPriority::URGENT,
            NotificationCategory::MOTIVATION => NotificationPriority::LOW,
            NotificationCategory::INFO => NotificationPriority::MEDIUM,
            default => NotificationPriority::HIGH,
        };
    }

    public static function defaultQuietWindowMinutes(string $type): int
    {
        return match ($type) {
            self::CHAT_NEW_MESSAGE => 5,
            self::SELECTION_OPENED,
            self::SELECTION_VIEWED,
            self::FAVORITE_ADDED => 30,
            self::MOTIVATION_MANAGER_MORNING_DIGEST,
            self::MOTIVATION_MANAGER_EVENING_DIGEST,
            self::MOTIVATION_AGENT_EVENING_DIGEST => 360,
            default => 15,
        };
    }
}
