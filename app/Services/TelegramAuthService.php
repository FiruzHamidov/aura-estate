<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use RuntimeException;

class TelegramAuthService
{
    public function authenticateWidgetUser(array $payload, string $tokenName = 'telegram-widget-token'): array
    {
        $data = $this->validateWidgetPayload($payload);

        /** @var User|null $user */
        $user = User::query()
            ->where('telegram_id', $data['id'])
            ->with('role')
            ->first();

        if (! $user) {
            throw new RuntimeException('Telegram account is not linked to any user.');
        }

        if ($user->status !== User::STATUS_ACTIVE) {
            throw new RuntimeException('Пользователь деактивирован');
        }

        $this->syncTelegramProfile($user, $data);

        $plainTextToken = $user->createToken(
            $tokenName,
            ['*'],
            now()->addHours(24)
        )->plainTextToken;

        return [
            'token' => $plainTextToken,
            'user' => $user->fresh('role'),
        ];
    }

    public function linkWidgetUser(User $user, array $payload): User
    {
        $data = $this->validateWidgetPayload($payload);

        $linkedUser = User::query()
            ->where('telegram_id', $data['id'])
            ->where('id', '!=', $user->id)
            ->first();

        if ($linkedUser) {
            throw new RuntimeException('Этот Telegram уже привязан к другому пользователю.');
        }

        if ($user->status !== User::STATUS_ACTIVE) {
            throw new RuntimeException('Пользователь деактивирован');
        }

        $this->syncTelegramProfile($user, $data);

        return $user->fresh('role');
    }

    public function attachChatFromStart(int|string $telegramUserId, ?string $telegramUsername, ?string $photoUrl, int|string $chatId): ?User
    {
        /** @var User|null $user */
        $user = User::query()->where('telegram_id', (string) $telegramUserId)->first();

        if (! $user) {
            return null;
        }

        $user->forceFill([
            'telegram_username' => $telegramUsername ?: $user->telegram_username,
            'telegram_photo_url' => $photoUrl ?: $user->telegram_photo_url,
            'telegram_chat_id' => (string) $chatId,
            'telegram_linked_at' => $user->telegram_linked_at ?? now(),
        ])->save();

        return $user->fresh();
    }

    public function validateWidgetPayload(array $payload): array
    {
        $required = ['id', 'auth_date', 'hash'];

        foreach ($required as $field) {
            if (! array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
                throw new RuntimeException(sprintf('Missing required Telegram field: %s.', $field));
            }
        }

        $botToken = config('services.telegram.bot_token');

        if (! $botToken) {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $authDate = Carbon::createFromTimestampUTC((int) $payload['auth_date']);
        $ttl = (int) config('services.telegram.auth_ttl_seconds', 300);

        if ($authDate->lt(now()->subSeconds($ttl))) {
            throw new RuntimeException('Telegram authorization payload expired.');
        }

        $receivedHash = (string) $payload['hash'];
        $checkData = $payload;
        unset($checkData['hash']);

        ksort($checkData);

        $dataCheckString = collect($checkData)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value, $key) => sprintf('%s=%s', $key, $value))
            ->implode("\n");

        $secretKey = hash('sha256', $botToken, true);
        $expectedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($expectedHash, $receivedHash)) {
            throw new RuntimeException('Invalid Telegram authorization hash.');
        }

        return [
            'id' => (string) $payload['id'],
            'first_name' => (string) ($payload['first_name'] ?? ''),
            'last_name' => (string) ($payload['last_name'] ?? ''),
            'username' => isset($payload['username']) ? (string) $payload['username'] : null,
            'photo_url' => isset($payload['photo_url']) ? (string) $payload['photo_url'] : null,
            'auth_date' => (int) $payload['auth_date'],
        ];
    }

    private function syncTelegramProfile(User $user, array $data): void
    {
        $user->forceFill([
            'telegram_id' => $data['id'],
            'telegram_username' => $data['username'],
            'telegram_photo_url' => $data['photo_url'],
            'telegram_linked_at' => $user->telegram_linked_at ?? now(),
        ]);

        if (blank($user->photo) && filled($data['photo_url'])) {
            $photoPath = $this->storeTelegramPhoto((string) $data['photo_url']);

            if ($photoPath) {
                $user->photo = $photoPath;
            }
        }

        $user->save();
    }

    private function storeTelegramPhoto(string $photoUrl): ?string
    {
        try {
            $response = Http::timeout(10)->get($photoUrl);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful() || $response->body() === '') {
            return null;
        }

        $extension = $this->detectPhotoExtension($photoUrl, (string) $response->header('Content-Type', ''));
        $filename = 'users/'.uniqid('telegram_', true).'.'.$extension;

        Storage::disk('public')->put($filename, $response->body());

        return $filename;
    }

    private function detectPhotoExtension(string $photoUrl, string $contentType): string
    {
        $path = parse_url($photoUrl, PHP_URL_PATH);
        $extension = $path ? pathinfo($path, PATHINFO_EXTENSION) : '';
        $extension = strtolower((string) $extension);

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return $extension;
        }

        return match (strtolower(trim(strtok($contentType, ';')))) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
