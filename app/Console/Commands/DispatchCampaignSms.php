<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCampaignSms;
use App\Jobs\SubmitBulkCampaignSms;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final class DispatchCampaignSms extends Command
{
    protected $signature = 'sms:dispatch-campaigns';

    protected $description = 'Queue due campaign SMS and delivery receipt checks';

    public function handle(): int
    {
        // An interrupted submission cannot safely be sent a second time.
        SmsCampaignRecipient::where('status', 'sending')->where('next_action_at', '<=', now())
            ->update(['status' => 'unknown', 'next_action_at' => null]);

        DB::transaction(function () {
            $due = SmsCampaignRecipient::whereIn('status', ['pending', 'accepted'])
                ->where('next_action_at', '<=', now())->orderBy('next_action_at')->orderBy('id')
                ->limit(200)->lockForUpdate()->get(['id', 'campaign_id', 'status']);
            $ids = $due->pluck('id');
            if ($ids->isEmpty()) {
                return;
            }
            // A lease recovers lost jobs. Conditional transitions protect against overlapping workers.
            SmsCampaignRecipient::whereKey($ids)->update(['next_action_at' => now()->addMinutes(15)]);
            $transports = SmsCampaign::whereKey($due->pluck('campaign_id')->unique())->pluck('transport', 'id');
            $jobs = [];
            foreach ($due->groupBy('campaign_id') as $campaignId => $rows) {
                if ($transports[$campaignId] === 'bulk') {
                    $jobs[] = new SubmitBulkCampaignSms($campaignId, $rows->pluck('id')->all());
                } else {
                    foreach ($rows as $row) {
                        $jobs[] = new ProcessCampaignSms($row->id, $row->status === 'accepted');
                    }
                }
            }
            Queue::connection('database')->bulk($jobs);
        }, 3);

        return self::SUCCESS;
    }
}
