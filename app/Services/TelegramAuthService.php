<?php

namespace App\Services;

use App\Models\TelegramLoginToken;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class TelegramAuthService
{
    public function issueForUser(User $user): TelegramLoginToken
    {
        TelegramLoginToken::query()
            ->where('user_id', $user->id)
            ->whereNull('confirmed_at')
            ->whereNull('used_at')
            ->delete();

        return TelegramLoginToken::create([
            'user_id' => $user->id,
            'phone' => $user->phone,
            'token' => 'auth_'.Str::random(40),
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    public function confirmByTelegram(string $tokenValue, int|string $telegramUserId, ?string $telegramUsername, int|string $telegramChatId): TelegramLoginToken
    {
        /** @var TelegramLoginToken|null $token */
        $token = TelegramLoginToken::query()
            ->where('token', $tokenValue)
            ->with('user.role')
            ->first();

        if (! $token) {
            throw new RuntimeException('Ссылка авторизации не найдена.');
        }

        if ($token->used_at) {
            throw new RuntimeException('Ссылка авторизации уже использована.');
        }

        if ($token->expires_at === null || $token->expires_at->isPast()) {
            throw new RuntimeException('Ссылка авторизации истекла.');
        }

        $user = $token->user;

        if (! $user) {
            throw new RuntimeException('Пользователь для авторизации не найден.');
        }

        $linkedUser = User::query()
            ->where('telegram_id', (string) $telegramUserId)
            ->where('id', '!=', $user->id)
            ->first();

        if ($linkedUser) {
            throw new RuntimeException('Этот Telegram уже привязан к другому пользователю.');
        }

        $user->forceFill([
            'telegram_id' => (string) $telegramUserId,
            'telegram_username' => $telegramUsername,
            'telegram_chat_id' => (string) $telegramChatId,
            'telegram_linked_at' => now(),
        ])->save();

        $token->forceFill([
            'telegram_user_id' => (string) $telegramUserId,
            'telegram_username' => $telegramUsername,
            'telegram_chat_id' => (string) $telegramChatId,
            'confirmed_at' => now(),
        ])->save();

        return $token->fresh(['user.role']);
    }

    public function complete(string $tokenValue): array
    {
        /** @var TelegramLoginToken|null $token */
        $token = TelegramLoginToken::query()
            ->where('token', $tokenValue)
            ->with('user.role')
            ->first();

        if (! $token) {
            throw new RuntimeException('Ссылка авторизации не найдена.');
        }

        if ($token->expires_at === null || $token->expires_at->isPast()) {
            throw new RuntimeException('Ссылка авторизации истекла.');
        }

        if (! $token->confirmed_at) {
            return ['status' => 'pending'];
        }

        if ($token->used_at) {
            throw new RuntimeException('Ссылка авторизации уже использована.');
        }

        $user = $token->user;

        if (! $user) {
            throw new RuntimeException('Пользователь для авторизации не найден.');
        }

        $token->forceFill([
            'used_at' => now(),
        ])->save();

        $plainTextToken = $user->createToken(
            'telegram-api-token',
            ['*'],
            now()->addHours(24)
        )->plainTextToken;

        return [
            'status' => 'authorized',
            'token' => $plainTextToken,
            'user' => $user->fresh('role'),
        ];
    }
}
