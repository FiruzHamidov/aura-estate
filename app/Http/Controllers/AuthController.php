<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SmsAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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

    // Проверка метода авторизации
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

        return response()->json(['method' => $user->auth_method]);
    }

    // Отправка кода на SMS
    public function requestSmsCode(Request $request, SmsAuthService $smsAuthService)
    {
        $request->validate([
            'phone' => 'required|string'
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден или не настроен для SMS входа'], 404);
        }

        if ($inactiveResponse = $this->ensureUserIsActive($user)) {
            return $inactiveResponse;
        }

        if ($user->auth_method !== 'sms') {
            return response()->json(['message' => 'Метод авторизации — не SMS'], 403);
        }

        try {
            $smsAuthService->sendVerificationCode($request->phone);
            return response()->json(['message' => 'Код отправлен']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
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

        if ($user->auth_method !== 'sms') {
            return response()->json(['message' => 'Метод авторизации — не SMS'], 403);
        }

        if ($smsAuthService->verifyCode($request->phone, $request->code)) {

            $token = $user->createToken(
                'api-token',
                ['*'],
                now()->addHours(24)
            )->plainTextToken;

            return response()->json([
                'token' => $token,
                'user' => $user
            ]);
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

        if ($user->auth_method !== 'password') {
            return response()->json(['message' => 'Метод авторизации — не пароль'], 403);
        }

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Неверный пароль'], 401);
        }

        $token = $user->createToken(
            'api-token',
            ['*'],
            now()->addHours(24)
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user
        ]);
    }
}
