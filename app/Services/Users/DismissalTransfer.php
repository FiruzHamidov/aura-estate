<?php

namespace App\Services\Users;

use App\Models\BranchGroup;
use App\Models\User;
use App\Observers\GroupOwnedRecordObserver;
use App\Services\GroupAccess\GroupAccessAudit;
use App\Services\GroupAccess\GroupRecordTransfer;
use App\Services\GroupAccess\UserOrganizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A reviewed transfer; closed history is preserved. */
final class DismissalTransfer
{
    public function __construct(
        private readonly UserOrganizationService $organization,
        private readonly GroupAccessAudit $audit,
    ) {}

    private function inventory(User $employee, bool $lock = false): array
    {
        $records = [];
        $queries = $this->organization->activeQueries($employee->id);
        foreach (GroupRecordTransfer::MODELS as $type => $class) {
            $table = (new $class)->getTable();
            $query = $queries[$table] ?? null;
            if (! $query) {
                continue;
            }
            foreach ($query->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get() as $row) {
                $records[] = ['type' => $type, 'id' => (int) $row->id, 'table' => $table,
                    'title' => $this->recordTitle($type, $row),
                    'branch_group_id' => $row->branch_group_id ?? null,
                    'branch_id' => $row->branch_id ?? (! empty($row->branch_group_id)
                        ? BranchGroup::whereKey($row->branch_group_id)->value('branch_id') : $employee->branch_id),
                    'attributes' => (array) $row];
            }
        }

        return $records;
    }

    private function recordTitle(string $type, object $row): string
    {
        $title = trim((string) ($row->title ?? $row->full_name ?? ''));
        if ($title !== '') return $title;
        if ($type !== 'properties') return '#'.$row->id;

        return implode(' · ', array_filter([
            ! empty($row->rooms) ? $row->rooms.' комн.' : null,
            $row->address ?? null,
            ! empty($row->total_area) ? $row->total_area.' м²' : null,
        ])) ?: 'Объявление #'.$row->id;
    }

    private function revision(User $employee, array $records): string
    {
        return hash('sha256', json_encode([$employee->only(['id', 'status', 'role_id', 'branch_id', 'branch_group_id', 'access_scope_version']), $records], JSON_THROW_ON_ERROR));
    }

