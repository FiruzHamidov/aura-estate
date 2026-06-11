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
        if ($this->isAppReviewLoginOtp($phone, $purpose)) {
            return $this->storeCode($phone, $purpose, $this->appReviewOtp());
        }

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

        $this->storeCode($phone, $purpose, (string) $code);

        return (string) $code;
    }

    public function storeVerificationCode(string $phone, string $purpose): string
    {
        $code = (string) random_int(100000, 999999);

        $this->storeCode($phone, $purpose, $code);

        return $code;
    }

    public function verifyCode(
        string $phone,
        string $code,
        string $purpose = SmsVerificationCode::PURPOSE_LOGIN,
        bool $consume = false
    ): bool
    {
        if (
            $this->isAppReviewLoginOtp($phone, $purpose)
            && hash_equals($this->appReviewOtp(), $code)
        ) {
            return true;
        }

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

    private function storeCode(string $phone, string $purpose, string $code): string
    {
        SmsVerificationCode::updateOrCreate(
            ['phone' => $phone, 'purpose' => $purpose],
            [
                'code' => $code,
                'expires_at' => now()->addMinutes(5),
            ]
        );

        return $code;
    }

    private function isAppReviewLoginOtp(string $phone, string $purpose): bool
    {
        return $purpose === SmsVerificationCode::PURPOSE_LOGIN
            && $this->normalizePhone($phone) === $this->normalizePhone((string) config('auth.app_review.phone'));
    }

    private function appReviewOtp(): string
    {
        return (string) config('auth.app_review.otp', '000000');
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '992')) {
            return substr($digits, 3);
        }

        return $digits;
    }
}
