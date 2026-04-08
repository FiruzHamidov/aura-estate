<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelegramBotService
{
    public function isEnabled(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    public function username(): ?string
    {
        return config('services.telegram.bot_username');
    }

    public function deepLink(string $token): ?string
    {
        $username = $this->username();

        if (! $username) {
            return null;
        }

        return sprintf('https://t.me/%s?start=%s', ltrim($username, '@'), $token);
    }

    public function sendMessage(string|int $chatId, string $text): array
    {
        $botToken = config('services.telegram.bot_token');

        Log::info('Telegram sendMessage called.', [
            'chat_id' => (string) $chatId,
            'bot_enabled' => $this->isEnabled(),
            'has_bot_token' => filled($botToken),
            'text_preview' => mb_strimwidth($text, 0, 120, '...'),
        ]);

        if (! $botToken) {
            Log::error('Telegram bot token is not configured.');
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $response = Http::timeout(15)
            ->baseUrl(sprintf('https://api.telegram.org/bot%s', $botToken))
            ->post('/sendMessage', [
                'chat_id' => (string) $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);

        Log::info('Telegram API response received.', [
            'chat_id' => (string) $chatId,
            'status' => $response->status(),
            'ok' => $response->ok(),
            'body' => $response->json(),
        ]);

        if ($response->failed()) {
            Log::error('Telegram API request failed.', [
                'chat_id' => (string) $chatId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new RuntimeException('Telegram API request failed.');
        }

        return $response->json();
    }

    public function sendUserMessage(User $user, string $text): array
    {
        Log::info('Telegram sendUserMessage called.', [
            'user_id' => $user->id,
            'telegram_chat_id' => $user->telegram_chat_id,
            'telegram_id' => $user->telegram_id,
        ]);

        if (! $user->telegram_chat_id) {
            Log::warning('Telegram sendUserMessage skipped: user does not have linked telegram_chat_id.', [
                'user_id' => $user->id,
                'telegram_id' => $user->telegram_id,
            ]);
            throw new RuntimeException('User does not have a linked Telegram chat.');
        }

        return $this->sendMessage($user->telegram_chat_id, $text);
    }
}
