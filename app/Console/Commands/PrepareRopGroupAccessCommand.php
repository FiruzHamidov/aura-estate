<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Observers\GroupOwnedRecordObserver;
use App\Services\GroupAccess\GroupAccessAudit;
use App\Services\GroupAccess\RopGroupAssignments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PrepareRopGroupAccessCommand extends Command
{
    protected $signature = 'rop-groups:prepare {--apply : Apply unambiguous ownership backfill} {--assignments= : JSON file with rop_id, branch_group_ids, version} {--actor= : Administrator ID for assignment import} {--queue-review : Publish unresolved records for administrators/directors} {--check : Fail unless assignments and ownership preparation are ready} {--allow-unassigned-rops : Permit release with unassigned ROPs kept without working-data access}';

    protected $description = 'Audit ROP group readiness; explicitly backfill ownership and import reviewed assignments.';

    public function handle(GroupAccessAudit $audit, RopGroupAssignments $assignments): int
    {
        $apply = (bool) $this->option('apply');
        $report = ['applied' => $apply, 'backfilled' => 0, 'candidates' => 0, 'issues' => [], 'rops_without_groups' => [], 'assignments' => []];
        // Reject an invalid assignment file before any ownership backfill.
        if ($path = $this->option('assignments')) {
            $rows = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            abort_unless(is_array($rows), 422, 'INVALID_ASSIGNMENT_IMPORT');
            $actor = User::query()->findOrFail((int) $this->option('actor'));
            $report['assignments'] = $assignments->import($actor, $rows, $apply);
        }
        foreach (GroupOwnedRecordObserver::RESPONSIBLES as $table => $responsibleField) {
            if (! Schema::hasColumn($table, 'branch_group_id')) {
                continue;
            }
            DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($table, $responsibleField, $apply, $audit, &$report) {
                foreach ($rows as $row) {
                    $groupId = $row->branch_group_id;
                    $parentSources = [];
                    $creator = null;
                    $responsible = ! empty($row->{$responsibleField}) ? DB::table('users')->find($row->{$responsibleField}) : null;
                    $isControl = $table === 'crm_deals' && ($row->control_kind ?? null) === 'security_property_closure';
                    $inheritedGroup = null;
                    if ($isControl) {
                        $inheritedGroup = DB::table('properties')->where('id', $row->primary_property_id ?? null)->value('branch_group_id');
                    } elseif ($table === 'crm_tasks' && (! empty($row->related_entity_type) || ! empty($row->related_entity_id))) {
                        $task = (new \App\Models\CrmTask)->setRawAttributes((array) $row);
                        try {
                            $inheritedGroup = app(\App\Services\GroupAccess\TaskGroupOwnership::class)->parent($task)?->branch_group_id;
                        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                            $report['issues'][] = ['table' => $table, 'id' => $row->id, 'reason' => 'invalid_parent'];
                            continue;
                        }
                    }
                    $inherits = $isControl || ($table === 'crm_tasks' && (! empty($row->related_entity_type) || ! empty($row->related_entity_id)));
                    if ($inherits) {
                        if (! $inheritedGroup || ($groupId && (int) $groupId !== (int) $inheritedGroup)) {
                            $report['issues'][] = ['table' => $table, 'id' => $row->id, 'reason' => $inheritedGroup ? 'parent_group_conflict' : 'unclassified_parent'];
                            continue;
                        }
                        $groupId ??= $inheritedGroup;
                    }
                    if (! $groupId) {
                        if ($responsible) {
                            $groupId = $responsible->branch_group_id;
                        } else {
                            $parents = [];
                            foreach (['client_id' => 'clients', 'lead_id' => 'leads', 'primary_property_id' => 'properties', 'property_id' => 'properties'] as $field => $parentTable) {
                                if (! empty($row->{$field}) && Schema::hasColumn($parentTable, 'branch_group_id')) {
                                    $candidate = DB::table($parentTable)->where('id', $row->{$field})->value('branch_group_id');
                                    $parentSources[$parentTable.':'.$row->{$field}] = ['table' => $parentTable, 'id' => $row->{$field}, 'group' => $candidate];
                                    if ($candidate) $parents[] = (int) $candidate;
                                }
                            }
                            $parents = array_unique($parents);
                            if (count($parents) > 1) {
                                $report['issues'][] = ['table' => $table, 'id' => $row->id, 'reason' => 'ambiguous_parent_groups'];
                                continue;
                            }
                            $creatorId = $row->created_by ?? $row->creator_id ?? null;
                            $creator = ! $parents && $creatorId ? DB::table('users')->find($creatorId) : null;
                            $groupId = $parents ? reset($parents) : $creator?->branch_group_id;
                        }
                    }
                    $group = $groupId ? DB::table('branch_groups')->find($groupId) : null;
                    if (! $group || (! empty($row->branch_id) && (int) $row->branch_id !== (int) $group->branch_id)
                        || (! $isControl && $responsible && (int) $responsible->branch_id !== (int) $group->branch_id)) {
                        $report['issues'][] = ['table' => $table, 'id' => $row->id, 'reason' => $group ? 'branch_conflict' : 'unclassified'];
                        continue;
                    }
                    if (! $isControl && $responsible && (int) $responsible->branch_group_id !== (int) $groupId) {
                        $report['issues'][] = ['table' => $table, 'id' => $row->id, 'reason' => 'responsible_group_conflict'];
                        continue;
                    }
                    $changes = [];
                    if (! $row->branch_group_id) $changes['branch_group_id'] = $groupId;
                    if (property_exists($row, 'branch_id') && ! $row->branch_id) $changes['branch_id'] = $group->branch_id;
                    if ($changes === []) continue;
                    $report['candidates']++;
                    if ($apply) {
                        DB::transaction(function () use ($table, $row, $changes, $audit, $responsible, $group, $inherits, $isControl, $inheritedGroup, $parentSources, $creator, &$report) {
                            $users = array_filter([$responsible?->id, $row->created_by ?? $row->creator_id ?? null]);
                            $lockedUsers = DB::table('users')->whereIn('id', $users)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                            $lockedGroup = DB::table('branch_groups')->where('id', $group->id)->lockForUpdate()->first();
                            $parentGroup = $inheritedGroup;
                            if ($inherits) {
                                $parentGroup = $isControl
                                    ? DB::table('properties')->where('id', $row->primary_property_id)->lockForUpdate()->value('branch_group_id')
                                    : app(\App\Services\GroupAccess\TaskGroupOwnership::class)->parent((new \App\Models\CrmTask)->setRawAttributes((array) $row), true)?->branch_group_id;
                            }
                            ksort($parentSources);
                            foreach ($parentSources as $source) {
                                $sourceGroup = DB::table($source['table'])->where('id', $source['id'])->lockForUpdate()->value('branch_group_id');
                                if ((string) $sourceGroup !== (string) $source['group']) {
                                    $report['issues'][] = ['table' => $table, 'id' => $row->id, 'reason' => 'concurrent_change'];
                                    return;
                                }
                            }
                            $current = DB::table($table)->where('id', $row->id)->lockForUpdate()->first();
                            // Refuse a stale audit candidate if someone edited the record during the scan.
                            if ((array) $current !== (array) $row || ! $lockedGroup || (int) $lockedGroup->branch_id !== (int) $group->branch_id
                                || ($responsible && (array) $lockedUsers->get($responsible->id) !== (array) $responsible)
                                || ($creator && (array) $lockedUsers->get($creator->id) !== (array) $creator)
                                || ($inherits && (int) $parentGroup !== (int) $inheritedGroup)) {
                                $report['issues'][] = ['table' => $table, 'id' => $row->id, 'reason' => 'concurrent_change'];
                                return;
                            }
                            DB::table($table)->where('id', $row->id)->update($changes);
                            $audit->record(null, 'ownership_backfilled', $table, $row->id,
                                array_intersect_key((array) $row, $changes), $changes, 'Reviewed ownership preparation');
                            $report['backfilled']++;
                        });
                    }
                }
            });
        }

        $report['rops_without_groups'] = User::query()->whereHas('role', fn ($q) => $q->where('slug', 'rop'))
            ->whereDoesntHave('supervisedGroups', fn ($q) => $q->whereColumn('branch_groups.branch_id', 'users.branch_id'))
            ->pluck('id')->all();
        $report['rop_assignments'] = DB::table('users as u')->join('roles as r', 'r.id', '=', 'u.role_id')
            ->leftJoin('rop_branch_groups as a', 'a.rop_id', '=', 'u.id')
            ->leftJoin('branch_groups as g', 'g.id', '=', 'a.branch_group_id')
            ->where('r.slug', 'rop')->orderBy('u.id')->orderBy('g.id')
            ->get(['u.id as rop_id', 'u.branch_id as rop_branch_id', 'g.id as branch_group_id', 'g.branch_id as group_branch_id'])
            ->map(fn ($row) => (array) $row + ['valid' => $row->branch_group_id !== null && (int) $row->rop_branch_id === (int) $row->group_branch_id])
            ->all();
        $report['unclassified_history'] = [];
        foreach (['daily_reports', 'attendance_daily_summaries', 'attendance_events', 'attendance_leaves', 'attendance_duties', 'selections', 'reels', 'kpi_early_risk_alerts', 'kpi_quality_issues', 'kpi_acceptance_runs', 'kpi_adjustment_logs'] as $table) {
            if (Schema::hasColumn($table, 'branch_group_id')) {
                $report['unclassified_history'][$table] = DB::table($table)->whereNull('branch_group_id')->count();
            }
        }
        $report['cross_group_links'] = [];
        $report['unclassified_attendance_context'] = [];
        foreach (['attendance_events', 'attendance_daily_summaries'] as $table) {
            if (Schema::hasColumn($table, 'role_slug')) {
                $report['unclassified_attendance_context'][$table]['role'] = DB::table($table)->whereNull('role_slug')->count();
            }
        }
        if (Schema::hasColumn('attendance_daily_summaries', 'schedule_snapshot')) {
            $report['unclassified_attendance_context']['attendance_daily_summaries']['schedule'] = DB::table('attendance_daily_summaries')->whereNull('schedule_snapshot')->count();
        }
        foreach (array_keys(GroupOwnedRecordObserver::RESPONSIBLES) as $table) {
            if (! Schema::hasColumn($table, 'branch_group_id')) continue;
            foreach (['client_id' => 'clients', 'lead_id' => 'leads', 'primary_property_id' => 'properties', 'property_id' => 'properties'] as $field => $parent) {
                if (! Schema::hasColumn($table, $field) || ! Schema::hasColumn($parent, 'branch_group_id')) continue;
                DB::table($table.' as child')->join($parent.' as parent', 'parent.id', '=', 'child.'.$field)
                    ->whereNotNull('child.branch_group_id')->whereNotNull('parent.branch_group_id')
                    ->whereColumn('child.branch_group_id', '<>', 'parent.branch_group_id')
                    ->select(['child.id', 'child.branch_group_id', 'parent.id as parent_id', 'parent.branch_group_id as parent_group_id'])
                    ->orderBy('child.id')->chunkById(500, function ($rows) use (&$report, $table, $field, $parent) {
                        foreach ($rows as $row) $report['cross_group_links'][] = ['table' => $table, 'field' => $field, 'parent_table' => $parent] + (array) $row;
                    }, 'child.id', 'id');
            }
        }
        $report['review_queue_count'] = $this->option('queue-review')
            ? app(\App\Services\GroupAccess\GroupAccessReviewQueue::class)->publish($report) : null;
        $activeMissing = User::query()->whereIn('id', $report['rops_without_groups'])->where('status', 'active')->count();
        $unresolved = count($report['issues']) + array_sum($report['unclassified_history']);
        foreach ($report['unclassified_attendance_context'] as $fields) $unresolved += array_sum($fields);
        $report['unassigned_rops_allowed'] = (bool) $this->option('allow-unassigned-rops');
        $report['ready'] = ($activeMissing === 0 || $report['unassigned_rops_allowed']) && ($apply || $report['candidates'] === 0)
            && ($unresolved === 0 || $this->option('queue-review'));
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $this->option('check') && ! $report['ready'] ? self::FAILURE : self::SUCCESS;
    }
}