    private function recipients(User $actor, User $employee)
    {
        return User::query()->with(['role', 'branch', 'branchGroup'])->whereKeyNot($employee->id)->where('status', User::STATUS_ACTIVE)
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['agent', 'mop']))
            ->when($actor->hasRole('branch_director'), fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->orderBy('id')->get()->reject(fn (User $user) => $user->isDeletedAccount());
    }

    private function eligible(User $actor, User $target, array $record): bool
    {
        if ($record['type'] === 'properties' && $target->branch_group_id
            && in_array($actor->role?->slug, ['admin', 'superadmin', 'branch_director'], true)
            && (! $actor->hasRole('branch_director') || (int) $actor->branch_id === (int) $target->branch_id)) {
            return true;
        }

        return (int) $target->branch_group_id === (int) $record['branch_group_id']
            && (int) $target->branch_id === (int) $record['branch_id'];
    }

    private function ensureRecordScope(User $actor, array $records): void
    {
        if ($actor->hasRole('branch_director')) {
            foreach ($records as $record) {
                abort_unless($actor->branch_id && (int) $record['branch_id'] === (int) $actor->branch_id, 403, 'FORBIDDEN_ACTION');
            }
        }
    }

    public function preview(User $actor, User $employee): array
    {
        $records = $this->inventory($employee);
        $this->ensureRecordScope($actor, $records);
        $recipients = $this->recipients($actor, $employee);
        $properties = collect($records)->where('type', 'properties')->keyBy('id');

        $loads = [];
        if (Schema::hasTable('properties')) {
            $loads = DB::table('properties')->where('moderation_status', 'approved')
                ->whereIn('agent_id', $recipients->pluck('id'))->groupBy('agent_id')
                ->selectRaw('agent_id, COUNT(*) as total')->pluck('total', 'agent_id')->all();
        }

        return ['revision' => $this->revision($employee, $records),
            'records' => array_map(function ($r) use ($actor, $recipients, $properties) {
                $parent = $r['type'] === 'tasks' && in_array($r['attributes']['related_entity_type'] ?? null, ['property', 'ad'], true)
                    ? $properties->get($r['attributes']['related_entity_id'] ?? 0) : null;
                $scope = $parent ?? $r;
                $eligible = $recipients->filter(fn ($u) => $this->eligible($actor, $u, $scope))->pluck('id')->values()->all();
                $coOwner = (int) ($r['attributes']['co_owner_user_id'] ?? 0);
                return [...array_intersect_key($r, array_flip(['type', 'id', 'title', 'branch_group_id', 'branch_id'])),
                    'eligible_user_ids' => $eligible, 'follows_property_id' => $parent['id'] ?? null,
                    'preferred_user_id' => $r['type'] === 'properties' && in_array($coOwner, $eligible, true) ? $coOwner : null];
            }, $records),
            'recipients' => $recipients->map(fn ($u) => [...$u->only(['id', 'name', 'branch_id', 'branch_group_id']),
                'branch_name' => $u->branch?->name,
                'group_name' => $u->branchGroup?->name,
                'approved_properties_count' => (int) ($loads[$u->id] ?? 0),
            ])->values()->all()];
    }

    /** Called inside the dismissal transaction after locking every participating user. */
    public function apply(User $actor, User $employee, array $plan, \Illuminate\Support\Collection $lockedUsers): array
    {
        $groupIds = [...array_column($this->inventory($employee), 'branch_group_id'), ...$lockedUsers->pluck('branch_group_id')->all()];
        BranchGroup::query()->whereIn('id', array_filter($groupIds))->orderBy('id')->lockForUpdate()->get();
        $inventory = $this->inventory($employee, true);
        $this->ensureRecordScope($actor, $inventory);
        abort_unless(hash_equals($this->revision($employee, $inventory), $plan['revision']), 409, 'DISMISSAL_VERSION_CONFLICT');
        $assignments = [];
        $destinations = [];
        foreach ($plan['records'] as $item) {
            $key = $item['type'].':'.$item['id'];
            abort_if(isset($assignments[$key]), 422, 'DUPLICATE_TRANSFER_RECORD');
            $assignments[$key] = $item['responsible_user_id'];
            $destinations[$key] = $item['destination_branch_group_id'] ?? null;
        }
        $expected = array_map(fn ($r) => $r['type'].':'.$r['id'], $inventory);
        $actual = array_keys($assignments);
        sort($expected);
        sort($actual);
        abort_unless($actual === $expected, 422, 'COMPLETE_TRANSFER_PLAN_REQUIRED');
        $targets = $lockedUsers->filter(fn (User $target) => $target->id !== $employee->id
            && $target->status === User::STATUS_ACTIVE && ! $target->isDeletedAccount()
            && in_array($target->role?->slug, ['agent', 'mop'], true));
        $counts = array_fill_keys(array_keys(GroupRecordTransfer::MODELS), 0);
        foreach ($inventory as $record) {
            if ($record['type'] === 'tasks') {
                $fresh = DB::table($record['table'])->where('id', $record['id'])->lockForUpdate()->first();
                $record['branch_group_id'] = $fresh->branch_group_id;
                $record['branch_id'] = $fresh->branch_group_id
                    ? BranchGroup::whereKey($fresh->branch_group_id)->value('branch_id') : $record['branch_id'];
            }
            $target = $targets->get($assignments[$record['type'].':'.$record['id']]);
            abort_unless($target && $this->eligible($actor, $target, $record), 422, 'INVALID_TRANSFER_TARGET');
            if ($record['type'] === 'properties'
                && ((int) $target->branch_group_id !== (int) $record['branch_group_id']
                    || (int) $target->branch_id !== (int) $record['branch_id'])) {
                abort_unless((int) ($destinations['properties:'.$record['id']] ?? 0) === (int) $target->branch_group_id,
                    422, 'TRANSFER_DESTINATION_CONFIRMATION_REQUIRED');
                $transfer = app(GroupRecordTransfer::class);
                $model = $transfer->model('properties', $record['id'], true);
                $transfer->transfer($actor, 'properties', $record['id'], [
                    'branch_group_id' => $target->branch_group_id, 'responsible_user_id' => $target->id,
                    'revision' => $transfer->revision($model), 'reason' => $plan['reason'],
                ]);
                $counts['properties']++;
                continue;
            }
            $field = GroupOwnedRecordObserver::RESPONSIBLES[$record['table']];
            $changes = [$field => $target->id, 'updated_at' => now()];
            if ($record['type'] === 'properties') {
                if (in_array((int) ($record['attributes']['co_owner_user_id'] ?? 0), [$employee->id, $target->id], true)) {
                    $changes['co_owner_user_id'] = null;
                }
                if (array_key_exists('moderation_version', $record['attributes'])) {
                    $changes['moderation_version'] = (int) $record['attributes']['moderation_version'] + 1;
                }
            }
            DB::table($record['table'])->where('id', $record['id'])->update($changes);
            if ($record['type'] === 'clients' && Schema::hasTable('client_needs')) {
                DB::table('client_needs')->where('client_id', $record['id'])->update(['responsible_agent_id' => $target->id, 'updated_at' => now()]);
            }
            $this->audit->record($actor, 'employee_dismissal_record_transferred', $record['table'], $record['id'],
                array_intersect_key($record['attributes'], $changes), [...$changes, 'dismissed_user_id' => $employee->id], $plan['reason']);
            $counts[$record['type']]++;
        }
        abort_if($this->organization->hasActiveRecords($employee->id, lock: true), 409, 'DISMISSAL_VERSION_CONFLICT');

        return $counts;
    }
}
