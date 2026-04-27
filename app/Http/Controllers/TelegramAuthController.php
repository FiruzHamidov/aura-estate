<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DailyReportService;
use App\Services\TelegramAuthService;
use App\Services\TelegramBotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    public function login(Request $request, TelegramAuthService $telegramAuthService)
    {
        $payload = $request->validate([
            'id' => 'required',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'username' => 'nullable|string',
            'photo_url' => 'nullable|string',
            'auth_date' => 'required',
            'hash' => 'required|string',
        ]);

        try {
            $result = $telegramAuthService->authenticateWidgetUser($payload);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Telegram account is not linked to any user.' ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $status);
        }

        $dailyReportStatus = app(DailyReportService::class)->statusForUser($result['user']);

        return response()->json(array_merge([
            'status' => 'authorized',
            'token' => $result['token'],
            'user' => $result['user'],
            'daily_report_status' => $dailyReportStatus,
        ], $dailyReportStatus));
    }

    public function link(Request $request, TelegramAuthService $telegramAuthService)
    {
        $payload = $request->validate([
            'id' => 'required',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'username' => 'nullable|string',
            'photo_url' => 'nullable|string',
            'auth_date' => 'required',
            'hash' => 'required|string',
        ]);

        /** @var User|null $user */
        $user = Auth::user();

        abort_unless($user, 401, 'Unauthenticated.');

        try {
            $linkedUser = $telegramAuthService->linkWidgetUser($user, $payload);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Telegram account linked successfully.',
            'user' => $linkedUser,
        ]);
    }

    public function webhook(Request $request, TelegramAuthService $telegramAuthService, TelegramBotService $telegramBotService)
    {
        $this->ensureWebhookAuthorized($request);

        $message = $request->input('message', []);
        $text = trim((string) data_get($message, 'text', ''));
        $chatId = data_get($message, 'chat.id');
        $telegramUserId = data_get($message, 'from.id');
        $telegramUsername = data_get($message, 'from.username');
        $photoUrl = null;

        if (! $chatId || ! $telegramUserId || $text === '') {
            return response()->json(['ok' => true]);
        }

        if (! str_starts_with($text, '/start')) {
            return response()->json(['ok' => true]);
        }

        $user = $telegramAuthService->attachChatFromStart($telegramUserId, $telegramUsername, $photoUrl, $chatId);

        if (! $user) {
            $telegramBotService->sendMessage(
                $chatId,
                'Ваш Telegram пока не привязан к аккаунту на сайте. Сначала войдите через Telegram Login Widget в личном кабинете.'
            );

            return response()->json(['ok' => true]);
        }

        $telegramBotService->sendMessage(
            $chatId,
            'Уведомления подключены. Теперь бот может присылать вам сообщения по событиям из системы.'
        );

        return response()->json(['ok' => true]);
    }
}
