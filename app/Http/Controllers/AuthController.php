<?php

namespace App\Http\Controllers;

use App\Models\SmsVerificationCode;
use App\Models\User;
use App\Services\DailyReportService;
use App\Services\SmsAuthService;
use App\Services\TelegramBotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AuthController extends Controller
{
    private function ensureUserIsActive(User $user)
    {
        if ($user->status === 'active') {
            return null;
        }

        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        return response()->json(['message' => 'Пользователь деактивирован'], 403);
    }

    private function issueApiToken(User $user, string $tokenName = 'api-token'): string
    {
        return $user->createToken(
            $tokenName,
            ['*'],
            now()->addHours(24)
        )->plainTextToken;
    }

    private function authPayload(User $user, string $token): array
    {
        $dailyReportStatus = app(DailyReportService::class)->statusForUser($user);

        return array_merge([
            'token' => $token,
            'user' => $user,
            'daily_report_status' => $dailyReportStatus,
        ], $dailyReportStatus);
    }

    // Проверка доступных способов входа без привязки к auth_method пользователя
    public function checkMethod(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        if ($inactiveResponse = $this->ensureUserIsActive($user)) {
            return $inactiveResponse;
        }

        return response()->json([
            'method' => null,
            'available_methods' => ['password', 'sms'],
        ]);
    }

    // Отправка кода на SMS
    public function requestSmsCode(Request $request, SmsAuthService $smsAuthService)
    {
        $request->validate([
            'phone' => 'required|string'
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        if ($inactiveResponse = $this->ensureUserIsActive($user)) {
            return $inactiveResponse;
        }

        try {
            $smsAuthService->sendVerificationCode($request->phone);
            return response()->json(['message' => 'Код отправлен']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function requestPasswordResetCode(
        Request $request,
        SmsAuthService $smsAuthService,
        TelegramBotService $telegramBotService
    ) {
        $validated = $request->validate([
            'phone' => 'required|string',
            'channel' => 'required|string|in:sms,telegram',
        ]);

        $user = User::where('phone', $validated['phone'])->first();

        if (! $user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        if ($inactiveResponse = $this->ensureUserIsActive($user)) {
            return $inactiveResponse;
        }

        try {
            if ($validated['channel'] === 'telegram') {
                if (! $user->telegram_id || ! $user->telegram_chat_id) {
                    return response()->json([
                        'message' => 'Для этого пользователя не подключён Telegram для получения кода.',
                    ], 422);
                }

                $code = $smsAuthService->storeVerificationCode(
                    $validated['phone'],
                    SmsVerificationCode::PURPOSE_PASSWORD_RESET
                );

                $telegramBotService->sendUserMessage(
                    $user,
                    "Код для сброса пароля: {$code}\nКод действует 5 минут."
                );

                return response()->json(['message' => 'Код для сброса пароля отправлен в Telegram']);
            }

            $smsAuthService->sendVerificationCode(
                $validated['phone'],
                SmsVerificationCode::PURPOSE_PASSWORD_RESET
            );

            return response()->json(['message' => 'Код для сброса пароля отправлен по SMS']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function resetPassword(Request $request, SmsAuthService $smsAuthService)
    {
        $validated = $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        $user = User::where('phone', $validated['phone'])->first();

        if (! $user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        if ($inactiveResponse = $this->ensureUserIsActive($user)) {
            return $inactiveResponse;
        }

        if (! $smsAuthService->verifyCode(
            $validated['phone'],
            $validated['code'],
            SmsVerificationCode::PURPOSE_PASSWORD_RESET,
            true
        )) {
            return response()->json(['message' => 'Неверный код'], 401);
        }

        $user->password = Hash::make($validated['new_password']);
        $user->remember_token = null;
        $user->save();

        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        return response()->json(['message' => 'Пароль успешно сброшен']);
    }

    // Проверка кода из SMS
    public function verifySmsCode(Request $request, SmsAuthService $smsAuthService)
    {
        $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string'
        ]);

        $user = User::where('phone', $request->phone)->with('role')->first();

        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        if ($inactiveResponse = $this->ensureUserIsActive($user)) {
            return $inactiveResponse;
        }

        if ($smsAuthService->verifyCode($request->phone, $request->code)) {
            $token = $this->issueApiToken($user);

            return response()->json($this->authPayload($user, $token));
        }

        return response()->json(['message' => 'Неверный код'], 401);
    }

    // Стандартный логин по паролю
    public function login(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('phone', $request->phone)->with('role')->first();

        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        if ($inactiveResponse = $this->ensureUserIsActive($user)) {
            return $inactiveResponse;
        }

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Неверный пароль'], 401);
        }

        $token = $this->issueApiToken($user);

        return response()->json($this->authPayload($user, $token));
    }

    public function logout(Request $request)
    {
        $user = Auth::user();

        if (! $user || ! method_exists($user, 'currentAccessToken')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = $user->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return response()->json(['message' => 'Вы успешно вышли из системы']);
    }
}
