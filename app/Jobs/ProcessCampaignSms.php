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
use Throwable;

final class ProcessCampaignSms implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public int $tries = 1;

    public function __construct(public readonly int $recipientId, public readonly bool $checkReceipt = false) {}

    public function handle(OsonCampaignGateway $provider, SmsAudience $audience): void
    {
        $recipient = SmsCampaignRecipient::find($this->recipientId);
        if (! $recipient) {
            return;
        }
        if ($this->checkReceipt) {
            if ($recipient->status === 'accepted') {
                $this->receipt($provider, $recipient);
            }

            return;
        }
        if ($recipient->status !== 'pending') {
            return;
        }
        $campaign = SmsCampaign::findOrFail($recipient->campaign_id);
        if ($campaign->transport !== 'individual' || $campaign->scheduled_at->isFuture()) {
            return;
        }
        $actor = User::find($campaign->created_by);
        $eligible = SmsCampaign::allowed($actor)
            && ! DB::table('sms_campaign_exclusions')->where('phone', $recipient->phone)->exists()
            && InternationalPhone::e164($audience->query($actor, $campaign->segment)
                ->whereKey($recipient->source_id)->value('phone')) === $recipient->phone;

        // Only one worker may cross the provider boundary, even after queue redelivery.
        $claimed = $this->update('pending', [
            'status' => $eligible ? 'sending' : 'skipped',
            'next_action_at' => $eligible ? now()->addMinutes(5) : null,
        ]);
        if (! $claimed || ! $eligible) {
            return;
        }

        try {
            $result = $provider->submitIndividual(substr($recipient->phone, 1), $campaign->message,
                'campaign_'.$campaign->id.'_'.$recipient->id);
        } catch (Throwable) {
            // The provider may have accepted the SMS before the connection was interrupted.
            $result = ['status' => 'unknown', 'error' => 'OsonSMS не подтвердил приём сообщения.'];
        }
        $this->update('sending', $result + [
            'submitted_at' => now(), 'next_action_at' => $result['status'] === 'accepted' ? now()->addMinutes(2) : null,
        ]);
    }

    private function receipt(OsonCampaignGateway $provider, SmsCampaignRecipient $recipient): void
    {
        try {
            $state = $provider->receipt($recipient->provider_id);
        } catch (Throwable) {
            $state = '';
        }
        $status = match ($state) {
            'DELIVERED' => 'delivered',
            'EXPIRED', 'DELETED', 'UNDELIVERABLE', 'REJECTED' => 'failed',
            'UNKNOWN' => 'unknown',
            default => $recipient->submitted_at->lt(now()->subDays(2)) ? 'unknown' : 'accepted',
        };
        $this->update('accepted', ['status' => $status, 'next_action_at' => $status === 'accepted' ? now()->addMinutes(15) : null]);
    }

    private function update(string $expected, array $changes): int
    {
        return SmsCampaignRecipient::whereKey($this->recipientId)->where('status', $expected)->update($changes);
    }
}
