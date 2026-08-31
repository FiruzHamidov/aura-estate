<?php

namespace App\Services;

use App\Models\Notification as AppNotification;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\WebPushConfig;

class FirebasePushService
{
    public function send(AppNotification $notification): void
    {
        $credentials = (string) config('services.firebase.credentials', '');

        if ($credentials === '' || ! is_file($credentials)) {
            Log::warning('Firebase push skipped because credentials are not configured.', [
                'notification_id' => $notification->id,
            ]);

            return;
        }

        $subscriptions = PushSubscription::query()
            ->where('user_id', $notification->user_id)
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $link = $this->absoluteFrontendUrl($notification->action_url ?: '/profile');
        $data = array_merge($this->stringifyData($notification->data ?? []), [
            'notification_id' => (string) $notification->id,
            'type' => (string) $notification->type,
            'title' => (string) $notification->title,
            'body' => (string) $notification->body,
            'action_url' => $link,
        ]);

        try {
            $messaging = (new Factory)
                ->withServiceAccount($credentials)
                ->createMessaging();

            foreach ($subscriptions->groupBy(fn (PushSubscription $subscription) => $subscription->platform ?: 'web') as $platform => $platformSubscriptions) {
                $this->sendToPlatform(
                    $messaging,
                    (string) $platform,
                    $platformSubscriptions->pluck('token')->all(),
                    $data,
                    $link,
                    (int) $notification->priority,
                    (int) $notification->id,
                );
            }
        } catch (\Throwable $exception) {
            Log::error('Firebase push delivery failed.', [
                'notification_id' => $notification->id,
                'recipient_user_id' => $notification->user_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function sendToPlatform(
        Messaging $messaging,
        string $platform,
        array $tokens,
        array $data,
        string $link,
        int $priority,
        int $notificationId,
    ): void {
        $report = $messaging->sendMulticast(
            $this->messageForPlatform($platform, $data, $link, $priority),
            $tokens,
        );
        $expiredTokens = array_values(array_unique([
            ...$report->invalidTokens(),
            ...$report->unknownTokens(),
        ]));

        if ($expiredTokens !== []) {
            PushSubscription::query()->whereIn('token', $expiredTokens)->delete();
        }

        PushSubscription::query()
            ->whereIn('token', $report->validTokens())
            ->update(['last_used_at' => now()]);

        if ($report->hasFailures()) {
            Log::warning('Some Firebase push notifications failed.', [
                'notification_id' => $notificationId,
                'platform' => $platform,
                'failures' => $report->failures()->count(),
            ]);
        }
    }

    private function messageForPlatform(string $platform, array $data, string $link, int $priority): CloudMessage
    {
        $message = CloudMessage::new()->withData($data);

        return match ($platform) {
            'android' => $message->withAndroidConfig([
                'priority' => $priority >= 3 ? 'high' : 'normal',
                'ttl' => '86400s',
                'notification' => [
                    'title' => $data['title'],
                    'body' => $data['body'],
                ],
            ]),
            'ios' => $message->withApnsConfig([
                'headers' => [
                    'apns-priority' => $priority >= 3 ? '10' : '5',
                    'apns-expiration' => (string) now()->addDay()->timestamp,
                ],
                'payload' => [
                    'aps' => [
                        'alert' => [
                            'title' => $data['title'],
                            'body' => $data['body'],
                        ],
                        'sound' => 'default',
                    ],
                ],
            ]),
            default => $message->withWebPushConfig(WebPushConfig::fromArray([
                'headers' => [
                    'Urgency' => $priority >= 3 ? 'high' : 'normal',
                    'TTL' => '86400',
                ],
                'fcm_options' => ['link' => $link],
            ])),
        };
    }

    private function absoluteFrontendUrl(string $path): string
    {
        if (str_starts_with($path, 'https://') || str_starts_with($path, 'http://')) {
            return $path;
        }

        return rtrim((string) config('app.frontend_url', config('app.url')), '/').'/'.ltrim($path, '/');
    }

    private function stringifyData(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[(string) $key] = is_scalar($value) || $value === null
                ? (string) $value
                : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $result;
    }
}
