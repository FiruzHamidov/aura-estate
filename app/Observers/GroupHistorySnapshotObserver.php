<?php

namespace App\Observers;

use App\Models\AttendanceDailySummary;
use App\Models\DailyReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class GroupHistorySnapshotObserver
{
    public function creating(Model $record): void
    {
        if (! Schema::hasColumn($record->getTable(), 'branch_group_id') || $record->branch_group_id) {
            return;
        }
        if ($record instanceof DailyReport) {
            if ($record->report_date?->toDateString() === now('Asia/Dushanbe')->toDateString()) {
                $record->branch_group_id = User::query()->whereKey($record->user_id)->value('branch_group_id');
            }
        } elseif ($record instanceof \App\Models\AttendanceLeave || $record instanceof \App\Models\AttendanceDuty) {
            if ($record->date_from?->toDateString() >= now(config('attendance.timezone', 'Asia/Dushanbe'))->toDateString()) {
                $record->branch_group_id = User::query()->whereKey($record->user_id)->value('branch_group_id');
            }
        } elseif ($record instanceof AttendanceDailySummary && Schema::hasColumn('attendance_events', 'branch_group_id')) {
            $day = CarbonImmutable::parse($record->work_date, config('attendance.timezone', 'Asia/Dushanbe'));
            $groups = DB::table('attendance_events')->where('user_id', $record->user_id)
                ->whereBetween('occurred_at', [$day->startOfDay()->utc(), $day->endOfDay()->utc()])
                ->distinct()->pluck('branch_group_id');
            if ($groups->count() === 1) {
                $record->branch_group_id = $groups->first();
            }
        }
    }
}
