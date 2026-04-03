<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TelegramAuthService;
use App\Services\TelegramBotService;
use Illuminate\Http\Request;
use RuntimeException;

class TelegramAuthController extends Controller
{
    private function ensureWebhookAuthorized(Request $request): void
    {
        $secret = config('services.telegram.webhook_secret');

        if (! $secret) {
            return;
        }

        abort_unless(
            hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')),
            403,
            'Forbidden'
        );
    }

    public function requestLogin(Request $request, TelegramAuthService $telegramAuthService, TelegramBotService $telegramBotService)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        abort_unless($telegramBotService->isEnabled(), 503, 'Telegram login is not configured.');

        $user = User::query()->where('phone', $request->string('phone'))->with('role')->first();

        if (! $user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        if ($user->status !== User::STATUS_ACTIVE) {
            return response()->json(['message' => 'Пользователь деактивирован'], 403);
        }

        $loginToken = $telegramAuthService->issueForUser($user);

        return response()->json([
            'status' => 'pending',
            'token' => $loginToken->token,
            'expires_at' => $loginToken->expires_at,
            'bot_username' => $telegramBotService->username(),
            'telegram_link' => $telegramBotService->deepLink($loginToken->token),
        ]);
    }

    public function confirmLogin(Request $request, TelegramAuthService $telegramAuthService)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        try {
            $result = $telegramAuthService->complete($request->string('token')->toString());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (($result['status'] ?? null) === 'pending') {
            return response()->json(['status' => 'pending']);
        }

        return response()->json($result);
    }

    public function webhook(Request $request, TelegramAuthService $telegramAuthService, TelegramBotService $telegramBotService)
    {
        $this->ensureWebhookAuthorized($request);

        $message = $request->input('message', []);
        $text = trim((string) data_get($message, 'text', ''));
        $chatId = data_get($message, 'chat.id');
        $telegramUserId = data_get($message, 'from.id');
        $telegramUsername = data_get($message, 'from.username');

        if (! $chatId || ! $telegramUserId || $text === '') {
            return response()->json(['ok' => true]);
        }

        if (! str_starts_with($text, '/start')) {
            return response()->json(['ok' => true]);
        }

        $parts = preg_split('/\s+/', $text);
        $payload = $parts[1] ?? null;

        if (! $payload || ! str_starts_with($payload, 'auth_')) {
            $telegramBotService->sendMessage($chatId, 'Откройте ссылку авторизации из приложения, чтобы привязать Telegram.');

            return response()->json(['ok' => true]);
        }

        try {
            $telegramAuthService->confirmByTelegram($payload, $telegramUserId, $telegramUsername, $chatId);

            $telegramBotService->sendMessage(
                $chatId,
                'Telegram успешно привязан. Вернитесь в приложение и завершите вход.'
            );
        } catch (RuntimeException $e) {
            $telegramBotService->sendMessage($chatId, $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }
}
