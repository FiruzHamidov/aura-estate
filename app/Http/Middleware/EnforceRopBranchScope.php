<?php

namespace App\Http\Middleware;

use App\Models\BranchGroup;
use App\Models\User;
use App\Support\RbacBranchScope;
use Closure;
use Illuminate\Http\Request;

class EnforceRopBranchScope
{
    public function __construct(private readonly RbacBranchScope $branchScope)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $authUser = $request->user();

        if (! $authUser || ! $this->branchScope->isRop($authUser)) {
            return $next($request);
        }

        $branchIds = $this->toArray($request->input('branch_id'));
        foreach ($branchIds as $branchId) {
            $this->branchScope->ensureSameBranchOrDeny((int) $branchId, $authUser);
        }

        $branchGroupIds = $this->toArray($request->input('branch_group_id'));
        foreach ($branchGroupIds as $branchGroupId) {
            $belongs = BranchGroup::query()
                ->whereKey((int) $branchGroupId)
                ->where('branch_id', $authUser->branch_id)
                ->exists();

            if (! $belongs) {
                $this->branchScope->denyBranchScopeViolation();
            }
        }

        $agentIds = $this->toArray($request->input('agent_id', $request->input('user_id')));
        $agentIds = array_merge(
            $agentIds,
            $this->toArray($request->input('responsible_user_id')),
            $this->toArray($request->input('responsible_agent_id'))
        );

        if (! empty($agentIds)) {
            $count = User::query()
                ->whereIn('id', array_map('intval', $agentIds))
                ->where('branch_id', $authUser->branch_id)
                ->count();

            if ($count !== count(array_unique(array_map('intval', $agentIds)))) {
                $this->branchScope->denyBranchScopeViolation();
            }
        }

        return $next($request);
    }

    private function toArray(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter($value, fn ($v) => $v !== '' && $v !== null));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }
}
