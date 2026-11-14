<?php

namespace App\Services\Marketing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class OsonCampaignGateway
{
    public static function bulkConfigured(): bool
    {
        return filled(config('osonsmsservice.bulk_token'));
    }

    /** Legacy accounts use a signature. Token accounts use the documented bearer credentials. */
    public function submitIndividual(string $phone, string $message, string $transaction): array
    {
        $login = config('osonsmsservice.login');
        $sender = config('osonsmsservice.sender_name');
        $params = ['from' => $sender, 'phone_number' => $phone, 'msg' => $message, 'txn_id' => $transaction, 'login' => $login];
        if (blank(config('osonsmsservice.api_token'))) {
            $params['str_hash'] = $this->signature([$transaction, $login, $sender, $phone]);
        }
        $response = $this->individualRequest()->get('https://api.osonsms.com/sendsms_v1.php', $params);

        if ($response->successful() && is_numeric($response->json('msg_id')) && (int) $response->json('msg_id') > 0) {
            return ['status' => 'accepted', 'provider_id' => (int) $response->json('msg_id')];
        }

        return $this->failure($response->status());
    }

    public function receipt(int $providerId, string $sendTransaction): string
    {
        $login = config('osonsmsservice.login');
        $params = ['login' => $login, 'txn_id' => $sendTransaction, 'msg_id' => $providerId];
        if (blank(config('osonsmsservice.api_token'))) {
            $params['txn_id'] = (string) \Illuminate\Support\Str::uuid();
            $params['str_hash'] = $this->signature([$login, $params['txn_id']]);
        }
        $response = $this->individualRequest()->get('https://api.osonsms.com/query_sms.php', $params);

        return $response->ok() ? strtoupper(trim((string) ($response->json('message_state') ?? $response->json('status')))) : '';
    }

    private function individualRequest(): PendingRequest
    {
        $request = Http::acceptJson()->withoutRedirecting()->connectTimeout(20)->timeout(20);
        $token = config('osonsmsservice.api_token');

        return filled($token) ? $request->withToken($token) : $request;
    }

    private function signature(array $parts): string
    {
        return hash('sha256', implode(';', [...$parts, config('osonsmsservice.pass_salt_hash')]));
    }

    /** The bulk endpoint returns a campaign name, without individual delivery IDs. */
    public function submitBulk(array $phones, string $message, string $transaction): array
    {
        if (! self::bulkConfigured()) {
            return ['status' => 'failed', 'error' => 'Отсутствует токен массовой отправки OsonSMS.'];
        }
        // Each line is a single validated 12-digit number. The worker limits a batch to 200 rows.
        $csv = implode("\r\n", $phones)."\r\n";
        $fields = [
            'login' => config('osonsmsservice.login'), 'from' => config('osonsmsservice.sender_name'),
            'msg' => $message, 'txn_id' => $transaction, 'send_at' => now('Asia/Dushanbe')->format('Y-m-d H:i:s'),
        ];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = Http::withToken(config('osonsmsservice.bulk_token'))->acceptJson()
                    ->withoutRedirecting()->connectTimeout(20)->timeout(20)
                    ->attach('file', $csv, 'recipients.csv', ['Content-Type' => 'text/csv'])
                    ->post('https://cab.osonsms.com/api/v1/bulk-sms/create', $fields);
                if ($response->ok() && $response->json('status') === 'success' && is_string($response->json('sms_bulk_name'))) {
                    return ['status' => 'accepted', 'bulk_name' => mb_substr($response->json('sms_bulk_name'), 0, 255)];
                }

                return $this->failure($response->status());
            } catch (ConnectionException) {
                // The documented timeout retry must preserve the transaction ID, CSV and scheduled time.
            }
        }

        return ['status' => 'unknown', 'error' => 'OsonSMS не подтвердил приём пакета после повторного запроса.'];
    }

    private function failure(int $code): array
    {
        $reason = match ($code) {
            400 => 'OsonSMS отклонил список номеров.',
            401, 403, 404 => 'OsonSMS отклонил доступ к аккаунту.',
            402 => 'Недостаточно средств на балансе OsonSMS.',
            422 => 'OsonSMS отклонил параметры рассылки.',
            default => null,
        };

        return ['status' => $reason ? 'failed' : 'unknown', 'error' => $reason ?? 'Приём не подтверждён OsonSMS.'];
    }
}
