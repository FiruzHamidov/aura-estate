<?php

namespace App\Console\Commands;

use App\Models\AttendanceDevice;
use App\Services\NotificationService;
use Illuminate\Console\Command;

final class MonitorAttendanceDevicesCommand extends Command
{
    protected $signature = 'attendance:monitor-devices';

    protected $description = 'Notify administrators once when an attendance terminal goes offline';

    public function handle(NotificationService $notifications): int
    {
        $cutoff = now()->subMinutes(max(1, (int) config('attendance.offline_threshold_minutes', 10)));
        $devices = AttendanceDevice::query()
            ->where('is_active', true)
            ->whereNull('offline_notified_at')
            ->where(function ($query) use ($cutoff) {
                $query->where('last_seen_at', '<', $cutoff)
                    ->orWhere(function ($neverSeen) use ($cutoff) {
                        $neverSeen->whereNull('last_seen_at')->where('created_at', '<', $cutoff);
                    });
            })
            ->get();

        foreach ($devices as $device) {
            $notifications->notifyAttendanceDeviceOffline($device);
            $device->forceFill(['offline_notified_at' => now()])->save();
        }

        $this->info("Reported {$devices->count()} offline attendance devices.");

        return self::SUCCESS;
    }
}
