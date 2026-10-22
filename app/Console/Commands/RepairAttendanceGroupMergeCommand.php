<?php

namespace App\Console\Commands;

use App\Models\{AttendanceAuditLog, AttendanceDailySummary, AttendanceEvent, BranchGroup, User};
use App\Services\Attendance\AttendanceSummaryService;
use App\Services\GroupAccess\HistoricalUserGroup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class RepairAttendanceGroupMergeCommand extends Command
{
    protected $signature = 'attendance:repair-group-merge {audit_id* : Employee merge audit IDs}
        {--apply : Persist the reviewed repair}
        {--backup= : New absolute private JSON backup path, required with --apply}';

    protected $description = 'Repair attendance organization after explicit audited group merges, preserving earlier marks';

    public function handle(HistoricalUserGroup $history, AttendanceSummaryService $summaries): int
    {
        $ids = $this->argument('audit_id');
        Validator::make(['ids' => $ids], ['ids' => 'required|array|max:100', 'ids.*' => 'required|integer|min:1|distinct'])->validate();
        $apply = (bool) $this->option('apply');
        $backupPath = (string) $this->option('backup');
        if ($apply && (!str_starts_with($backupPath, '/') || !is_dir(dirname($backupPath)) || file_exists($backupPath))) {
            $this->error('--apply requires a new absolute --backup path in an existing private directory.');
            return self::FAILURE;
        }
        $until = CarbonImmutable::now('UTC');
        $result = DB::transaction(function () use ($ids, $apply, $backupPath, $until, $history, $summaries) {
            $audits = DB::table('group_access_audit_logs')->whereIn('id', $ids)->orderBy('subject_id')->get();
            throw_unless($audits->count() === count($ids) && $audits->unique('subject_id')->count() === count($ids),
                \InvalidArgumentException::class, 'Provide one existing merge audit per employee.');
            $users = User::query()->whereIn('id', $audits->pluck('subject_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $changes = []; $days = []; $report = []; $backup = ['until' => $until->toISOString(), 'audit_ids' => $ids, 'events' => [], 'summaries' => []];
            foreach ($audits as $audit) {
                throw_unless(($audit->subject_type === 'user' && $audit->event === 'user_group_merged')
                    || ($audit->subject_type === 'users' && $audit->event === 'group_consolidation_transfer'),
                    \InvalidArgumentException::class, 'Only employee group merge receipts are accepted.');
                $before = json_decode($audit->old_values, true, flags: JSON_THROW_ON_ERROR);
                $after = json_decode($audit->new_values, true, flags: JSON_THROW_ON_ERROR);
                $source = BranchGroup::withoutGlobalScope('active')->find($before['branch_group_id'] ?? null);
                $target = BranchGroup::withoutGlobalScope('active')->find($after['branch_group_id'] ?? null);
                $user = $users->get($audit->subject_id);
                throw_unless($user && $source && $target && $source->id !== $target->id && (int) $source->branch_id === (int) $target->branch_id,
                    \InvalidArgumentException::class, 'Missing employee or invalid same-branch merge receipt.');
                $from = CarbonImmutable::parse($audit->created_at, config('app.timezone'))->utc();
                $events = AttendanceEvent::where('user_id', $user->id)->whereBetween('occurred_at', [$from, $until])
                    ->orderBy('id')->lockForUpdate()->get();
                $count = 0;
                foreach ($events as $event) {
                    $at = CarbonImmutable::instance($event->occurred_at);
                    $next = [...$history->at($user, $at), 'role_slug' => $history->roleAt($user, $at)];
                    throw_unless($next['branch_id'] && $next['branch_group_id'] && $next['role_slug'],
                        \RuntimeException::class, 'Incomplete historical context for event '.$event->id.'; no repair applied.');
                    $original = $event->only(array_keys($next));
                    $event->fill($next);
                    if ($event->isDirty(array_keys($next))) {
                        $backup['events'][] = $event->getRawOriginal();
                        $changes[] = [$event, $original, $next, $audit->id];
                        $count++;
                    }
                    // Include already-correct marks so interrupted older repairs can
                    // rebuild a stale day too. Mixed-group days remain unassigned.
                    $date = $at->setTimezone($summaries->timezoneFor($user))->toDateString();
                    $days[$user->id][$date] = $audit->id;
                }
                $report[] = ['audit_id' => $audit->id, 'user_id' => $user->id, 'from' => $from->toISOString(),
                    'events_checked' => $events->count(), 'events_to_change' => $count, 'days_to_recompute' => count($days[$user->id] ?? [])];
            }
            foreach ($days as $userId => $dates) foreach ($dates as $date => $auditId) {
                $backup['summaries'][$userId][$date] = AttendanceDailySummary::where('user_id', $userId)->whereDate('work_date', $date)
                    ->lockForUpdate()->first()?->getRawOriginal();
            }
            $summaryChanges = 0;
            if ($apply) {
                $json = json_encode($backup, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
                $mask = umask(0077);
                try { $file = fopen($backupPath, 'x'); } finally { umask($mask); }
                throw_unless($file, \RuntimeException::class, 'Cannot create repair backup.');
                try { throw_unless(fwrite($file, $json) === strlen($json) && fflush($file), \RuntimeException::class, 'Incomplete repair backup.'); }
                finally { fclose($file); }
                foreach ($changes as [$event, $old, $new, $auditId]) {
                    $event->save();
                    $this->record($event, $old, $new, $auditId);
                }
                foreach ($days as $userId => $dates) foreach ($dates as $date => $auditId) {
                    $old = $backup['summaries'][$userId][$date] ?? [];
                    $summary = $summaries->recompute($users[$userId], $date);
                    $new = $summary->getRawOriginal();
                    unset($old['updated_at'], $new['updated_at']);
                    if ($old != $new) { $summaryChanges++; $this->record($summary, $old, $new, $auditId); }
                }
            }
            return ['mode' => $apply ? 'applied' : 'preview', 'until' => $until->toISOString(), 'employees' => $report,
                'events_to_change' => count($changes), 'summaries_changed' => $apply ? $summaryChanges : null,
                'backup' => $apply ? $backupPath : null];
        });
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }

    private function record(AttendanceEvent|AttendanceDailySummary $subject, array $before, array $after, int $auditId): void
    {
        AttendanceAuditLog::create(['action' => 'attendance.group_merge_repaired', 'auditable_type' => $subject::class,
            'auditable_id' => $subject->id, 'old_values' => $before, 'new_values' => [...$after, 'group_merge_audit_id' => $auditId],
            'user_agent' => 'attendance:repair-group-merge']);
    }
}
