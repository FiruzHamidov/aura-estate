<?php

namespace App\Services\Kpi;

use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** One-time, auditable transition: new revisions, never rewritten history. */
final class ResultAmountUpgrade
{
    public function run(string $month): array
    {
        return DB::transaction(function () use ($month) {
            $period = CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Dushanbe');
            $facts = app(ResultFactService::class);
            $sources = $facts->forPeriod($period->toDateString(), $period->endOfMonth()->toDateString(), true);
            $recorded = 0;
            foreach ($sources as $source) {
                if (($source->participant_commissions ?? null) !== null && ! ($source->legacy_scope ?? false)) {
                    continue;
                }
                $property = Property::whereKey($source->property_id)->lockForUpdate()->first();
                if (! $property || ! in_array($property->moderation_status, ['sold', 'rented'], true)) {
                    continue;
                }
                // The current property contains the most recent confirmed financials.
                // Prior source events and closed report/reward snapshots remain intact.
                $facts->record($property, 'income-basis-upgrade-'.$month.'-'.$property->id, null);
                $recorded++;
            }
            $latest = DB::table('kpi_result_goals')->where('month', $month.'-01')
                ->orderByDesc('version')->lockForUpdate()->get()->unique(fn ($row) => $row->scope_key.':'.$row->kind);
            $changed = [];
            foreach ($latest as $goal) {
                if ($goal->amount_basis !== 'deal_value') {
                    continue;
                }
                $new = (array) $goal;
                unset($new['id']);
                $new['version'] = (int) $goal->version + 1;
                $new['amount_basis'] = 'company_income';
                $new['comment'] = 'Переход на доход компании по согласованию: пороги сохранены. Предыдущая версия #'.$goal->id.'.';
                $new['created_at'] = now();
                $new['updated_at'] = now();
                $id = DB::table('kpi_result_goals')->insertGetId($new);
                $changed[] = ['from_goal_id' => $goal->id, 'to_goal_id' => $id];
            }

            return ['month' => $month, 'financial_snapshots' => $recorded, 'goal_revisions' => $changed];
        }, 3);
    }
}
