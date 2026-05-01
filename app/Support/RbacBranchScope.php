<?php

namespace App\Support;

use App\Models\BranchGroup;
use App\Models\User;

class RbacBranchScope
{
    public const VIOLATION_CODE = 'RBAC_BRANCH_SCOPE_VIOLATION';

    public function roleSlug(?User $user): ?string
    {
        $user?->loadMissing('role');

        return $user?->role?->slug;
    }

    public function isRop(?User $user): bool
    {
        return $this->roleSlug($user) === 'rop';
    }

    public function isBranchScopedManager(?User $user): bool
    {
        return in_array($this->roleSlug($user), ['rop', 'branch_director'], true);
    }

    public function denyBranchScopeViolation(string $message = 'Forbidden'): void
    {
        abort(response()->json([
            'message' => $message,
            'code' => self::VIOLATION_CODE,
        ], 403));
    }

    public function ensureSameBranchOrDeny(?int $candidateBranchId, User $authUser): void
    {
        if ($candidateBranchId === null) {
            return;
        }

        if ((int) $candidateBranchId !== (int) $authUser->branch_id) {
            $this->denyBranchScopeViolation();
        }
    }

    public function ensureBranchGroupInUserBranchOrDeny(?int $branchGroupId, User $authUser): void
    {
        if ($branchGroupId === null) {
            return;
        }

        $belongs = BranchGroup::query()
            ->whereKey($branchGroupId)
            ->where('branch_id', $authUser->branch_id)
            ->exists();

        if (! $belongs) {
            $this->denyBranchScopeViolation();
        }
    }

    public function ensureUserInUserBranchOrDeny(?int $userId, User $authUser): void
    {
        if ($userId === null) {
            return;
        }

        $belongs = User::query()
            ->whereKey($userId)
            ->where('branch_id', $authUser->branch_id)
            ->exists();

        if (! $belongs) {
            $this->denyBranchScopeViolation();
        }
    }
}
