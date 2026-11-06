<?php

namespace App\Services\Kpi;

use App\Models\User;
use App\Support\KpiResultAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ResultPeriodAdjustment
{
    public function run(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data) {
            [$actor] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquireMany($actor, [], [$data['user_id']]);
            abort_unless($actor->hasPermission(($data['preview'] ?? false) ? 'kpi.adjustments.view' : 'kpi.adjustments.manage'), 403);
            $period = DB::table('kpi_result_periods')->where('month', $data['month'].'-01')->where('user_id', $data['user_id'])->lockForUpdate()->first();
            abort_unless($period, 422, 'Сначала закройте период.');
            $access = app(KpiResultAccess::class);
            abort_unless($access->visible($actor, $period), 403);
            $month = CarbonImmutable::parse($period->month, 'Asia/Dushanbe');
            $facts = app(ResultFactService::class)->forPeriod($month->toDateString(), $month->endOfMonth()->toDateString(), true)->where('user_id', $data['user_id']);
            $before = json_decode($period->snapshot, true, flags: JSON_THROW_ON_ERROR);
            abort_unless($facts->every(fn ($fact) => $access->visible($actor, $fact))
                && collect($before['deals'])->every(fn ($fact) => $access->visible($actor, $fact)), 403);
            $target = User::findOrFail($data['user_id']);
            $target->branch_id = $period->branch_id;
            $target->branch_group_id = $period->branch_group_id;
            $after = app(ResultReportService::class)->employee($target, $data['month'], $facts, $actor);
            $token = hash('sha256', json_encode([$period->version, $before, $after], JSON_THROW_ON_ERROR));
            if ($data['preview'] ?? false) {
                return ['before' => $before, 'after' => $after, 'version' => $period->version, 'preview_token' => $token];
            }
            abort_unless(hash_equals($token, $data['preview_token'] ?? ''), 409, 'Данные изменились. Повторите предварительный расчёт.');
            DB::table('kpi_result_period_revisions')->insert([
                'period_id' => $period->id, 'version' => $period->version + 1,
                'before' => $period->snapshot, 'after' => json_encode($after, JSON_THROW_ON_ERROR),
                'reason' => $data['reason'], 'actor_id' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('kpi_result_periods')->where('id', $period->id)->update([
                'version' => $period->version + 1, 'snapshot' => json_encode($after, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);

            return ['version' => $period->version + 1, 'snapshot' => $after];
        }, 3);
    }
}
