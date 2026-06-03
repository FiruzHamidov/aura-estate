<?php

namespace App\Console\Commands;

use App\Models\ApiRequestLog;
use Illuminate\Console\Command;

class PruneApiRequestLogsCommand extends Command
{
    protected $signature = 'audit:prune-api-request-logs';

    protected $description = 'Delete API request audit logs older than the configured retention period.';

    public function handle(): int
    {
        $retentionDays = max(1, (int) config('audit.api_requests.retention_days', 90));
        $cutoff = now()->subDays($retentionDays);

        $deleted = ApiRequestLog::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Deleted {$deleted} API request audit logs older than {$retentionDays} days.");

        return self::SUCCESS;
    }
}
