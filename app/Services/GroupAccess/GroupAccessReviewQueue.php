<?php

namespace App\Services\GroupAccess;

use Illuminate\Support\Facades\DB;

final class GroupAccessReviewQueue
{
    /** Publish a complete preparation scan atomically. Contains identifiers, never copied contacts. */
    public function publish(array $report): int
    {
        $items = $report['issues'];
        foreach (array_keys($report['unclassified_history']) as $table) {
            DB::table($table)->whereNull('branch_group_id')->orderBy('id')->chunkById(500, function ($rows) use ($table, &$items) {
                foreach ($rows as $row) $items[] = ['table' => $table, 'id' => $row->id, 'reason' => 'unclassified_history'];
            });
        }
        foreach ($report['unclassified_attendance_context'] as $table => $fields) {
            foreach (array_keys($fields) as $field) {
                $column = $field === 'role' ? 'role_slug' : 'schedule_snapshot';
                DB::table($table)->whereNull($column)->orderBy('id')->chunkById(500, function ($rows) use ($table, $field, &$items) {
                    foreach ($rows as $row) $items[] = ['table' => $table, 'id' => $row->id, 'reason' => 'unknown_historical_'.$field];
                });
            }
        }

        return DB::transaction(function () use ($items) {
            $now = now();
            DB::table('group_access_review_items')->whereNull('resolved_at')->update(['resolved_at' => $now]);
            foreach ($items as $item) {
                $source = DB::table($item['table'])->find($item['id']);
                if (! $source) continue;
                // An ambiguous branch belongs only in the administrator's queue.
                $groupBranch = ! empty($source->branch_group_id)
                    ? DB::table('branch_groups')->where('id', $source->branch_group_id)->value('branch_id') : null;
                $branch = $source->branch_id ?? $groupBranch;
                if ($groupBranch && $branch && (int) $groupBranch !== (int) $branch) $branch = null;
                DB::table('group_access_review_items')->updateOrInsert(
                    ['source_table' => $item['table'], 'source_id' => $item['id'], 'reason' => $item['reason']],
                    ['branch_id' => $branch, 'detected_at' => $now, 'resolved_at' => null],
                );
            }
            return DB::table('group_access_review_items')->whereNull('resolved_at')->count();
        });
    }
}
