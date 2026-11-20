<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\BranchVisibility;
use App\Support\RopGroupAccess;
use Illuminate\Support\Facades\DB;

/** Current inventory, including closed/deleted listings, independent of KPI attribution. */
final class ListingStatusReport
{
    public const STATUSES = [
        'approved' => 'Одобрено', 'pending' => 'На модерации', 'deposit' => 'Залог',
        'sold' => 'Продано агентом', 'sold_by_owner' => 'Продано владельцем',
        'rented' => 'Сдано в аренду', 'denied' => 'Клиент отказался',
        'rejected' => 'Отклонено', 'draft' => 'Черновики', 'deleted' => 'Удалено',
        'unknown' => 'Другой / не указан',
    ];

    public function build(User $actor, array $filters): array
    {
        abort_unless($actor->hasPermission('property_reports.view')
            && $actor->hasPermission('property_reports.inventory.view')
            && collect(['all', 'branch', 'group'])->contains(fn ($scope) => $actor->hasPermission('property_reports.scope.'.$scope)), 403, 'FORBIDDEN_ACTION');

        return DB::transaction(fn () => $this->snapshot($actor, $filters));
    }

    private function snapshot(User $actor, array $filters): array
    {
        $access = app(RopGroupAccess::class);
        $assigned = $actor->usesAssignedGroupScope();
        $branchScope = $assigned || $actor->hasPermission('property_reports.scope.branch');
        $groupScope = $actor->hasPermission('property_reports.scope.group');
        $global = ! $branchScope && ! $groupScope && $actor->hasPermission('property_reports.scope.all');
        $includeHidden = (bool) ($filters['include_hidden'] ?? false);
        abort_if($includeHidden && ! $global, 403, 'FORBIDDEN_ACTION');

        $branchesQuery = DB::table('branches')->select('id', 'name', 'is_hidden');
        if (! $includeHidden) {
            $branchesQuery->where('is_hidden', false);
        }
        if ($branchScope || $groupScope) {
            $branchesQuery->where('id', $actor->branch_id ?? 0);
        }
        $branches = $branchesQuery->orderBy('id')->get()->keyBy('id');
        $groupsQuery = DB::table('branch_groups')->whereIn('branch_id', $branches->keys())->select('id', 'name', 'branch_id', 'is_archived');
        if ($assigned) {
            $groupsQuery->whereIn('id', $access->groupsQuery($actor));
        }
        if ($groupScope) {
            $groupsQuery->where('id', $actor->branch_group_id ?? 0);
        }
        $groups = $groupsQuery->orderBy('id')->get()->keyBy('id');
        $ropsQuery = DB::table('users')->whereIn('branch_id', $branches->keys())
            ->whereIn('role_id', Role::query()->usingAssignedGroups()->select('id'))
            ->whereNull('deleted_at')->select('id', 'name', 'branch_id', 'status');
        if ($assigned) {
            $ropsQuery->where('id', $actor->id);
        }
        if ($groupScope) {
            $ropsQuery->whereIn('id', DB::table('rop_branch_groups')->whereIn('branch_group_id', $groups->keys())->select('rop_id'));
        }
        $rops = $ropsQuery->orderBy('id')->get()->keyBy('id');
        $assignments = DB::table('rop_branch_groups')->whereIn('branch_group_id', $groups->keys())->whereIn('rop_id', $rops->keys())
            ->orderBy('rop_id')->get(['rop_id', 'branch_group_id'])
            ->filter(fn ($r) => (int) $groups[$r->branch_group_id]->branch_id === (int) $rops[$r->rop_id]->branch_id)
            ->groupBy('branch_group_id');

        foreach (['branch_id' => $branches, 'branch_group_id' => $groups, 'rop_id' => $rops] as $key => $allowed) {
            abort_if(isset($filters[$key]) && ! $allowed->has((int) $filters[$key]), 403, 'RBAC_GROUP_SCOPE_VIOLATION');
        }
        $branchId = isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;
        $groupId = isset($filters['branch_group_id']) ? (int) $filters['branch_group_id'] : null;
        $ropId = isset($filters['rop_id']) ? (int) $filters['rop_id'] : null;
        abort_if($branchId && $groupId && (int) $groups[$groupId]->branch_id !== (int) $branchId, 422, 'FILTER_SCOPE_MISMATCH');
        abort_if($branchId && $ropId && (int) $rops[$ropId]->branch_id !== (int) $branchId, 422, 'FILTER_SCOPE_MISMATCH');
        $selectedGroups = $groups->filter(fn ($g) => (! $branchId || (int) $g->branch_id === (int) $branchId)
            && (! $groupId || (int) $g->id === (int) $groupId)
            && (! $ropId || $assignments->get($g->id, collect())->contains('rop_id', (int) $ropId)));
        abort_if($groupId && $ropId && ! $selectedGroups->has($groupId), 422, 'FILTER_SCOPE_MISMATCH');

        $branch = 'COALESCE(properties.branch_id, agent.branch_id, author.branch_id)';
        $group = 'COALESCE(properties.branch_group_id, agent.branch_group_id, author.branch_group_id)';
        $query = DB::table('properties')->leftJoin('users as agent', 'agent.id', '=', 'properties.agent_id')
            ->leftJoin('users as author', 'author.id', '=', 'properties.created_by');
        if (! $includeHidden) {
            BranchVisibility::records($query, 'properties');
        }
        $query->where(function ($q) use ($branch, $branches, $global) {
            $q->whereIn(DB::raw($branch), $branches->keys());
            if ($global) {
                $q->orWhereNull(DB::raw($branch));
            }
        });
        // Employee fallback must never grant ROP access to historical unowned records.
        if ($assigned) {
            $access->scope($query, $actor, 'properties.branch_group_id', 'properties.branch_id');
        }
        if ($groupScope) {
            $query->whereIn(DB::raw($group), $groups->keys());
        }
        if ($branchId) {
            $query->where(DB::raw($branch), $branchId);
        }
        if ($groupId || $ropId) {
            $query->whereIn(DB::raw($group), $selectedGroups->keys());
        }
        $facts = $query->selectRaw($branch.' as branch_id, '.$group.' as group_id, properties.moderation_status as status, COUNT(*) as total')
            ->groupByRaw($branch.', '.$group.', properties.moderation_status')->get();

        $byBranch = [];
        $byGroup = [];
        $byRop = [];
        foreach ($branches as $b) {
            if (! $branchId || (int) $b->id === (int) $branchId) {
                $byBranch[(string) $b->id] = $this->row((string) $b->id, $b->name.($b->is_hidden ? ' (скрыт)' : ''));
            }
        }
        $ownersFor = fn ($id) => $assignments->get($id, collect())->pluck('rop_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $ropKey = fn ($ids) => $ids === [] ? 'unassigned' : implode('-', $ids);
        $ropName = fn ($ids) => $ids === [] ? 'Без РОПа' : implode(' + ', array_map(fn ($id) => $rops[$id]->name.($rops[$id]->status === 'inactive' ? ' (неактивен)' : ''), $ids));
        foreach ($selectedGroups as $g) {
            $byGroup[(string) $g->id] = $this->row((string) $g->id, $g->name.($g->is_archived ? ' (архив)' : ''), $branches[$g->branch_id]->name);
            $ids = $ownersFor($g->id);
            $byRop[$ropKey($ids)] ??= $this->row($ropKey($ids), $ropName($ids));
        }
        // Preserve zero counts for ROPs without assigned groups.
        foreach ($rops as $rop) {
            if (($branchId && (int) $rop->branch_id !== (int) $branchId) || ($ropId && (int) $rop->id !== (int) $ropId) || $groupId) {
                continue;
            }
            if (! $assignments->flatten(1)->contains('rop_id', $rop->id)) {
                $byRop[(string) $rop->id] = $this->row((string) $rop->id, $ropName([$rop->id]));
            }
        }
        $totals = $this->row('total', 'Итого');
        foreach ($facts as $fact) {
            $status = array_key_exists($fact->status ?? '', self::STATUSES) ? $fact->status : 'unknown';
            $bKey = $fact->branch_id === null ? 'unassigned' : (string) $fact->branch_id;
            $gKey = $fact->group_id === null ? 'unassigned' : ($groups->has($fact->group_id) ? (string) $fact->group_id : 'unavailable');
            $ids = $ownersFor($fact->group_id);
            $rKey = $ropKey($ids);
            $byBranch[$bKey] ??= $this->row($bKey, 'Без филиала');
            if (! isset($byGroup[$gKey])) {
                $g = $groups->get($fact->group_id);
                $byGroup[$gKey] = $g
                    ? $this->row($gKey, $g->name.($g->is_archived ? ' (архив)' : ''), $branches[$g->branch_id]->name)
                    : $this->row($gKey, $gKey === 'unavailable' ? 'Группа вне доступной области' : 'Без группы');
            }
            $byRop[$rKey] ??= $this->row($rKey, $ropName($ids));
            $this->add($byBranch[$bKey], $status, (int) $fact->total);
            $this->add($byGroup[$gKey], $status, (int) $fact->total);
            $this->add($byRop[$rKey], $status, (int) $fact->total);
            $this->add($totals, $status, (int) $fact->total);
        }
        $sort = fn ($rows) => collect($rows)->sortByDesc('total')->values()->all();

        return [
            'generated_at' => now('Asia/Dushanbe')->toIso8601String(),
            'scope' => ['type' => $assigned ? 'assigned_groups' : ($groupScope ? 'group' : ($branchScope ? 'branch' : 'all')),
                'label' => $assigned ? 'Ваши закреплённые группы' : ($global ? 'Все доступные филиалы' : 'Ваша область отчётов'), 'can_include_hidden' => $global],
            'filters' => ['branch_id' => $branchId, 'branch_group_id' => $groupId, 'rop_id' => $ropId, 'include_hidden' => $includeHidden],
            'options' => ['branches' => $branches->values()->all(),
                'groups' => $groups->map(fn ($g) => [...(array) $g, 'rop_ids' => $ownersFor($g->id)])->values()->all(),
                'rops' => $rops->values()->all()],
            'statuses' => collect(self::STATUSES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'totals' => $totals, 'by_branch' => $sort($byBranch), 'by_group' => $sort($byGroup), 'by_rop' => $sort($byRop),
        ];
    }

    private function row(string $key, string $name, ?string $branchName = null): array
    {
        return ['key' => $key, 'name' => $name, 'branch_name' => $branchName, 'total' => 0,
            'without_deleted' => 0, 'by_status' => array_fill_keys(array_keys(self::STATUSES), 0)];
    }

    private function add(array &$row, string $status, int $count): void
    {
        $row['by_status'][$status] += $count;
        $row['total'] += $count;
        if ($status !== 'deleted') {
            $row['without_deleted'] += $count;
        }
    }
}
