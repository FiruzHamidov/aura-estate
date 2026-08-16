<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Attendance\AttendanceParticipantService;
use App\Services\Attendance\AttendanceSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class SummarizeAttendanceCommand extends Command
{
    protected $signature = 'attendance:summarize {date? : Work date in Y-m-d format}';

    protected $description = 'Rebuild attendance summaries, including absences, for a work date';

    public function handle(AttendanceSummaryService $summaries, AttendanceParticipantService $participants): int
    {
        $timezone = (string) config('attendance.timezone', 'Asia/Dushanbe');
        $date = $this->argument('date') ?: CarbonImmutable::now($timezone)->subDay()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            $this->error('Date must use Y-m-d format.');

            return self::INVALID;
        }
        $count = 0;
        $participants->query()
            ->orderBy('id')
            ->each(function (User $user) use ($summaries, $date, &$count) {
                if (! $summaries->isWorkingDay($user, (string) $date)) {
                    return;
                }
                $summaries->recompute($user, (string) $date);
                $count++;
            });
        $this->info("Attendance summaries rebuilt for {$count} users.");

        return self::SUCCESS;
    }
}
