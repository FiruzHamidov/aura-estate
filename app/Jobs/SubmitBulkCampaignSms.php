<?php

namespace App\Jobs;

use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\User;
use App\Services\Marketing\OsonCampaignGateway;
use App\Services\Marketing\SmsAudience;
use App\Support\InternationalPhone;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class SubmitBulkCampaignSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct(public readonly int $campaignId, public readonly array $ids) {}

    public function handle(OsonCampaignGateway $provider, SmsAudience $audience): void
    {
        $campaign = SmsCampaign::findOrFail($this->campaignId);
        if ($campaign->scheduled_at->isFuture() || $campaign->transport !== 'bulk') {
            return;
        }
        $phones = DB::transaction(function () use ($campaign, $audience) {
            $rows = $campaign->recipients()->whereKey($this->ids)->where('status', 'pending')->orderBy('id')->lockForUpdate()->get();
            $actor = User::find($campaign->created_by);
            $current = SmsCampaign::allowed($actor)
                ? $audience->query($actor, $campaign->segment)->whereIntegerInRaw('id', $rows->pluck('source_id'))->pluck('phone', 'id')
                : collect();
            $excluded = DB::table('sms_campaign_exclusions')->whereIn('phone', $rows->pluck('phone'))->pluck('phone')->flip();
            $phones = [];
            $skipped = [];
            foreach ($rows as $row) {
                if ($excluded->has($row->phone) || InternationalPhone::e164($current->get($row->source_id)) !== $row->phone) {
                    $skipped[] = $row->id;
                } else {
                    $phones[$row->id] = substr($row->phone, 1);
                }
            }
            SmsCampaignRecipient::whereKey($skipped)->update(['status' => 'skipped', 'next_action_at' => null]);
            SmsCampaignRecipient::whereKey(array_keys($phones))->update(['status' => 'sending', 'next_action_at' => now()->addMinutes(5)]);

            return $phones;
        }, 3);
        if (! $phones) {
            return;
        }
        $transaction = 'bulk_'.$campaign->id.'_'.substr(hash('sha256', implode(',', array_keys($phones))), 0, 24);
        $result = $provider->submitBulk($phones, $campaign->message, $transaction);
        SmsCampaignRecipient::whereKey(array_keys($phones))->where('status', 'sending')->update($result + [
            'submitted_at' => now(), 'next_action_at' => null,
        ]);
    }
}
