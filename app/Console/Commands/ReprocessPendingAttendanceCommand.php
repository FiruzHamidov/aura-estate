<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAttendanceRawEvent;
use App\Models\AttendanceRawEvent;
use Illuminate\Console\Command;

final class ReprocessPendingAttendanceCommand extends Command
{
    protected $signature = 'attendance:reprocess-pending {--limit=1000}';

    protected $description = 'Queue pending or failed attendance raw events for normalization';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $ids = AttendanceRawEvent::query()
            ->whereIn('processing_status', ['pending', 'failed'])
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        foreach ($ids as $id) {
            ProcessAttendanceRawEvent::dispatch((int) $id);
            AttendanceRawEvent::query()
                ->whereKey($id)
                ->whereIn('processing_status', ['pending', 'failed', 'queued'])
                ->update(['processing_status' => 'queued', 'processing_error' => null]);
        }
        $this->info("Queued {$ids->count()} attendance events.");

        return self::SUCCESS;
    }
}
