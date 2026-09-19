<?php

namespace App\Services\GroupAccess;

use App\Models\BranchGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class RopGroupAssignments
{
    public function __construct(private readonly GroupAccessAudit $audit) {}

    public function authorize(User $actor, User $rop, bool $write): void
    {
        $global = in_array($actor->role?->slug, ['admin', 'superadmin'], true);
        $director = $actor->hasRole('branch_director') && $actor->branch_id !== null
            && (int) $actor->branch_id === (int) $rop->branch_id;
        $self = ! $write && $actor->id === $rop->id && $actor->hasRole('rop');
        abort_unless($global || $director || $self, 403, 'FORBIDDEN_ACTION');
        abort_unless($rop->hasRole('rop'), 422, 'ROP_ROLE_REQUIRED');
    }

    public function payload(User $rop): array
    {
        return [
            'rop_id' => $rop->id,
            'branch_id' => $rop->branch_id,
            'version' => (int) $rop->access_scope_version,
            'groups' => $rop->supervisedGroups()->where('branch_groups.branch_id', $rop->branch_id ?? 0)
                ->orderBy('branch_groups.id')->get(['branch_groups.id', 'branch_groups.name', 'branch_groups.branch_id']),
        ];
    }

    /** Validate the complete reviewed file before changing any assignments. */
    public function import(User $actor, array $rows, bool $apply): array
    {
        abort_unless(array_is_list($rows), 422, 'INVALID_ASSIGNMENT_IMPORT');
        Validator::make(['rows' => $rows], [
            'rows' => ['array'],
            'rows.*' => ['required', 'array:rop_id,branch_group_ids,version'],
            'rows.*.rop_id' => ['required', 'integer', 'min:1', 'distinct'],
            'rows.*.version' => ['required', 'integer', 'min:0'],
            'rows.*.branch_group_ids' => ['present', 'array', 'list'],
            'rows.*.branch_group_ids.*' => ['required', 'integer', 'min:1'],
        ])->validate();

        // Dry runs execute exactly the write validation, then roll back the
        // savepoint, including versions and audit entries. Keep user -> group
        // locking consistent across the whole batch, not just each row.
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            User::query()->whereIn('id', [$actor->id, ...array_column($rows, 'rop_id')])
                ->orderBy('id')->lockForUpdate()->get();
            $groupIds = array_merge([], ...array_column($rows, 'branch_group_ids'));
            BranchGroup::query()->whereIn('id', $groupIds)->orderBy('id')->lockForUpdate()->get();
            $results = [];
            foreach ($rows as $row) {
                $result = $this->replace($actor, (int) $row['rop_id'], $row['branch_group_ids'],
                    (int) $row['version'], 'Reviewed assignment import');
                $results[] = ['rop_id' => $result['rop_id'], 'version' => $result['version']];
            }
            $apply ? DB::commit() : DB::rollBack();

            return $results;
        } catch (\Throwable $exception) {
            DB::rollBack($level);
            throw $exception;
        }
    }

    public function replace(User $actor, int $ropId, array $ids, int $version, ?string $reason = null): array
    {
        return DB::transaction(function () use ($actor, $ropId, $ids, $version, $reason) {
            // Same user locks are used by changes to role/branch and employee transfers.
            $locked = User::query()->whereIn('id', [$actor->id, $ropId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $locked->get($actor->id);
            $rop = $locked->get($ropId);
            abort_unless($actor && $rop, 404, 'NOT_FOUND');
            $this->authorize($actor, $rop, true);
            abort_unless((int) $rop->access_scope_version === $version, 409, 'ACCESS_SCOPE_VERSION_CONFLICT');

            $ids = array_map('intval', $ids);
            abort_unless(count($ids) === count(array_unique($ids)), 422, 'DUPLICATE_GROUP');
            $groups = BranchGroup::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($groups->count() === count($ids), 422, 'INVALID_GROUP');
            foreach ($groups as $group) {
                abort_unless($rop->branch_id && (int) $group->branch_id === (int) $rop->branch_id, 403, 'RBAC_GROUP_SCOPE_VIOLATION');
            }

            $before = $rop->supervisedGroups()->pluck('branch_groups.id')->map(fn ($id) => (int) $id)->all();
            $rop->supervisedGroups()->detach(array_diff($before, $ids));
            foreach (array_diff($ids, $before) as $id) {
                $rop->supervisedGroups()->attach($id, ['assigned_by' => $actor->id]);
            }
            $rop->forceFill(['access_scope_version' => $version + 1])->save();
            $this->audit->record($actor, 'rop_groups_replaced', 'user', $rop->id,
                ['group_ids' => $before, 'version' => $version],
                ['group_ids' => $ids, 'version' => $version + 1], $reason);

            return $this->payload($rop);
        });
    }
}
