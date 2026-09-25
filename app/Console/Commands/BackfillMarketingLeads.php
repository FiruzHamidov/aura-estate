<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\Marketing\LeadReceiptTracker;
use Illuminate\Console\Command;

class BackfillMarketingLeads extends Command
{
    protected $signature = 'marketing:backfill-leads';

    protected $description = 'Capture historical marketing leads without inventing assignment dates';

    public function handle(LeadReceiptTracker $tracker): int
    {
        Lead::withTrashed()->orderBy('id')->chunkById(200, function ($leads) use ($tracker) {
            foreach ($leads as $lead) {
                $tracker->capture($lead, true);
            }
        });
        $this->info('Historical receipts captured. Original assignment history is marked unknown.');

        return self::SUCCESS;
    }
}
