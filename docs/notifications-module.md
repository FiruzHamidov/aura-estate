## Notifications Module

### Assumptions

- In the current schema, `leads.responsible_agent_id` is used as the responsible lead owner even for managers.
- Managers own lead workflow.
- Agents own deal execution, showings, bookings, client engagement, and property follow-up.

### MVP scope

- In-app notification center with unread counters and read state.
- Anti-spam aggregation by `dedupe_key` plus quiet window.
- Workflow notifications for:
  - lead creation
  - lead assignment
  - lead status changes
  - lead conversion
  - deal creation
  - deal assignment
  - deal stage changes
  - booking creation and update
  - new chat message
  - selection events
  - favorites
- Scheduled reminders for:
  - lead SLA soon
  - lead SLA overdue
  - lead follow-up due
  - lead follow-up overdue
  - lead inactivity
  - deal deadline soon
  - overdue next activity on deal
  - booking reminder 24h
  - booking reminder 1h
  - morning/evening motivational digests

### Core components

- `App\Models\Notification`
- `App\Services\NotificationService`
- `App\Services\NotificationRecipientResolver`
- `App\Http\Controllers\NotificationController`
- `App\Console\Commands\DispatchNotificationRemindersCommand`
- `App\Support\Notifications\*`

### API

- `GET /api/notifications`
- `GET /api/notifications/unread-count`
- `PATCH /api/notifications/{notification}/read`
- `PATCH /api/notifications/read-all`

### Delivery channels

- The canonical record is always stored as `in_app`.
- Extra channels are stored declaratively in `channels`.
- Push, Telegram, SMS, and email delivery can be added later through queued workers that consume unread notifications by channel.

### Anti-spam

- Self-notifications are skipped.
- Repeated events within a quiet window aggregate into one row.
- Aggregation increments `occurrences_count` and updates `last_occurred_at`.
- Chat notifications aggregate per conversation.

### Future extensions

- User-level notification preferences and per-channel opt-outs.
- Push delivery workers.
- Telegram delivery for urgent workflows.
- Email digest rendering.
- Property moderation and duplicate-detection notifications.
- More granular client-intent scoring from views, favorites, and selections.
