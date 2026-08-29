<?php

namespace App\Services;

use App\Models\Notification as AppNotification;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
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

        $message = CloudMessage::new()
            ->withData($data)
            ->withWebPushConfig(WebPushConfig::fromArray([
                'headers' => [
                    'Urgency' => ((int) $notification->priority >= 3) ? 'high' : 'normal',
                    'TTL' => '86400',
                ],
                'fcm_options' => ['link' => $link],
            ]));

        try {
            $messaging = (new Factory)
                ->withServiceAccount($credentials)
                ->createMessaging();
            $tokens = $subscriptions->pluck('token')->all();
            $report = $messaging->sendMulticast($message, $tokens);
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
                    'notification_id' => $notification->id,
                    'failures' => $report->failures()->count(),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::error('Firebase push delivery failed.', [
                'notification_id' => $notification->id,
                'recipient_user_id' => $notification->user_id,
                'error' => $exception->getMessage(),
            ]);
        }
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
