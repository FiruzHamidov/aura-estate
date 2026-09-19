<?php

namespace App\Services;

use App\Models\KpiRopPlan;
use App\Models\User;
use App\Support\KpiPlanScopePolicy;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use App\Support\RopGroupAccess;
use App\Services\GroupAccess\GroupRecordWriteLock;

class KpiRopPlanService
{
    private const METRIC_WHITELIST = ['objects', 'shows', 'ads', 'calls', 'sales'];

    public function __construct(private readonly KpiPlanScopePolicy $scopePolicy, private readonly \App\Services\Crm\AuditLogger $auditLogger)
    {
    }

    public function list(User $actor, array $filters): LengthAwarePaginator
    {
        if (! $actor->hasRole('rop') || isset($filters['branch_group_id'])) $this->scopePolicy->ensureCanReadCommonPlan($actor, $filters);

        $query = KpiRopPlan::query()->orderByDesc('id');
        if ($actor->hasRole('rop')) app(RopGroupAccess::class)->scope($query, $actor, 'kpi_rop_plans.branch_group_id', 'kpi_rop_plans.branch_id');
        if (! empty($filters['month'])) {
            $query->where('month', (string) $filters['month']);
        }
        if (! empty($filters['role'])) {
            $query->where('role_slug', (string) $filters['role']);
        }
        if (array_key_exists('branch_id', $filters)) {
            $query->where('branch_id', $filters['branch_id']);
        }
        if (array_key_exists('branch_group_id', $filters)) {
            $query->where('branch_group_id', $filters['branch_group_id']);
        }

        return $query->paginate((int) ($filters['per_page'] ?? 25), ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->through(fn (KpiRopPlan $plan) => $this->serialize($plan));
    }

    public function get(User $actor, int $id): ?array
    {
        $plan = KpiRopPlan::query()->find($id);
        if (! $plan) {
            return null;
        }

        $this->scopePolicy->ensureCanReadCommonPlan($actor, [
            'role' => $plan->role_slug,
            'branch_id' => $plan->branch_id,
            'branch_group_id' => $plan->branch_group_id,
        ]);

        return $this->serialize($plan);
    }

    public function create(User $actor, array $payload): array
    {
        return $this->createPlan($actor, $payload);
    }

    private function createPlan(User $actor, array $payload, ?int $sourceId = null): array
    {
        return DB::transaction(function () use ($actor, $payload, $sourceId) {
            [$actor] = app(GroupRecordWriteLock::class)->acquire($actor, null, null, $payload['branch_group_id'] ?? null);
            if ($actor->hasRole('rop')) {
                $payload['branch_group_id'] = app(RopGroupAccess::class)->creationGroup($actor, $payload['branch_group_id'] ?? null);
                abort_if(isset($payload['branch_id']) && (int) $payload['branch_id'] !== (int) $actor->branch_id, 403, 'RBAC_GROUP_SCOPE_VIOLATION');
                $payload['branch_id'] = $actor->branch_id;
                \App\Models\BranchGroup::whereKey($payload['branch_group_id'])->lockForUpdate()->firstOrFail();
            }
            $this->scopePolicy->ensureCanManageCommonPlan($actor, $payload);
            $this->assertNoPeriodConflict((string) $payload['month'], (string) $payload['role'], $payload['branch_id'] ?? null, $payload['branch_group_id'] ?? null, null);

            $plan = KpiRopPlan::query()->create([
                'role_slug' => (string) $payload['role'],
                'branch_id' => $payload['branch_id'] ?? null,
                'branch_group_id' => $payload['branch_group_id'] ?? null,
                'month' => (string) $payload['month'],
                'items' => $this->sanitizeItems((array) $payload['items']),
                'created_by' => (int) $actor->id,
                'updated_by' => (int) $actor->id,
            ]);

            $this->auditLogger->log($plan, $actor, $sourceId === null ? 'kpi_rop_plan_created' : 'kpi_rop_plan_copied', [], $plan->attributesToArray(),
                $sourceId === null ? 'KPI ROP plan created' : 'KPI ROP plan copied',
                ['source_id' => $sourceId, 'branch_group_id' => $plan->branch_group_id, 'trace_id' => request()->attributes->get('trace_id')]);

            return $this->serialize($plan);
        });
    }

    public function update(User $actor, int $id, array $payload): ?array
    {
        $plan = KpiRopPlan::query()->find($id);
        if (! $plan) {
            return null;
        }

        return DB::transaction(function () use ($actor, $plan, $payload) {
            [$actor, $plan] = app(GroupRecordWriteLock::class)->acquire($actor, $plan, null, $payload['branch_group_id'] ?? null);
            $this->scopePolicy->ensureCanManageCommonPlan($actor, ['role' => $plan->role_slug, 'branch_id' => $plan->branch_id, 'branch_group_id' => $plan->branch_group_id]);
            if ($actor->hasRole('rop')) {
                foreach (['branch_id', 'branch_group_id'] as $field) {
                    abort_if(array_key_exists($field, $payload) && (string) $payload[$field] !== (string) $plan->{$field}, 422, 'GROUP_TRANSFER_REQUIRED');
                }
            }
            $next = [
                'role' => (string) ($payload['role'] ?? $plan->role_slug),
                'branch_id' => array_key_exists('branch_id', $payload) ? $payload['branch_id'] : $plan->branch_id,
                'branch_group_id' => array_key_exists('branch_group_id', $payload) ? $payload['branch_group_id'] : $plan->branch_group_id,
                'month' => (string) ($payload['month'] ?? $plan->month),
            ];

            $this->scopePolicy->ensureCanManageCommonPlan($actor, $next);
            $this->assertNoPeriodConflict($next['month'], $next['role'], $next['branch_id'], $next['branch_group_id'], $plan->id);

            $before = $plan->attributesToArray();
            $plan->fill([
                'role_slug' => $next['role'],
                'branch_id' => $next['branch_id'],
                'branch_group_id' => $next['branch_group_id'],
                'month' => $next['month'],
                'updated_by' => (int) $actor->id,
            ]);

            if (array_key_exists('items', $payload)) {
                $plan->items = $this->sanitizeItems((array) $payload['items']);
            }

            $changed = $plan->isDirty();
            $plan->save();
            if ($changed) $this->auditLogger->log($plan, $actor, 'kpi_rop_plan_updated', $before, $plan->attributesToArray(),
                'KPI ROP plan updated', ['branch_group_id' => $plan->branch_group_id, 'trace_id' => request()->attributes->get('trace_id')]);

            return $this->serialize($plan);
        });
    }

    public function copy(User $actor, int $id, array $overrides): ?array
    {
        $source = KpiRopPlan::query()->find($id);
        if (! $source) {
            return null;
        }

        return DB::transaction(function () use ($actor, $source, $overrides) {
            [$actor, $source] = app(GroupRecordWriteLock::class)->acquire($actor, $source, null, $overrides['branch_group_id'] ?? null);
            $this->scopePolicy->ensureCanReadCommonPlan($actor, [
                'role' => $source->role_slug,
                'branch_id' => $source->branch_id,
                'branch_group_id' => $source->branch_group_id,
            ]);

            $payload = [
                'role' => (string) ($overrides['role'] ?? $source->role_slug),
                'branch_id' => array_key_exists('branch_id', $overrides) ? $overrides['branch_id'] : $source->branch_id,
                'branch_group_id' => array_key_exists('branch_group_id', $overrides) ? $overrides['branch_group_id'] : $source->branch_group_id,
                'month' => (string) ($overrides['month'] ?? $source->month),
                'items' => array_key_exists('items', $overrides) ? (array) $overrides['items'] : (array) $source->items,
            ];

            return $this->createPlan($actor, $payload, (int) $source->id);
        });
    }

    private function assertNoPeriodConflict(string $month, string $role, mixed $branchId, mixed $branchGroupId, ?int $exceptId): void
    {
        $query = KpiRopPlan::query()
            ->where('month', $month)
            ->where('role_slug', $role)
            ->where('branch_id', $branchId)
            ->where('branch_group_id', $branchGroupId);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->lockForUpdate()->get(['id'])->isNotEmpty()) {
            throw new \DomainException('Plan period conflicts with an existing ROP KPI plan interval.');
        }
    }

