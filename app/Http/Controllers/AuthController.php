<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SmsAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
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

        return response()->json(['method' => $user->auth_method]);
    }

    // Стандартный логин по паролю
    public function login(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (!$user || $user->auth_method !== 'password') {
            return response()->json(['message' => 'Метод авторизации — не пароль'], 403);
        }

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Неверный пароль'], 401);
        }

        $token = $user->createToken('api-token')->plainTextToken;
        return response()->json(['token' => $token]);
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
