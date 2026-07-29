<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserLocationPoint;
use App\Models\UserLocationTrackingSetting;
use Illuminate\Console\Command;

class PruneUserLocationHistoryCommand extends Command
{
    protected $signature = 'locations:prune-history {--chunk=1000}';

    protected $description = 'Delete user location history older than the configured retention period';

    public function handle(): int
    {
        $chunk = max(100, min(5000, (int) $this->option('chunk')));
        $deleted = 0;

        UserLocationTrackingSetting::query()
            ->select(['user_id', 'history_retention_days'])
            ->orderBy('user_id')
            ->each(function (UserLocationTrackingSetting $settings) use (&$deleted, $chunk) {
                $deleted += $this->pruneUser(
                    (int) $settings->user_id,
                    max(1, (int) $settings->history_retention_days),
                    $chunk
                );
            });

        User::query()
            ->whereHas('role', fn ($query) => $query->whereIn('slug', config('location_tracking.tracked_roles', ['agent', 'mop'])))
            ->whereDoesntHave('locationTrackingSetting')
            ->select('id')
            ->orderBy('id')
            ->each(function (User $user) use (&$deleted, $chunk) {
                $deleted += $this->pruneUser(
                    (int) $user->id,
                    (int) config('location_tracking.history_retention_days', 90),
                    $chunk
                );
            });

        $this->info("Deleted {$deleted} expired location points.");

        return self::SUCCESS;
    }

    private function pruneUser(int $userId, int $retentionDays, int $chunk): int
    {
        $deleted = 0;
        $cutoff = now()->subDays($retentionDays);

        do {
            $ids = UserLocationPoint::query()
                ->where('user_id', $userId)
                ->where('captured_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            $count = $ids->isEmpty()
                ? 0
                : UserLocationPoint::query()->whereIn('id', $ids)->delete();
            $deleted += $count;
        } while ($count === $chunk);

        return $deleted;
    }
}
