<?php

namespace App\Jobs;

use App\Models\AttendanceRawEvent;
use App\Services\Attendance\AttendanceIngestionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessAttendanceRawEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 300];

    public int $uniqueFor = 604800;

    public function __construct(public readonly int $rawEventId) {}

    public function uniqueId(): string
    {
        return (string) $this->rawEventId;
    }

    public function handle(AttendanceIngestionService $ingestion): void
    {
        $raw = AttendanceRawEvent::query()->find($this->rawEventId);
        if ($raw === null) {
            return;
        }

        try {
            $ingestion->reprocess($raw);
        } catch (\Throwable $exception) {
            $raw->forceFill(['processing_error' => mb_substr($exception->getMessage(), 0, 2000)])->save();
            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        AttendanceRawEvent::query()->whereKey($this->rawEventId)->update([
            'processing_status' => 'failed',
            'processing_error' => $exception ? mb_substr($exception->getMessage(), 0, 2000) : 'Queue job failed.',
        ]);
    }
}
