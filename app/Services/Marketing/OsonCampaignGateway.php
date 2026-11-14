<?php

namespace App\Services\Marketing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class OsonCampaignGateway
{
    public static function bulkConfigured(): bool
    {
        return filled(config('osonsmsservice.bulk_token'));
    }

    /** Use the installed gateway's signature and credentials over the provider's HTTPS endpoint. */
    public function submitIndividual(string $phone, string $message, string $transaction): array
    {
        $login = config('osonsmsservice.login');
        $sender = config('osonsmsservice.sender_name');
        $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(20)->timeout(20)
            ->get('https://api.osonsms.com/sendsms_v1.php', [
                'from' => $sender, 'phone_number' => $phone, 'msg' => $message, 'txn_id' => $transaction, 'login' => $login,
                'str_hash' => $this->signature([$transaction, $login, $sender, $phone]),
            ]);

        if ($response->successful() && is_numeric($response->json('msg_id')) && (int) $response->json('msg_id') > 0) {
            return ['status' => 'accepted', 'provider_id' => (int) $response->json('msg_id')];
        }

        return $this->failure($response->status());
    }

    public function receipt(int $providerId): string
    {
        $login = config('osonsmsservice.login');
        $transaction = (string) \Illuminate\Support\Str::uuid();
        $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(20)->timeout(20)
            ->get('https://api.osonsms.com/query_sms.php', [
                'login' => $login, 'txn_id' => $transaction, 'msg_id' => $providerId,
                'str_hash' => $this->signature([$login, $transaction]),
            ]);

        return $response->ok() ? strtoupper(trim((string) ($response->json('message_state') ?? $response->json('status')))) : '';
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
