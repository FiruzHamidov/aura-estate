# Messaging realtime contract

Messaging remains REST-first. Existing conversation, history, send, create and read-state endpoints are the source of truth and polling fallback. Reverb only delivers invalidation/message updates after the database transaction commits.

## Client contract

Laravel Echo subscribes without the `private-` prefix:

- `messaging.user.{userId}` — authenticated user's conversation-list and unread updates.
- `messaging.conversation.{conversationId}` — message updates for an authenticated participant. Authorization reuses `MessageAccessService::canAccessConversation`.
- `guest-support.conversation.{supportThreadPublicId}` — guest support only. `supportThreadPublicId` is the `public_id` UUID returned by guest-support REST responses; database IDs and conversation IDs must never be used in this channel name.

Authenticated private-channel authorization is `POST /api/broadcasting/auth` with the existing Sanctum bearer token. Guest authorization is `POST /api/guest-support/broadcasting/auth` with `withCredentials: true`; it accepts only a valid, unexpired HttpOnly `aura_guest_support` cookie and a UUID-shaped guest channel belonging to that session. Both HTTP auth routes and the Reverb WebSocket handshake retain the existing origin allowlists.

Echo event names are `.conversation.message.created` and `.conversation.updated`. Event IDs and database message IDs are stable dedupe keys. System/lifecycle messages do not produce `conversation.message.created`.

`conversation.message.created` payload:

```json
{
  "version": 1,
  "event_id": "message.created:456",
  "conversation_id": 123,
  "occurred_at": "2026-08-30T12:00:00+05:00",
  "conversation": {
    "id": 123,
    "type": "direct|group|support",
    "kind": "personal|group|support",
    "updated_at": "2026-08-30T12:00:00+05:00"
  },
  "message": {
    "id": 456,
    "conversation_id": 123,
    "author_id": 42,
    "client_message_id": "optional-client-uuid",
    "type": "text",
    "body": "Message body",
    "created_at": "2026-08-30T12:00:00+05:00"
  }
}
```

`conversation.updated` has the same envelope and compact `conversation`. It adds `reason` (`conversation_created`, `message_created`, or `conversation_read`), may include the same compact `message`, and includes a viewer-specific integer `unread_count` on authenticated user channels. Guest events omit `unread_count`. The optional `client_message_id` supports optimistic-send reconciliation; the database `message.id` remains the canonical dedupe key. Payloads deliberately omit names, phones, email, photos, roles, cookies, tokens, hashes, auth data and arbitrary model metadata.

## Production runtime requirements

Realtime must remain disabled until every requirement below is present and checked:

- `BROADCAST_CONNECTION=reverb`
- `MESSAGING_REALTIME_BROADCAST_ENABLED=true`
- non-synchronous durable `QUEUE_CONNECTION` (the existing production database queue is supported) and a healthy queue worker
- matching `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` for the Laravel broadcaster and Reverb server
- public `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME=https`; internal `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT`, and optional `REVERB_SERVER_PATH`
- `REVERB_ALLOWED_ORIGINS` containing only the existing approved frontend origins; do not use `*`
- `REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM=none`
- TLS/WebSocket reverse proxy forwarding Upgrade/Connection headers to the Reverb listener
- a supervised long-running `php artisan reverb:start --host=0.0.0.0 --port=8080` process, separate from the existing queue worker

Recommended Supervisor program name for the deployment contract is `aura-estate-reverb`, running as `www-data` from `/var/www/aura-estate`, with `autostart=true`, `autorestart=true`, `stopasgroup=true`, and application log rotation. Deploys that change cached config must run `php artisan config:cache`, `php artisan route:cache`, `php artisan queue:restart`, and `php artisan reverb:restart`, then verify both Supervisor programs are RUNNING.

`GET /up` proves Laravel/PHP availability but does not prove Reverb health. Release readiness also requires a WebSocket connection smoke test through the public proxy, successful/denied auth checks for the private channel classes, and observation that a committed test message is received once while a rolled-back write is never received. The versioned production deploy script now checks Supervisor, TLS proxy, approved/foreign Origin handshakes and unauthenticated HTTP auth boundaries before retaining the feature flag. The installed `/usr/local/lib/aura-deploy/backend.sh` must match that version; an older installed entry point is not sufficient evidence of realtime readiness.
