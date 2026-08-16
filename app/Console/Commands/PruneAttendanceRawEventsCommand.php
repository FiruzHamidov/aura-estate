<?php

namespace App\Console\Commands;

use App\Models\AttendanceIngestRequest;
use App\Models\AttendanceRawEvent;
use Illuminate\Console\Command;

final class PruneAttendanceRawEventsCommand extends Command
{
    protected $signature = 'attendance:prune-raw';

    protected $description = 'Delete expired raw attendance payloads while keeping normalized events';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) config('attendance.raw_retention_days', 90)));
        $deleted = AttendanceRawEvent::query()
            ->where('received_at', '<', $cutoff)
            ->delete();
        $requestsDeleted = AttendanceIngestRequest::query()
            ->where('received_at', '<', $cutoff)
            ->delete();
        $this->info("Deleted {$deleted} expired raw events and {$requestsDeleted} ingest requests.");

        return self::SUCCESS;
    }
}
