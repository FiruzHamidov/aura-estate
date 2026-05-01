<?php

namespace App\Console\Commands;

use App\Models\Story;
use Illuminate\Console\Command;

class ExpireStoriesCommand extends Command
{
    protected $signature = 'stories:expire';
    protected $description = 'Archive active stories that reached expires_at.';

    public function handle(): int
    {
        $affected = Story::query()
            ->where('status', Story::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => Story::STATUS_ARCHIVED]);

        $this->info("Archived stories: {$affected}");

        return self::SUCCESS;
    }
}

