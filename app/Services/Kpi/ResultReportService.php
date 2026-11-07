<?php

namespace App\Services\Kpi;

use App\Models\User;
use App\Services\KpiModuleService;
use App\Support\KpiResultAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ResultReportService
{
    public function __construct(
        private readonly ResultFactService $facts,
        private readonly ResultGoalService $goals,
        private readonly KpiResultAccess $access,
    ) {}

    public function report(User $actor, array $filters): array
    {
        abort_unless($actor->hasPermission('kpi.reports.view'), 403);
        $month = CarbonImmutable::createFromFormat('!Y-m', $filters['month'], 'Asia/Dushanbe');
        $visibleBranches = DB::table('branches');
        \App\Support\BranchVisibility::apply($visibleBranches, 'branches.id');
        $branchIds = $visibleBranches->pluck('id')->map(fn ($id) => (int) $id)->all();
        $matches = function ($row) use ($actor, $filters, $branchIds) {
            if ($row->branch_id && ! in_array((int) $row->branch_id, $branchIds, true)) {
                return false;
            }
            if (! $this->access->visible($actor, $row)) {
                return false;
            }
            foreach (['user_id', 'branch_id', 'branch_group_id'] as $field) {
                if (! empty($filters[$field]) && (int) $row->{$field} !== (int) $filters[$field]) {
                    return false;
                }
            }

            return true;
        };
        $facts = $this->facts->forPeriod($month->toDateString(), $month->endOfMonth()->toDateString())->filter($matches);
        $periods = DB::table('kpi_result_periods')->where('month', $month->toDateString())->get()->filter($matches)
            ->filter(fn ($period) => collect(json_decode($period->snapshot, true)['deals'] ?? [])->every(fn ($deal) => $this->access->visible($actor, $deal)))
            ->keyBy('user_id');
        $users = app(KpiModuleService::class)->reportUsersQuery($actor)->get()->filter(function ($user) use ($matches) {
            return $matches((object) ['user_id' => $user->id, 'branch_id' => $user->branch_id, 'branch_group_id' => $user->branch_group_id]);
        })->keyBy('id');
        // Include staff who moved since closing; visibility comes from their historical fact.
        $historical = User::whereIn('id', $facts->pluck('user_id')->merge($periods->keys())->diff($users->keys())->unique())->get();
        foreach ($historical as $user) {
            $context = $facts->firstWhere('user_id', $user->id) ?? $periods->get($user->id);
            $user->branch_id = $context->branch_id;
            $user->branch_group_id = $context->branch_group_id;
            $users->put($user->id, $user);
        }
        $groupNames = DB::table('branch_groups')->whereIn('id', $users->pluck('branch_group_id')->filter()->unique())->pluck('name', 'id');
        $rows = $users->map(function (User $user) use ($facts, $periods, $filters, $actor, $groupNames) {
            if ($closed = $periods->get($user->id)) {
                return json_decode($closed->snapshot, true) + ['branch_group_name' => $groupNames->get($closed->branch_group_id), 'closed' => true, 'closed_at' => $closed->created_at, 'period_version' => $closed->version];
            }

            return $this->employee($user, $filters['month'], $facts->where('user_id', $user->id), $actor) + ['closed' => false, 'branch_group_name' => $groupNames->get($user->branch_group_id)];
        })->values();

        return [
            'month' => $filters['month'], 'timezone' => 'Asia/Dushanbe',
            'generated_at' => now()->toIso8601String(), 'rows' => $rows,
            'totals' => [
                'sales_count' => $rows->sum('sale.count'), 'rentals_count' => $rows->sum('rental.count'),
                'achieved' => $rows->filter(fn ($row) => $row['sale']['achieved'] || $row['rental']['achieved'])->count(),
                'incomplete' => $rows->filter(fn ($row) => ! $row['sale']['amount_complete'] || ! $row['rental']['amount_complete'])->count(),
            ],
        ];
    }

    public function employee(User $user, string $month, Collection $facts, ?User $actor = null): array
    {
        $row = ['user_id' => $user->id, 'name' => $user->name, 'branch_id' => $user->branch_id, 'branch_group_id' => $user->branch_group_id];
        foreach (['sale', 'rental'] as $kind) {
            $goal = $this->goals->resolve($user->id, $user->branch_group_id, $month, $kind);
            // A group template applies to this employee's result; authorize that
            // personal projection while retaining the goal's branch/group scope.
            if ($goal && $actor && ! $this->access->visible($actor, array_replace($goal, ['user_id' => $goal['user_id'] ?? $user->id]))) {
                $goal = null;
            }
            $items = $facts->where('kind', $kind);
            $basis = $goal['rental_basis'] ?? ($kind === 'rental' ? 'monthly_rent' : null);
            $currency = $goal['currency'] ?? 'TJS';
            $amounts = $items->map(fn ($fact) => $this->facts->amount($fact, $currency, $basis));
            $row[$kind] = ResultProgress::calculate($goal, $items->count(), $amounts->filter(fn ($n) => $n !== null)->sum(), $amounts->filter(fn ($n) => $n === null)->count())
                + ['goal' => $goal, 'currency' => $currency, 'rental_basis' => $basis];
        }
        $row['deals'] = $facts->map(fn ($fact) => [
            'user_id' => $fact->user_id, 'branch_id' => $fact->branch_id, 'branch_group_id' => $fact->branch_group_id,
            'source_event' => $fact->source_event, 'property_id' => $fact->property_id,
            'kind' => $fact->kind, 'closed_at' => $fact->closed_at, 'amount' => $fact->amount,
            'kpi_amount' => $this->facts->amount($fact, $row[$fact->kind]['currency'], $row[$fact->kind]['rental_basis']),
            'kpi_currency' => $row[$fact->kind]['currency'], 'rental_basis' => $row[$fact->kind]['rental_basis'],
            'currency' => $fact->currency, 'rental_monthly_amount' => $fact->rental_monthly_amount,
            'rental_contract_amount' => $fact->rental_contract_amount, 'fx_rate' => $fact->fx_rate,
            'legacy_scope' => $fact->legacy_scope ?? false,
        ])->values()->all();

        return $row;
    }

    public function close(User $actor, string $month, int $userId): array
    {
        abort_unless($actor->hasPermission('kpi.periods.lock'), 403);
        abort_unless($month < CarbonImmutable::now('Asia/Dushanbe')->format('Y-m'), 422, 'Можно закрыть только завершённый месяц.');

        return DB::transaction(function () use ($actor, $month, $userId) {
            [$actor] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquireMany($actor, [], [$userId]);
            abort_unless($actor->hasPermission('kpi.periods.lock'), 403);
            $target = User::findOrFail($userId);
            abort_unless($this->access->visible($actor, ['user_id' => $target->id, 'branch_id' => $target->branch_id, 'branch_group_id' => $target->branch_group_id]), 403);
            $period = CarbonImmutable::parse($month.'-01', 'Asia/Dushanbe');
            $allFacts = $this->facts->forPeriod($period->toDateString(), $period->endOfMonth()->toDateString(), true)->where('user_id', $userId);
            abort_if($allFacts->contains(fn ($fact) => ! $this->access->visible($actor, $fact)), 403, 'Закрытие полного месяца требует доступа ко всем его сделкам.');
            $report = $this->report($actor, ['month' => $month, 'user_id' => $userId]);
            $row = $report['rows']->first();
            abort_unless($row, 403);
            if ($row['closed']) {
                return $row;
            }
            unset($row['closed']);
            DB::table('kpi_result_periods')->insert([
                'month' => $month.'-01', 'user_id' => $userId,
                'branch_id' => $row['branch_id'], 'branch_group_id' => $row['branch_group_id'],
                'snapshot' => json_encode($row, JSON_THROW_ON_ERROR), 'closed_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $row + ['closed' => true];
        }, 3);
    }
}