    private function sanitizeItems(array $items): array
    {
        return collect($items)->map(function (array $item) {
            $metricKey = (string) ($item['metric_key'] ?? $item['metric'] ?? '');

            if (! in_array($metricKey, self::METRIC_WHITELIST, true)) {
                return null;
            }

            return [
                'metric_key' => $metricKey,
                'metric' => $metricKey,
                'plan_value' => (float) ($item['plan_value'] ?? $item['monthly_plan'] ?? 0),
                'monthly_plan' => (float) ($item['plan_value'] ?? $item['monthly_plan'] ?? 0),
                'weight' => (float) ($item['weight'] ?? 0),
                'comment' => (string) ($item['comment'] ?? ''),
            ];
        })->filter()->values()->all();
    }

    private function serialize(KpiRopPlan $plan): array
    {
        return [
            'id' => (int) $plan->id,
            'role' => (string) $plan->role_slug,
            'branch_id' => $plan->branch_id !== null ? (int) $plan->branch_id : null,
            'branch_group_id' => $plan->branch_group_id !== null ? (int) $plan->branch_group_id : null,
            'month' => (string) $plan->month,
            'items' => collect((array) $plan->items)
                ->filter(fn (array $item) => in_array((string) ($item['metric_key'] ?? ''), self::METRIC_WHITELIST, true))
                ->values()
                ->all(),
            'source' => 'rop_plan',
            'meta' => [
                'exists' => true,
                'source' => 'rop_plan',
                'storage_unit' => 'monthly',
            ],
            'created_at' => optional($plan->created_at)?->toISOString(),
            'updated_at' => optional($plan->updated_at)?->toISOString(),
            'updated_by' => $plan->updated_by !== null ? (int) $plan->updated_by : null,
        ];
    }
}
