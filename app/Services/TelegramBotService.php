<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
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

        if (! $botToken) {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $response = Http::timeout(15)
            ->baseUrl(sprintf('https://api.telegram.org/bot%s', $botToken))
            ->post('/sendMessage', [
                'chat_id' => (string) $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Telegram API request failed.');
        }

        return $response->json();
    }

    public function sendUserMessage(User $user, string $text): array
    {
        if (! $user->telegram_chat_id) {
            throw new RuntimeException('User does not have a linked Telegram chat.');
        }

        return $this->sendMessage($user->telegram_chat_id, $text);
    }
}
