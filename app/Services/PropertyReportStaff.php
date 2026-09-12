<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/** Current organizational assignments, not a historical payroll snapshot. */
class PropertyReportStaff
{
    public function attach(Collection $properties): void
    {
        $properties->loadMissing(['saleUser:id,name,branch_id,branch_group_id', 'agent:id,name,branch_id,branch_group_id', 'creator', 'saleAgents.role']);
        $sources = $properties->mapWithKeys(function ($property) {
            $seller = $property->saleUser
                ?? $property->saleAgents->first(fn ($agent) => $agent->pivot?->role === 'main')
                ?? $property->saleAgents->first();
            $property->setAttribute('report_seller', $seller ? ['id' => $seller->id, 'name' => $seller->name] : null);

            return [$property->id => $seller ?? $property->agent ?? $property->creator];
        });
        $branchIds = $sources->pluck('branch_id')->filter()->unique()->values();
        $groupIds = $sources->pluck('branch_group_id')->filter()->unique()->values();
        $staff = User::query()->select(['id', 'name', 'role_id', 'branch_id', 'branch_group_id'])
            ->with('role:id,slug')
            ->where(fn ($query) => $query->where('status', 'active')->orWhereNull('status'))
            ->where(function ($query) use ($branchIds, $groupIds) {
                $query->where(function ($rop) use ($branchIds) {
                    $rop->whereIn('branch_id', $branchIds)->whereHas('role', fn ($role) => $role->where('slug', 'rop'));
                })->orWhere(function ($mop) use ($groupIds) {
                    $mop->whereIn('branch_group_id', $groupIds)->whereHas('role', fn ($role) => $role->where('slug', 'mop'));
                });
            })->orderBy('name')->get();
        foreach ($properties as $property) {
            $source = $sources->get($property->id);
            foreach (['rop' => 'branch_id', 'mop' => 'branch_group_id'] as $role => $scope) {
                $leaders = $staff->filter(fn ($person) => $source?->{$scope} && $person->role?->slug === $role && $person->{$scope} === $source->{$scope}
                )->merge($property->saleAgents->filter(fn ($person) => $person->role?->slug === $role))->unique('id');
                $property->setAttribute('report_'.$role, $leaders->map(function ($person) use ($property) {
                    $participant = $property->saleAgents->firstWhere('id', $person->id);

                    return ['id' => $person->id, 'name' => $person->name,
                        'amount' => $participant?->pivot?->agent_commission_amount,
                        'currency' => $participant?->pivot?->agent_commission_currency,
                        'paid_at' => $participant?->pivot?->agent_paid_at];
                })->values()->all());
            }
        }
    }
}
