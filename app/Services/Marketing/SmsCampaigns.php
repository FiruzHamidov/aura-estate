<?php

namespace App\Services\Marketing;

use App\Models\SmsCampaign;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SmsCampaigns
{
    public function __construct(private readonly SmsAudience $audience) {}

    public function preview(User $actor, array $data): array
    {
        $this->checkSchedule($data);
        $resolved = $this->audience->resolve($actor, $data['segment']);

        return $resolved['summary'] + ['preview_token' => $this->token($actor, $data, $resolved['recipients'])];
    }

    public function create(User $actor, array $data): SmsCampaign
    {
        return DB::transaction(function () use ($actor, $data) {
            // Serialize concurrent submissions for this author, including a retry after a lost response.
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(SmsCampaign::allowed($actor), 403);
            $existing = SmsCampaign::where('created_by', $actor->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->preview_token, $data['preview_token']), 409, 'Эта рассылка уже создана с другими параметрами.');

                return $existing;
            }
            $this->checkSchedule($data);
            $resolved = $this->audience->resolve($actor, $data['segment']);
            $recipients = $resolved['recipients'];
            abort_unless(hash_equals($this->token($actor, $data, $recipients), $data['preview_token']), 409,
                'Аудитория или параметры изменились. Проверьте рассылку ещё раз.');
            if ($recipients === []) {
                throw ValidationException::withMessages(['segment' => 'В этом сегменте нет получателей.']);
            }
            $scheduled = isset($data['scheduled_at'])
                ? CarbonImmutable::parse($data['scheduled_at'])->setTimezone(config('app.timezone')) : now();
            $campaign = SmsCampaign::create([
                'created_by' => $actor->id, 'name' => $data['name'], 'message' => $data['message'],
                'request_id' => $data['request_id'], 'preview_token' => $data['preview_token'],
                'transport' => OsonCampaignGateway::bulkConfigured() ? 'bulk' : 'individual',
                'segment' => $data['segment'], 'scheduled_at' => $scheduled,
            ]);
            $rows = [];
            foreach ($recipients as $phone => $sourceId) {
                $rows[] = ['campaign_id' => $campaign->id, 'source_id' => $sourceId, 'phone' => $phone, 'next_action_at' => $scheduled];
                if (count($rows) === 200) {
                    DB::table('sms_campaign_recipients')->insert($rows);
                    $rows = [];
                }
            }
            if ($rows) {
                DB::table('sms_campaign_recipients')->insert($rows);
            }

            return $campaign;
        }, 3);
    }

    private function token(User $actor, array $data, array $recipients): string
    {
        $segment = $data['segment'];
        ksort($segment);
        $hash = hash_init('sha256', HASH_HMAC, config('app.key'));
        hash_update($hash, json_encode([$actor->id, $data['request_id'], $data['name'], $data['message'],
            $data['scheduled_at'] ?? null, $segment], JSON_THROW_ON_ERROR));
        foreach ($recipients as $phone => $id) {
            hash_update($hash, "|$id:$phone");
        }

        return hash_final($hash);
    }

    private function checkSchedule(array $data): void
    {
        if (isset($data['scheduled_at']) && ! CarbonImmutable::parse($data['scheduled_at'])->isFuture()) {
            throw ValidationException::withMessages(['scheduled_at' => 'Выберите время отправки в будущем.']);
        }
    }
}
