<?php

namespace App\Services;

use App\Models\SmsVerificationCode;
use OsonSMS\OsonSMSService\OsonSmsService;
use RuntimeException;

class SmsAuthService
{
    public function __construct(private readonly OsonSmsService $osonSmsService)
    {}

    public function sendVerificationCode(string $phone): string
    {
        $code = random_int(100000, 999999);
        $txnId = uniqid('login_', true);

        $this->osonSmsService->sendSMS(
            senderName: config('osonsmsservice.sender_name'),
            phonenumber: $phone,
            message: "Ваш код авторизации: $code",
            txnId: $txnId
        );

        SmsVerificationCode::updateOrCreate(
            ['phone' => $phone],
            [
                'code' => $code,
                'expires_at' => now()->addMinutes(5),
            ]
        );

        return $code;
    }

    public function verifyCode(string $phone, string $code): bool
    {
        $record = SmsVerificationCode::where('phone', $phone)
            ->where('expires_at', '>', now())
            ->first();

        return $record && $record->code === $code;
    }
}
