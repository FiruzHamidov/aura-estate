<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SmsAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // Логин по паролю
    public function login(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'password' => 'nullable|string',
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        if ($user->auth_method === 'password') {
            if (!$request->password || !Hash::check($request->password, $user->password)) {
                return response()->json(['message' => 'Неверный пароль'], 401);
            }

            // создаем токен Sanctum
            $token = $user->createToken('api-token')->plainTextToken;
            return response()->json(['token' => $token]);
        }

        return response()->json(['message' => 'У пользователя включена авторизация через SMS'], 403);
    }

    // Отправка кода на SMS
    public function requestSmsCode(Request $request, SmsAuthService $smsAuthService)
    {
        $request->validate([
            'phone' => 'required|string'
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (!$user || $user->auth_method !== 'sms') {
            return response()->json(['message' => 'Пользователь не найден или не настроен для SMS входа'], 404);
        }

        try {
            $smsAuthService->sendVerificationCode($request->phone);
            return response()->json(['message' => 'Код отправлен']);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 401);
        }
    }

    // Проверка кода
    public function verifySmsCode(Request $request, SmsAuthService $smsAuthService)
    {
        $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string'
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        if ($smsAuthService->verifyCode($request->phone, $request->code)) {
            $token = $user->createToken('api-token')->plainTextToken;
            return response()->json(['token' => $token]);
        }

        return response()->json(['message' => 'Неверный код'], 401);
    }
}
