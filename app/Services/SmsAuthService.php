<?php

namespace App\Services;

use App\Models\SmsVerificationCode;
use OsonSMS\OsonSMSService\OsonSmsService;
use RuntimeException;

class SmsAuthService
{
    public function __construct(private readonly OsonSmsService $osonSmsService)
    {}

    public function sendVerificationCode(
        string $phone,
        string $purpose = SmsVerificationCode::PURPOSE_LOGIN,
        ?string $message = null
    ): string
    {
        $code = random_int(100000, 999999);
        $txnId = uniqid($purpose.'_', true);
        $message ??= $purpose === SmsVerificationCode::PURPOSE_PASSWORD_RESET
            ? "Ваш код для сброса пароля: $code"
            : "Ваш код авторизации: $code";

        $this->osonSmsService->sendSMS(
            senderName: config('osonsmsservice.sender_name'),
            phonenumber: $phone,
            message: $message,
            txnId: $txnId
        );

        SmsVerificationCode::updateOrCreate(
            ['phone' => $phone, 'purpose' => $purpose],
            [
                'code' => $code,
                'expires_at' => now()->addMinutes(5),
            ]
        );

        return $code;
    }

    public function storeVerificationCode(string $phone, string $purpose): string
    {
        $code = (string) random_int(100000, 999999);

        SmsVerificationCode::updateOrCreate(
            ['phone' => $phone, 'purpose' => $purpose],
            [
                'code' => $code,
                'expires_at' => now()->addMinutes(5),
            ]
        );

        return $code;
    }

    public function verifyCode(
        string $phone,
        string $code,
        string $purpose = SmsVerificationCode::PURPOSE_LOGIN,
        bool $consume = false
    ): bool
    {
        $record = SmsVerificationCode::where('phone', $phone)
            ->where('purpose', $purpose)
            ->where('expires_at', '>', now())
            ->first();

        if (! $record || $record->code !== $code) {
            return false;
        }

        if ($consume) {
            $record->delete();
        }

        return true;
    }

    public function clearCode(string $phone, string $purpose): void
    {
        SmsVerificationCode::where('phone', $phone)
            ->where('purpose', $purpose)
            ->delete();
    }
}
