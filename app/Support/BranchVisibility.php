<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Presentation filtering only: hiding a branch never deletes its records. */
final class BranchVisibility
{
    private array $columns = [];

    private static function hasColumn(string $table, string $column): bool
    {
        if (app()->runningUnitTests()) return Schema::hasColumn($table, $column);
        $instance = app(self::class);
        $instance->columns[$table] ??= Schema::getColumnListing($table);
        return in_array($column, $instance->columns[$table], true);
    }

    public static function apply($query, string $column): void
    {
        if (!self::hasColumn('branches', 'is_hidden')) return;
        $query->where(fn ($q) => $q->whereNull($column)->orWhereNotIn($column,
            DB::table('branches')->where('is_hidden', true)->select('id')));
    }

    public static function records($query, ?string $table = null): void
    {
        if (!self::hasColumn('branches', 'is_hidden')) return;
        $table ??= $query instanceof \Illuminate\Database\Eloquent\Builder ? $query->getModel()->getTable() : $query->from;
        if (self::hasColumn($table, 'branch_id')) self::apply($query, $table.'.branch_id');
        if (self::hasColumn($table, 'branch_group_id')) {
            $query->where(fn ($q) => $q->whereNull($table.'.branch_group_id')->orWhereNotIn($table.'.branch_group_id',
                DB::table('branch_groups')->whereIn('branch_id', DB::table('branches')->where('is_hidden', true)->select('id'))->select('id')));
        }
        $owner = match ($table) {
            'daily_reports', 'attendance_events', 'attendance_daily_summaries' => 'user_id',
            'bookings', 'properties' => 'agent_id',
            'crm_tasks' => 'assignee_id',
            default => null,
        };
        if ($owner && self::hasColumn($table, $owner)) {
            $query->where(function ($q) use ($table, $owner) {
                // Explicit record ownership takes precedence over today's employee location.
                if (self::hasColumn($table, 'branch_id')) $q->whereNotNull($table.'.branch_id');
                if (self::hasColumn($table, 'branch_group_id')) $q->orWhereNotNull($table.'.branch_group_id');
                $q->orWhereNull($table.'.'.$owner)->orWhereNotIn($table.'.'.$owner,
                    DB::table('users')->whereIn('branch_id', DB::table('branches')->where('is_hidden', true)->select('id'))->select('id'));
            });
        }
    }
}
