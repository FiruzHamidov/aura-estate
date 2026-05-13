<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ConversationMessage;
use App\Models\DailyReport;
use App\Models\Deal;
use App\Models\Favorite;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Selection;
use App\Models\User;
use App\Models\UserDailyReportReminderSetting;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationChannel;
use App\Support\Notifications\NotificationStatus;
use App\Support\Notifications\NotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class NotificationService
{
    public function __construct(
        private readonly NotificationRecipientResolver $recipients,
        private readonly TelegramBotService $telegramBot
    ) {}

    public function markAsRead(Notification $notification, User $user): Notification
    {
        if (! $this->notificationsTableExists()) {
            return $notification;
        }

        abort_unless((int) $notification->user_id === (int) $user->id, 403, 'Forbidden');

        if (! $notification->read_at) {
            $notification->forceFill([
                'read_at' => now(),
                'status' => NotificationStatus::READ,
            ])->save();
        }

        return $notification->fresh(['actor.role']);
    }

    public function markAllAsRead(User $user, ?string $category = null): int
    {
        if (! $this->notificationsTableExists()) {
            return 0;
        }

        return Notification::query()
            ->where('user_id', $user->id)
            ->when($category, fn ($query) => $query->where('category', $category))
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'status' => NotificationStatus::READ,
                'updated_at' => now(),
            ]);
    }

    public function unreadCount(User $user): int
    {
        if (! $this->notificationsTableExists()) {
            return 0;
        }

        return Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    public function handleLeadCreated(Lead $lead, ?User $actor = null): void
    {
        $this->notifyUsers(
            $this->recipients->leadManagers($lead),
            NotificationType::LEAD_NEW,
            'Новый лид',
            sprintf('Поступил новый лид%s.', $lead->full_name ? ': '.$lead->full_name : ''),
            $lead,
            $actor,
            [
                'action_url' => '/leads/'.$lead->id,
                'action_type' => 'open_lead',
                'dedupe_key' => 'lead:new:'.$lead->id,
                'data' => [
                    'lead_id' => $lead->id,
                    'status' => $lead->status,
                    'source' => $lead->source,
                ],
            ]
        );

        $this->notifyLeadAssigned($lead, $actor);
    }

    public function handleLeadUpdated(Lead $lead, ?User $actor, array $oldValues, array $dirty): void
    {
        if (array_key_exists('responsible_agent_id', $dirty)) {
            $this->notifyLeadAssigned($lead, $actor);
        }

        if (array_key_exists('status', $dirty)) {
            $oldStatus = $oldValues['status'] ?? null;

            $this->notifyUsers(
                $this->recipients->leadManagers($lead),
                NotificationType::LEAD_STATUS_CHANGED,
                'Статус лида обновлён',
                sprintf('Лид #%d переведён из "%s" в "%s".', $lead->id, (string) $oldStatus, (string) $lead->status),
                $lead,
                $actor,
                [
                    'action_url' => '/leads/'.$lead->id,
                    'action_type' => 'open_lead',
                    'dedupe_key' => 'lead:status:'.$lead->id,
                    'data' => [
                        'lead_id' => $lead->id,
                        'old_status' => $oldStatus,
                        'new_status' => $lead->status,
                    ],
                ]
            );

            if ($lead->status === Lead::STATUS_QUALIFIED) {
                $this->notifyUsers(
                    $this->recipients->leadManagers($lead),
                    NotificationType::LEAD_QUALIFIED,
                    'Лид квалифицирован',
                    sprintf('Лид #%d готов к следующему этапу.', $lead->id),
                    $lead,
                    $actor,
                    [
                        'action_url' => '/leads/'.$lead->id,
                        'action_type' => 'open_lead',
                        'dedupe_key' => 'lead:qualified:'.$lead->id,
                    ]
                );
            }

            if ($lead->status === Lead::STATUS_LOST) {
                $this->notifyUsers(
                    $this->recipients->leadManagers($lead),
                    NotificationType::LEAD_LOST,
                    'Лид потерян',
                    sprintf('Лид #%d отмечен как потерянный.', $lead->id),
                    $lead,
                    $actor,
                    [
                        'action_url' => '/leads/'.$lead->id,
                        'action_type' => 'open_lead',
                        'dedupe_key' => 'lead:lost:'.$lead->id,
                        'data' => ['lost_reason' => $lead->lost_reason],
                    ]
                );
            }
        }
    }

    public function handleLeadConverted(Lead $lead, ?User $actor = null): void
    {
        $this->notifyUsers(
            $this->recipients->leadManagers($lead),
            NotificationType::LEAD_CONVERTED,
            'Лид конвертирован',
            sprintf('Лид #%d успешно конвертирован в клиента.', $lead->id),
            $lead,
            $actor,
            [
                'action_url' => '/leads/'.$lead->id,
                'action_type' => 'open_lead',
                'dedupe_key' => 'lead:converted:'.$lead->id,
                'data' => [
                    'lead_id' => $lead->id,
                    'client_id' => $lead->client_id,
                ],
            ]
        );
    }

    public function handleDealCreated(Deal $deal, ?User $actor = null): void
    {
        $stakeholders = $this->recipients->dealStakeholders($deal);

        $this->notifyUsers(
            $stakeholders,
            NotificationType::DEAL_CREATED,
            'Создана сделка',
            sprintf('Создана сделка "%s".', $deal->title ?: 'Без названия'),
            $deal,
            $actor,
            [
                'action_url' => '/deals/'.$deal->id,
                'action_type' => 'open_deal',
                'dedupe_key' => 'deal:created:'.$deal->id,
                'data' => [
                    'deal_id' => $deal->id,
                    'lead_id' => $deal->lead_id,
                ],
            ]
        );

        if ($deal->responsible_agent_id) {
            $this->notifyUsers(
                collect([$deal->responsibleAgent]),
                NotificationType::DEAL_ASSIGNED,
                'Новая сделка в работе',
                sprintf('Вам назначена сделка "%s".', $deal->title ?: 'Без названия'),
                $deal,
                $actor,
                [
                    'action_url' => '/deals/'.$deal->id,
                    'action_type' => 'open_deal',
                    'dedupe_key' => 'deal:assigned:'.$deal->id.':'.$deal->responsible_agent_id,
                ]
            );

            $this->notifyUsers(
                collect([$deal->responsibleAgent]),
                NotificationType::MOTIVATION_AGENT_NEW_DEAL,
                'Новая сделка передана вам',
                'Новый клиент готов к работе. Зафиксируйте следующий шаг, пока интерес высокий.',
                $deal,
                $actor,
                [
                    'action_url' => '/deals/'.$deal->id,
                    'action_type' => 'open_deal',
                    'dedupe_key' => 'deal:motivation:new:'.$deal->id.':'.$deal->responsible_agent_id,
                ]
            );
        }
    }

    public function handleDealUpdated(Deal $deal, ?User $actor, array $oldValues, array $dirty): void
    {
        if (array_key_exists('responsible_agent_id', $dirty)) {
            $this->notifyUsers(
                collect([$deal->responsibleAgent]),
                NotificationType::DEAL_ASSIGNED,
                'Сделка назначена на вас',
                sprintf('Вам передана сделка "%s".', $deal->title ?: 'Без названия'),
                $deal,
                $actor,
                [
                    'action_url' => '/deals/'.$deal->id,
                    'action_type' => 'open_deal',
                    'dedupe_key' => 'deal:assigned:'.$deal->id.':'.$deal->responsible_agent_id,
                ]
            );
        }

        if (array_key_exists('stage_id', $dirty) || array_key_exists('pipeline_id', $dirty) || array_key_exists('closed_at', $dirty)) {
            $deal->loadMissing('stage');

            $this->notifyUsers(
                $this->recipients->dealStakeholders($deal),
                NotificationType::DEAL_STAGE_CHANGED,
                'Стадия сделки обновлена',
                sprintf('Сделка "%s" переведена на стадию "%s".', $deal->title ?: 'Без названия', $deal->stage?->name ?: 'Новая стадия'),
                $deal,
                $actor,
                [
                    'action_url' => '/deals/'.$deal->id,
                    'action_type' => 'open_deal',
                    'dedupe_key' => 'deal:stage:'.$deal->id,
                    'data' => [
                        'deal_id' => $deal->id,
                        'stage_id' => $deal->stage_id,
                    ],
                ]
            );

            if ($deal->is_closed) {
                $type = $deal->lost_reason ? NotificationType::DEAL_LOST : NotificationType::DEAL_WON;
                $title = $deal->lost_reason ? 'Сделка проиграна' : 'Сделка закрыта';
                $body = $deal->lost_reason
                    ? sprintf('Сделка "%s" закрыта как потерянная.', $deal->title ?: 'Без названия')
                    : sprintf('Сделка "%s" успешно закрыта.', $deal->title ?: 'Без названия');

                $this->notifyUsers(
                    $this->recipients->dealStakeholders($deal),
                    $type,
                    $title,
                    $body,
                    $deal,
                    $actor,
                    [
                        'action_url' => '/deals/'.$deal->id,
                        'action_type' => 'open_deal',
                        'dedupe_key' => 'deal:closed:'.$deal->id,
                    ]
                );

                if (! $deal->lost_reason && $deal->responsibleAgent) {
                    $this->notifyUsers(
                        collect([$deal->responsibleAgent]),
                        NotificationType::MOTIVATION_AGENT_DEAL_WON,
                        'Сделка успешно закрыта',
                        'Отличная работа. Зафиксируйте результат и удержите темп следующей сделкой.',
                        $deal,
                        $actor,
                        [
                            'action_url' => '/deals/'.$deal->id,
                            'action_type' => 'open_deal',
                            'dedupe_key' => 'deal:motivation:won:'.$deal->id,
                        ]
                    );
                }
            }
        }
    }

    public function handleBookingCreated(Booking $booking, ?User $actor = null): void
    {
        $this->notifyUsers(
            $this->recipients->bookingAgent($booking),
            NotificationType::BOOKING_CREATED,
            'Назначен новый показ',
            'У вас появился новый показ. Проверьте детали и подтвердите следующий шаг с клиентом.',
            $booking,
            $actor,
            [
                'action_url' => '/bookings/'.$booking->id,
                'action_type' => 'open_booking',
                'dedupe_key' => 'booking:created:'.$booking->id,
                'data' => [
                    'booking_id' => $booking->id,
                    'start_time' => $booking->start_time,
                    'end_time' => $booking->end_time,
                ],
            ]
        );
    }

    public function handleBookingUpdated(Booking $booking, ?User $actor = null, array $oldValues = []): void
    {
        $this->notifyUsers(
            $this->recipients->bookingAgent($booking),
            NotificationType::BOOKING_UPDATED,
            'Показ обновлён',
            'Детали показа были изменены. Проверьте время и комментарий к встрече.',
            $booking,
            $actor,
            [
                'action_url' => '/bookings/'.$booking->id,
                'action_type' => 'open_booking',
                'dedupe_key' => 'booking:updated:'.$booking->id,
                'data' => [
                    'booking_id' => $booking->id,
                    'old_values' => $oldValues,
                ],
            ]
        );
    }

    public function handleConversationMessageCreated(ConversationMessage $message): void
    {
        $message->loadMissing('conversation', 'author.role');

        $recipients = $this->recipients->conversationParticipants($message->conversation, $message->author_id);
        $channels = NotificationType::defaultChannels(NotificationType::CHAT_NEW_MESSAGE);

        Log::info('Handling chat_new_message notification.', [
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'author_id' => $message->author_id,
            'recipient_ids' => $recipients->pluck('id')->values()->all(),
            'channels' => $channels,
            'notifications_table_exists' => $this->notificationsTableExists(),
        ]);

        if (! in_array(NotificationChannel::TELEGRAM, $channels, true)) {
            Log::warning('Telegram delivery is not configured for chat_new_message notifications.', [
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                'configured_channels' => $channels,
                'reason' => 'NotificationType::defaultChannels(chat_new_message) does not include telegram.',
            ]);
        }

        $this->notifyUsers(
            $recipients,
            NotificationType::CHAT_NEW_MESSAGE,
            'Новое сообщение от клиента',
            mb_strimwidth((string) $message->body, 0, 120, '...'),
            $message->conversation,
            $message->author,
            [
                'action_url' => '/conversations/'.$message->conversation_id,
                'action_type' => 'open_conversation',
                'dedupe_key' => 'chat:new:'.$message->conversation_id,
                'data' => [
                    'conversation_id' => $message->conversation_id,
                    'message_id' => $message->id,
                ],
            ]
        );
    }

    public function handleSelectionEvent(Selection $selection, string $eventType, ?array $payload = null): void
    {
        $type = match ($eventType) {
            'viewed' => NotificationType::SELECTION_VIEWED,
            'opened' => NotificationType::SELECTION_OPENED,
            'requested_showing' => NotificationType::SELECTION_SHOWING_REQUESTED,
            default => null,
        };

        if (! $type) {
            return;
        }

        $title = match ($eventType) {
            'viewed' => 'Клиент открыл подборку',
            'opened' => 'Клиент открыл объект из подборки',
            'requested_showing' => 'Клиент запросил показ из подборки',
        };

        $body = match ($eventType) {
            'viewed' => 'Подборка привлекла внимание клиента. Хороший момент для контакта.',
            'opened' => 'Клиент перешёл к объекту из подборки. Интерес к предложению растёт.',
            'requested_showing' => 'Клиент хочет показ по объекту из подборки.',
        };

        $this->notifyUsers(
            $this->recipients->selectionOwner($selection),
            $type,
            $title,
            $body,
            $selection,
            null,
            [
                'action_url' => '/selections/'.$selection->id,
                'action_type' => 'open_selection',
                'dedupe_key' => 'selection:'.$eventType.':'.$selection->id,
                'data' => [
                    'selection_id' => $selection->id,
                    'payload' => $payload,
                ],
            ]
        );

        if (in_array($eventType, ['viewed', 'opened'], true)) {
            $this->notifyUsers(
                $this->recipients->selectionOwner($selection),
                NotificationType::MOTIVATION_AGENT_CLIENT_INTEREST,
                'Клиент проявляет интерес',
                'Клиент активно взаимодействует с подборкой. Самое время предложить показ или уточнить детали.',
                $selection,
                null,
                [
                    'action_url' => '/selections/'.$selection->id,
                    'action_type' => 'open_selection',
                    'dedupe_key' => 'selection:motivation:'.$selection->id,
                ]
            );
        }
    }

    public function handleFavoriteAdded(Favorite $favorite): void
    {
        $favorite->loadMissing('property.agent.role', 'property.creator.role', 'user.role');

        $recipient = $favorite->property?->agent ?: $favorite->property?->creator;

        if (! $recipient instanceof User) {
            return;
        }

        $this->notifyUsers(
            collect([$recipient]),
            NotificationType::FAVORITE_ADDED,
            'Объект добавлен в избранное',
            sprintf(
                'Клиент%s добавил объект "%s" в избранное.',
                $favorite->user?->name ? ' '.$favorite->user->name : '',
                $favorite->property?->title ?: 'Без названия'
            ),
            $favorite->property,
            $favorite->user,
            [
                'action_url' => '/properties/'.$favorite->property_id,
                'action_type' => 'open_property',
                'dedupe_key' => 'favorite:property:'.$favorite->property_id.':recipient:'.$recipient->id,
                'data' => [
                    'property_id' => $favorite->property_id,
                    'client_user_id' => $favorite->user_id,
                ],
            ]
        );
    }

    public function dispatchScheduledReminders(): array
    {
        return [
            'lead_sla_due_soon' => $this->dispatchLeadSlaDueSoon(),
            'lead_sla_overdue' => $this->dispatchLeadSlaOverdue(),
            'lead_follow_up_due' => $this->dispatchLeadFollowUpDue(),
            'lead_follow_up_overdue' => $this->dispatchLeadFollowUpOverdue(),
            'lead_inactive' => $this->dispatchLeadInactive(),
            'deal_deadline_soon' => $this->dispatchDealDeadlineSoon(),
            'deal_activity_overdue' => $this->dispatchDealActivityOverdue(),
            'booking_reminder_24h' => $this->dispatchBookingReminderWindow(NotificationType::BOOKING_REMINDER_24H, 24 * 60, 30),
            'booking_reminder_30m' => $this->dispatchBookingReminderWindow(NotificationType::BOOKING_REMINDER_30M, 30, 10),
            'motivation_manager_morning_digest' => $this->dispatchManagerMorningDigest(),
            'motivation_agent_day_plan' => $this->dispatchAgentDayPlan(),
            'motivation_manager_evening_digest' => $this->dispatchManagerEveningDigest(),
            'motivation_agent_evening_digest' => $this->dispatchAgentEveningDigest(),
            'kpi_early_risk' => $this->dispatchKpiEarlyRisk(),
            'daily_report_reminder' => $this->dispatchDailyReportReminders(),
        ];
    }

    private function dispatchDailyReportReminders(): int
    {
        if (! Schema::hasTable('daily_reports') || ! Schema::hasTable('user_daily_report_reminder_settings')) {
            return 0;
        }

        $nowUtc = now()->setTimezone('UTC');
        $sent = 0;

        $settings = UserDailyReportReminderSetting::query()
            ->with(['user.role'])
            ->where('enabled', true)
            ->get();

        foreach ($settings as $setting) {
            $user = $setting->user;
            if (! $user || $user->status !== User::STATUS_ACTIVE) {
                continue;
            }

            if (! in_array($user->role?->slug, ['agent', 'mop'], true)) {
                continue;
            }

            $timezone = $setting->timezone ?: 'Asia/Dushanbe';
            try {
                $localNow = $nowUtc->copy()->setTimezone($timezone);
            } catch (\Throwable) {
                $localNow = $nowUtc->copy()->setTimezone('Asia/Dushanbe');
                $timezone = 'Asia/Dushanbe';
            }

            $parts = explode(':', (string) $setting->remind_time);
            if (count($parts) !== 2) {
                continue;
            }

            $remindAt = $localNow->copy()->setTime((int) $parts[0], (int) $parts[1], 0);
            if ($localNow->lt($remindAt) || $localNow->gte($remindAt->copy()->addMinutes(5))) {
                continue;
            }

            $reportDate = $localNow->toDateString();
            $submitted = DailyReport::query()
                ->where('user_id', $user->id)
                ->whereDate('report_date', $reportDate)
                ->whereNotNull('submitted_at')
                ->exists();

            if ($submitted) {
                continue;
            }

            $this->notifyUsers(
                [$user],
                NotificationType::DAILY_REPORT_REMINDER,
                'Ежедневный KPI-отчёт ещё не сдан',
                'Заполните ежедневку сегодня, чтобы зафиксировать KPI за день.',
                null,
                null,
                [
                    'category' => NotificationCategory::MOTIVATION,
                    'channels' => array_values($setting->channels ?? [NotificationChannel::IN_APP]),
                    'action_url' => '/daily-report',
                    'action_type' => 'open_daily_report',
                    'dedupe_key' => 'daily-report:reminder:'.$user->id.':'.$reportDate,
                    'data' => [
                        'report_date' => $reportDate,
                        'timezone' => $timezone,
                    ],
                    'quiet_window_minutes' => 24 * 60,
                ]
            );
            $sent++;
        }

        return $sent;
    }

    private function dispatchKpiEarlyRisk(): int
    {
        if (! $this->isWithinDailyWindow('18:30', 5)) {
            return 0;
        }

        if (! Schema::hasTable('daily_reports')) {
            return 0;
        }

        $threshold = (float) (config('kpi.status_thresholds.risk', 0.6) + 0.2); // < 0.8
        $metricsConfig = (array) config('kpi.metrics', []);
        $today = now()->startOfDay();

        $reportsByUser = DailyReport::query()
            ->with('user.role')
            ->whereDate('report_date', '>=', $today->copy()->subDays(7)->toDateString())
            ->get()
            ->groupBy('user_id');

        $sent = 0;

        foreach ($reportsByUser as $userReports) {
            /** @var DailyReport|null $latest */
            $latest = $userReports->sortByDesc('report_date')->first();
            $agent = $latest?->user;

            if (! $agent instanceof User || ! in_array($agent->role?->slug, ['agent', 'mop'], true)) {
                continue;
            }

            $workingReports = $userReports
                ->sortByDesc('report_date')
                ->filter(function (DailyReport $report) {
                    $dayOfWeek = $report->report_date->dayOfWeek ?? null;

                    return $dayOfWeek !== Carbon::SUNDAY;
                })
                ->take(2)
                ->values();

            if ($workingReports->count() < 2) {
                continue;
            }

            $kpis = $workingReports->map(fn (DailyReport $report) => $this->calculateKpiValue($report, $metricsConfig));
            if ($kpis->filter(fn (float $kpi) => $kpi < $threshold)->count() !== 2) {
                continue;
            }

            $receivers = User::query()
                ->with('role')
                ->where('status', User::STATUS_ACTIVE)
                ->where('branch_id', $agent->branch_id)
                ->where(function ($q) use ($agent) {
                    $q->whereIn('role_id', function ($sub) {
                        $sub->select('id')->from('roles')->whereIn('slug', ['rop', 'branch_director']);
                    });
                })
                ->get();

            if ($receivers->isEmpty()) {
                continue;
            }

            $latestReport = $workingReports->first();
            $previousReport = $workingReports->get(1);
            $message = sprintf(
                'Ранний риск KPI: %s (%s) — два рабочих дня подряд ниже 0.8 (%.2f и %.2f).',
                $agent->name,
                $agent->role?->slug ?? 'agent',
                $kpis->get(0),
                $kpis->get(1)
            );

            $this->notifyUsers(
                $receivers,
                NotificationType::KPI_EARLY_RISK,
                'Ранний риск KPI',
                $message,
                null,
                null,
                [
                    'channels' => [NotificationChannel::IN_APP, NotificationChannel::TELEGRAM],
                    'action_url' => $this->absoluteFrontendUrl('/kpi-reports?period_type=day&user_id='.$agent->id),
                    'action_type' => 'open_kpi_report',
                    'dedupe_key' => 'kpi:early-risk:'.$agent->id.':'.$latestReport->report_date->toDateString(),
                    'quiet_window_minutes' => 24 * 60,
                    'data' => [
                        'agent_id' => $agent->id,
                        'agent_name' => $agent->name,
                        'kpi_day_latest' => $kpis->get(0),
                        'kpi_day_previous' => $kpis->get(1),
                        'latest_date' => $latestReport->report_date->toDateString(),
                        'previous_date' => $previousReport?->report_date?->toDateString(),
                    ],
                ]
            );

            $sent++;
        }

        return $sent;
    }

    private function calculateKpiValue(DailyReport $report, array $metricsConfig): float
    {
        $sum = 0.0;

        foreach ($metricsConfig as $column => $config) {
            $target = (float) ($config['target'] ?? 0);
            $weight = (float) ($config['weight'] ?? 0);
            $fact = (float) ($report->{$column} ?? 0);

            if ($target <= 0 || $weight <= 0) {
                continue;
            }

            $sum += ($fact / $target) * $weight;
        }

        return round($sum, 4);
    }

    private function notifyLeadAssigned(Lead $lead, ?User $actor = null): void
    {
        if (! $lead->responsible_agent_id) {
            return;
        }

        $lead->loadMissing('responsibleAgent.role');

        $this->notifyUsers(
            collect([$lead->responsibleAgent]),
            NotificationType::LEAD_ASSIGNED,
            'Новый лид в работе',
            sprintf('Лид #%d назначен на вас.', $lead->id),
            $lead,
            $actor,
            [
                'action_url' => '/leads/'.$lead->id,
                'action_type' => 'open_lead',
                'dedupe_key' => 'lead:assigned:'.$lead->id.':'.$lead->responsible_agent_id,
                'data' => ['lead_id' => $lead->id],
            ]
        );
    }

    private function notifyUsers(
        iterable $users,
        string $type,
        string $title,
        string $body,
        ?Model $subject = null,
        ?User $actor = null,
        array $options = []
    ): void {
        $recipients = collect($users)
            ->filter(fn ($user) => $user instanceof User && $user->status === User::STATUS_ACTIVE)
            ->unique('id')
            ->reject(fn (User $user) => $actor && (int) $user->id === (int) $actor->id)
            ->values();

        Log::info('Notification recipients resolved.', [
            'type' => $type,
            'actor_id' => $actor?->id,
            'recipient_ids' => $recipients->pluck('id')->values()->all(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'channels' => array_values($options['channels'] ?? NotificationType::defaultChannels($type)),
        ]);

        foreach ($recipients as $recipient) {
            $this->createOrAggregate($recipient, $type, $title, $body, $subject, $actor, $options);
        }
    }

    private function createOrAggregate(
        User $recipient,
        string $type,
        string $title,
        string $body,
        ?Model $subject,
        ?User $actor,
        array $options
    ): Notification {
        if (! $this->notificationsTableExists()) {
            Log::warning('Notification skipped because notifications table does not exist.', [
                'recipient_user_id' => $recipient->id,
                'type' => $type,
            ]);

            return new Notification();
        }

        $now = now();
        $dedupeKey = $options['dedupe_key'] ?? $type.':'.$recipient->id.':'.($subject?->getMorphClass() ?? 'none').':'.($subject?->getKey() ?? 'none');
        $channels = array_values($options['channels'] ?? NotificationType::defaultChannels($type));
        $quietWindowMinutes = (int) ($options['quiet_window_minutes'] ?? NotificationType::defaultQuietWindowMinutes($type));
        $windowStart = $now->copy()->subMinutes($quietWindowMinutes);

        Log::info('Preparing notification record.', [
            'recipient_user_id' => $recipient->id,
            'recipient_telegram_chat_id' => $recipient->telegram_chat_id,
            'type' => $type,
            'channels' => $channels,
            'dedupe_key' => $dedupeKey,
            'quiet_window_minutes' => $quietWindowMinutes,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ]);

        $existing = Notification::query()
            ->where('user_id', $recipient->id)
            ->where('type', $type)
            ->where('dedupe_key', $dedupeKey)
            ->whereNull('read_at')
            ->where('created_at', '>=', $windowStart)
            ->latest('id')
            ->first();

        $payload = [
            'user_id' => $recipient->id,
            'actor_id' => $actor?->id,
            'type' => $type,
            'category' => $options['category'] ?? NotificationType::category($type),
            'status' => NotificationStatus::UNREAD,
            'priority' => $options['priority'] ?? NotificationType::defaultPriority($type),
            'channels' => $channels,
            'title' => $title,
            'body' => $body,
            'action_url' => $options['action_url'] ?? null,
            'action_type' => $options['action_type'] ?? null,
            'dedupe_key' => $dedupeKey,
            'last_occurred_at' => $now,
            'delivered_at' => $options['delivered_at'] ?? $now,
            'scheduled_at' => $options['scheduled_at'] ?? null,
            'data' => $options['data'] ?? [],
        ];

        if ($subject) {
            $payload['subject_type'] = $subject->getMorphClass();
            $payload['subject_id'] = $subject->getKey();
        }

        if ($existing) {
            $existing->forceFill([
                ...$payload,
                'occurrences_count' => (int) $existing->occurrences_count + 1,
            ])->save();

            Log::info('Notification aggregated into existing record.', [
                'notification_id' => $existing->id,
                'recipient_user_id' => $recipient->id,
                'type' => $type,
                'occurrences_count' => $existing->occurrences_count,
            ]);

            return $existing;
        }

        $notification = Notification::query()->create([
            ...$payload,
            'occurrences_count' => 1,
        ]);

        Log::info('Notification record created.', [
            'notification_id' => $notification->id,
            'recipient_user_id' => $recipient->id,
            'type' => $type,
            'channels' => $channels,
            'telegram_chat_id' => $recipient->telegram_chat_id,
        ]);

        if (in_array(NotificationChannel::TELEGRAM, $channels, true)) {
            $this->deliverTelegramNotification($notification, $recipient);
        }

        return $notification;
    }

    private function deliverTelegramNotification(Notification $notification, User $recipient): void
    {
        if (! $this->telegramBot->isEnabled()) {
            return;
        }

        if (! $recipient->telegram_chat_id) {
            return;
        }

        try {
            $this->telegramBot->sendUserMessage(
                $recipient,
                $this->formatTelegramNotificationText($notification)
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to send notification to telegram.', [
                'notification_id' => $notification->id,
                'recipient_user_id' => $recipient->id,
                'type' => $notification->type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatTelegramNotificationText(Notification $notification): string
    {
        $title = trim((string) $notification->title);
        $body = trim((string) $notification->body);
        $url = trim((string) $notification->action_url);

        $parts = array_values(array_filter([$title, $body]));

        if ($url !== '') {
            $parts[] = $url;
        }

        return implode("\n", $parts);
    }

    private function absoluteFrontendUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $base = (string) config('app.frontend_url', config('app.url', ''));
        $base = rtrim($base, '/');
        $normalizedPath = '/'.ltrim($path, '/');

        if ($base === '') {
            return $normalizedPath;
        }

        return $base.$normalizedPath;
    }

    private function isWithinDailyWindow(string $hhmm, int $windowMinutes): bool
    {
        [$hours, $minutes] = array_map('intval', explode(':', $hhmm));
        $now = now();
        $from = $now->copy()->setTime($hours, $minutes, 0);
        $to = $from->copy()->addMinutes($windowMinutes);

        return $now->gte($from) && $now->lt($to);
    }

    private function dispatchLeadSlaDueSoon(): int
    {
        $from = now();
        $to = now()->copy()->addMinutes(5);

        $leads = Lead::query()
            ->whereNotIn('status', Lead::closedStatuses())
            ->whereNull('first_contacted_at')
            ->whereBetween('first_contact_due_at', [$from, $to])
            ->get();

        foreach ($leads as $lead) {
            $this->notifyUsers(
                $this->recipients->leadManagers($lead),
                NotificationType::LEAD_SLA_DUE_SOON,
                'SLA первого контакта подходит',
                sprintf('По лиду #%d скоро истечёт SLA первого контакта.', $lead->id),
                $lead,
                null,
                [
                    'action_url' => '/leads/'.$lead->id,
                    'action_type' => 'open_lead',
                    'dedupe_key' => 'lead:sla:due-soon:'.$lead->id,
                ]
            );

            $this->notifyUsers(
                $this->recipients->leadManagers($lead),
                NotificationType::MOTIVATION_MANAGER_LEAD_HOT,
                'Горячий лид ждёт контакта',
                'Свяжитесь с клиентом быстрее, пока интерес максимально высокий.',
                $lead,
                null,
                [
                    'action_url' => '/leads/'.$lead->id,
                    'action_type' => 'open_lead',
                    'dedupe_key' => 'lead:motivation:hot:'.$lead->id,
                ]
            );
        }

        return $leads->count();
    }

    private function dispatchLeadSlaOverdue(): int
    {
        $leads = Lead::query()
            ->whereNotIn('status', Lead::closedStatuses())
            ->whereNull('first_contacted_at')
            ->whereNotNull('first_contact_due_at')
            ->where('first_contact_due_at', '<', now())
            ->get();

        foreach ($leads as $lead) {
            $this->notifyUsers(
                $this->recipients->leadManagers($lead),
                NotificationType::LEAD_SLA_OVERDUE,
                'SLA по лиду просрочен',
                sprintf('Лид #%d не получил первый контакт вовремя.', $lead->id),
                $lead,
                null,
                [
                    'action_url' => '/leads/'.$lead->id,
                    'action_type' => 'open_lead',
                    'dedupe_key' => 'lead:sla:overdue:'.$lead->id,
                ]
            );
        }

        return $leads->count();
    }

    private function dispatchLeadFollowUpDue(): int
    {
        $from = now();
        $to = now()->copy()->addMinutes(5);

        $leads = Lead::query()
            ->whereNotIn('status', Lead::closedStatuses())
            ->whereBetween('next_follow_up_at', [$from, $to])
            ->get();

        foreach ($leads as $lead) {
            $this->notifyUsers(
                $this->recipients->leadManagers($lead),
                NotificationType::LEAD_FOLLOW_UP_DUE,
                'Подходит время follow-up',
                sprintf('По лиду #%d наступает время следующего контакта.', $lead->id),
                $lead,
                null,
                [
                    'action_url' => '/leads/'.$lead->id,
                    'action_type' => 'open_lead',
                    'dedupe_key' => 'lead:follow-up:due:'.$lead->id,
                ]
            );
        }

        return $leads->count();
    }

    private function dispatchLeadFollowUpOverdue(): int
    {
        $leads = Lead::query()
            ->whereNotIn('status', Lead::closedStatuses())
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<', now())
            ->get();

        foreach ($leads as $lead) {
            $this->notifyUsers(
                $this->recipients->leadManagers($lead),
                NotificationType::LEAD_FOLLOW_UP_OVERDUE,
                'Follow-up просрочен',
                sprintf('По лиду #%d просрочен следующий контакт.', $lead->id),
                $lead,
                null,
                [
                    'action_url' => '/leads/'.$lead->id,
                    'action_type' => 'open_lead',
                    'dedupe_key' => 'lead:follow-up:overdue:'.$lead->id,
                ]
            );
        }

        return $leads->count();
    }

    private function dispatchLeadInactive(): int
    {
        $threshold = now()->copy()->subHours(4);

        $leads = Lead::query()
            ->whereNotIn('status', Lead::closedStatuses())
            ->where(function ($query) use ($threshold) {
                $query->whereNull('last_activity_at')->orWhere('last_activity_at', '<', $threshold);
            })
            ->get();

        foreach ($leads as $lead) {
            $this->notifyUsers(
                $this->recipients->leadManagers($lead),
                NotificationType::LEAD_INACTIVE,
                'Лид давно без активности',
                sprintf('По лиду #%d давно не было действий.', $lead->id),
                $lead,
                null,
                [
                    'action_url' => '/leads/'.$lead->id,
                    'action_type' => 'open_lead',
                    'dedupe_key' => 'lead:inactive:'.$lead->id,
                ]
            );
        }

        return $leads->count();
    }

    private function dispatchDealDeadlineSoon(): int
    {
        $from = now();
        $to = now()->copy()->addDay();

        $deals = Deal::query()
            ->whereNull('closed_at')
            ->whereBetween('deadline_at', [$from, $to])
            ->get();

        foreach ($deals as $deal) {
            $this->notifyUsers(
                $this->recipients->dealStakeholders($deal),
                NotificationType::DEAL_DEADLINE_SOON,
                'Срок по сделке близко',
                sprintf('По сделке "%s" приближается дедлайн.', $deal->title ?: 'Без названия'),
                $deal,
                null,
                [
                    'action_url' => '/deals/'.$deal->id,
                    'action_type' => 'open_deal',
                    'dedupe_key' => 'deal:deadline:'.$deal->id,
                ]
            );

            if ($deal->responsibleAgent) {
                $this->notifyUsers(
                    collect([$deal->responsibleAgent]),
                    NotificationType::MOTIVATION_AGENT_DEAL_CLOSE_SOON,
                    'Сделка близка к закрытию',
                    'Сделка на финишной прямой. Проверьте следующий шаг и не дайте ей зависнуть.',
                    $deal,
                    null,
                    [
                        'action_url' => '/deals/'.$deal->id,
                        'action_type' => 'open_deal',
                        'dedupe_key' => 'deal:motivation:close-soon:'.$deal->id,
                    ]
                );
            }
        }

        return $deals->count();
    }

    private function dispatchDealActivityOverdue(): int
    {
        $deals = Deal::query()
            ->whereNull('closed_at')
            ->whereNotNull('next_activity_at')
            ->where('next_activity_at', '<', now())
            ->get();

        foreach ($deals as $deal) {
            $this->notifyUsers(
                $this->recipients->dealStakeholders($deal),
                NotificationType::DEAL_ACTIVITY_OVERDUE,
                'Следующий шаг по сделке просрочен',
                sprintf('По сделке "%s" просрочено следующее действие.', $deal->title ?: 'Без названия'),
                $deal,
                null,
                [
                    'action_url' => '/deals/'.$deal->id,
                    'action_type' => 'open_deal',
                    'dedupe_key' => 'deal:activity-overdue:'.$deal->id,
                ]
            );
        }

        return $deals->count();
    }

    private function dispatchBookingReminderWindow(string $type, int $offsetMinutes, int $windowMinutes): int
    {
        $from = now()->copy()->addMinutes($offsetMinutes - $windowMinutes);
        $to = now()->copy()->addMinutes($offsetMinutes + $windowMinutes);

        $bookings = Booking::query()
            ->whereBetween('start_time', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->get();

        foreach ($bookings as $booking) {
            $title = match ($type) {
                NotificationType::BOOKING_REMINDER_24H => 'Показ через 24 часа',
                NotificationType::BOOKING_REMINDER_30M => 'Показ через 30 минут',
                default => 'Показ скоро начнётся',
            };

            $body = match ($type) {
                NotificationType::BOOKING_REMINDER_24H => 'Через сутки у вас запланирован показ. Уточните подтверждение с клиентом.',
                NotificationType::BOOKING_REMINDER_30M => 'До показа осталось около 30 минут. Проверьте адрес, время и связь с клиентом.',
                default => 'Показ скоро начнётся.',
            };

            $this->notifyUsers(
                $this->recipients->bookingAgent($booking),
                $type,
                $title,
                $body,
                $booking,
                null,
                [
                    'channels' => [NotificationChannel::IN_APP, NotificationChannel::TELEGRAM],
                    'action_url' => '/bookings/'.$booking->id,
                    'action_type' => 'open_booking',
                    'dedupe_key' => 'booking:reminder:'.$type.':'.$booking->id,
                ]
            );
        }

        return $bookings->count();
    }

    private function dispatchManagerMorningDigest(): int
    {
        if ((int) now()->format('H') !== 8) {
            return 0;
        }

        $count = 0;
        $managers = $this->recipients->usersByRole(['manager']);

        foreach ($managers as $manager) {
            $leadStats = Lead::query()
                ->where('responsible_agent_id', $manager->id)
                ->whereNotIn('status', Lead::closedStatuses())
                ->selectRaw('COUNT(*) as total')
                ->selectRaw('SUM(CASE WHEN next_follow_up_at IS NOT NULL AND next_follow_up_at < ? THEN 1 ELSE 0 END) as overdue_follow_up', [now()])
                ->first();

            $this->notifyUsers(
                collect([$manager]),
                NotificationType::MOTIVATION_MANAGER_MORNING_DIGEST,
                'Утренний старт по лидам',
                sprintf(
                    'Сегодня в работе %d лидов, просроченных follow-up: %d. Начните с самых горячих.',
                    (int) ($leadStats->total ?? 0),
                    (int) ($leadStats->overdue_follow_up ?? 0),
                ),
                null,
                null,
                [
                    'action_url' => '/leads',
                    'action_type' => 'open_leads',
                    'dedupe_key' => 'digest:manager:morning:'.$manager->id.':'.now()->toDateString(),
                    'quiet_window_minutes' => 720,
                ]
            );

            $count++;
        }

        return $count;
    }

    private function dispatchAgentDayPlan(): int
    {
        if ((int) now()->format('H') !== 8) {
            return 0;
        }

        $count = 0;
        $agents = $this->recipients->usersByRole(['agent']);

        foreach ($agents as $agent) {
            $showsCount = Booking::query()
                ->where('agent_id', $agent->id)
                ->whereDate('start_time', now()->toDateString())
                ->count();

            $activeDeals = Deal::query()
                ->where('responsible_agent_id', $agent->id)
                ->whereNull('closed_at')
                ->count();

            $this->notifyUsers(
                collect([$agent]),
                NotificationType::MOTIVATION_AGENT_DAY_PLAN,
                'План на день готов',
                sprintf('Сегодня у вас %d показов и %d активных сделок. Проверьте приоритеты с утра.', $showsCount, $activeDeals),
                null,
                null,
                [
                    'action_url' => '/bookings',
                    'action_type' => 'open_bookings',
                    'dedupe_key' => 'digest:agent:morning:'.$agent->id.':'.now()->toDateString(),
                    'quiet_window_minutes' => 720,
                ]
            );

            $count++;
        }

        return $count;
    }

    private function dispatchManagerEveningDigest(): int
    {
        if ((int) now()->format('H') !== 18) {
            return 0;
        }

        $count = 0;
        $managers = $this->recipients->usersByRole(['manager']);

        foreach ($managers as $manager) {
            $processed = Lead::query()
                ->where('responsible_agent_id', $manager->id)
                ->whereDate('updated_at', now()->toDateString())
                ->count();

            $converted = Lead::query()
                ->where('responsible_agent_id', $manager->id)
                ->whereDate('converted_at', now()->toDateString())
                ->count();

            $this->notifyUsers(
                collect([$manager]),
                NotificationType::MOTIVATION_MANAGER_EVENING_DIGEST,
                'Итог дня по лидам',
                sprintf('Сегодня вы обработали %d лидов и конвертировали %d.', $processed, $converted),
                null,
                null,
                [
                    'action_url' => '/leads',
                    'action_type' => 'open_leads',
                    'dedupe_key' => 'digest:manager:evening:'.$manager->id.':'.now()->toDateString(),
                    'quiet_window_minutes' => 720,
                ]
            );

            $count++;
        }

        return $count;
    }

    private function dispatchAgentEveningDigest(): int
    {
        if ((int) now()->format('H') !== 18) {
            return 0;
        }

        $count = 0;
        $agents = $this->recipients->usersByRole(['agent']);

        foreach ($agents as $agent) {
            $shows = Booking::query()
                ->where('agent_id', $agent->id)
                ->whereDate('start_time', now()->toDateString())
                ->count();

            $wonDeals = Deal::query()
                ->where('responsible_agent_id', $agent->id)
                ->whereDate('closed_at', now()->toDateString())
                ->whereNull('lost_reason')
                ->count();

            $this->notifyUsers(
                collect([$agent]),
                NotificationType::MOTIVATION_AGENT_EVENING_DIGEST,
                'Итог дня по сделкам',
                sprintf('Сегодня у вас %d показов и %d успешно закрытых сделок.', $shows, $wonDeals),
                null,
                null,
                [
                    'action_url' => '/deals',
                    'action_type' => 'open_deals',
                    'dedupe_key' => 'digest:agent:evening:'.$agent->id.':'.now()->toDateString(),
                    'quiet_window_minutes' => 720,
                ]
            );

            $count++;
        }

        return $count;
    }

    private function notificationsTableExists(): bool
    {
        return Schema::hasTable('notifications');
    }
}
