<?php

namespace App\Services;

use App\Models\SmsVerificationCode;
use App\Support\InternationalPhone;
use App\Support\UserPhoneIdentity;
use Illuminate\Support\Facades\DB;
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

        try {
            $this->osonSmsService->sendSMS(
                senderName: config('osonsmsservice.sender_name'),
                phonenumber: ltrim(InternationalPhone::e164($phone), '+'),
                message: $message,
                txnId: $txnId
            );
        } catch (\Exception) {
            throw new RuntimeException('Не удалось доставить SMS.');
        }

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
        string $purpose = SmsVerificationCode::PURPOSE_LOGIN
    ): bool {
        if (!preg_match('/^[0-9]{6}$/D', $code)) {
            return false;
        }

        // One conditional write also prevents concurrent redemption.
        return SmsVerificationCode::where('phone', InternationalPhone::e164($phone))
            ->where('purpose', $purpose)
            ->where('expires_at', '>', now())
            ->where('code', $this->digest($phone, $purpose, $code))
            ->delete() > 0;
    }

    private function digest(string $phone, string $purpose, string $code): string
    {
        $key = config('app.key');
        if (!is_string($key) || $key === '') {
            throw new RuntimeException('Application key is missing.');
        }
        return hash_hmac('sha256', $purpose.'|'.InternationalPhone::e164($phone).'|'.$code, $key);
    }

    private function storeCode(string $phone, string $purpose, string $code): string
    {
        $canonical = InternationalPhone::e164($phone);
        DB::transaction(function () use ($phone, $canonical, $purpose, $code) {
            SmsVerificationCode::whereIn('phone', UserPhoneIdentity::variants($phone))
                ->where('phone', '!=', $canonical)->where('purpose', $purpose)->delete();
            SmsVerificationCode::upsert([
                'phone' => $canonical,
                'purpose' => $purpose,
                'code' => $this->digest($phone, $purpose, $code),
                'expires_at' => now()->addMinutes(5),
            ], ['phone', 'purpose'], ['code', 'expires_at', 'updated_at']);
        }, 3);

        return $code;
    }
}
