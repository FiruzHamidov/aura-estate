<?php

namespace App\Support;

use App\Models\BranchGroup;
use App\Models\User;

class RbacBranchScope
{
    public const VIOLATION_CODE = 'RBAC_BRANCH_SCOPE_VIOLATION';
    public const DAILY_REPORT_EDIT_FORBIDDEN = 'DAILY_REPORT_EDIT_FORBIDDEN';
    public const DAILY_REPORT_SCOPE_FORBIDDEN = 'DAILY_REPORT_SCOPE_FORBIDDEN';

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

    public function isMop(?User $user): bool
    {
        return $this->roleSlug($user) === 'mop';
    }

    public function denyBranchScopeViolation(string $message = 'Forbidden'): void
    {
        abort(response()->json([
            'code' => self::VIOLATION_CODE,
            'message' => $message,
            'details' => (object) [],
            'trace_id' => request()->attributes->get('trace_id'),
        ], 403));
    }

    public function denyWithCode(string $code, string $message = 'Forbidden'): void
    {
        abort(response()->json([
            'code' => $code,
            'message' => $message,
            'details' => (object) [],
            'trace_id' => request()->attributes->get('trace_id'),
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

    public function ensureSameBranchGroupOrDeny(?int $candidateBranchGroupId, User $authUser): void
    {
        if ($candidateBranchGroupId === null) {
            return;
        }

        if ((int) $candidateBranchGroupId !== (int) $authUser->branch_group_id) {
            $this->denyBranchScopeViolation();
        }
    }

    public function ensureUserInUserBranchGroupOrDeny(?int $userId, User $authUser): void
    {
        if ($userId === null) {
            return;
        }

        $belongs = User::query()
            ->whereKey($userId)
            ->where('branch_group_id', $authUser->branch_group_id)
            ->exists();

        if (! $belongs) {
            $this->denyBranchScopeViolation();
        }
    }
}
