<?php

namespace App\Services\Crm;

use App\Models\User;
use App\Support\DealAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DealListQuery
{
    public function __construct(private readonly DealAccess $dealAccess) {}

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:255',
            'q' => 'nullable|string|max:255',
            'pipeline_id' => 'nullable|integer|exists:crm_deal_pipelines,id',
            'pipeline_code' => 'nullable|string|max:255',
            'pipeline_type' => 'nullable|string|max:255',
            'stage_id' => 'nullable|integer|exists:crm_deal_stages,id',
            'stage_slug' => 'nullable|string|max:100',
            'client_id' => 'nullable|integer|exists:clients,id',
            'lead_id' => 'nullable|integer|exists:leads,id',
            'client_need_id' => 'nullable|integer|exists:client_needs,id',
            'primary_property_id' => 'nullable|integer|exists:properties,id',
            'source_property_status' => 'nullable',
            'source_property_status.*' => 'string|max:40',
            'source' => 'nullable|string|max:100',
            'responsible_agent_id' => 'nullable|integer|exists:users,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'overdue_activity' => 'nullable|boolean',
            'all_property_control_pipelines' => 'nullable|boolean',
            'created_from' => 'nullable|date',
            'created_to' => 'nullable|date|after_or_equal:created_from',
            'closed_from' => 'nullable|date',
            'closed_to' => 'nullable|date|after_or_equal:closed_from',
            'sort' => 'nullable|in:id,created_at,updated_at,closed_at,next_activity_at',
            'dir' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ];
    }

    public function build(Request $request, User $authUser, array $validated): Builder
    {
        $query = $this->dealAccess->visibleQuery($authUser);
        $search = trim((string) ($validated['q'] ?? $validated['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('title', 'like', '%'.$search.'%')
                    ->orWhere('source', 'like', '%'.$search.'%')
                    ->orWhereKey(ctype_digit($search) ? (int) $search : 0)
                    ->orWhereHas('client', fn (Builder $client) => $client->where('full_name', 'like', '%'.$search.'%'))
                    ->orWhereHas('lead', fn (Builder $lead) => $lead->where('full_name', 'like', '%'.$search.'%'))
                    ->orWhereHas('primaryProperty', function (Builder $property) use ($search) {
                        $property->where('title', 'like', '%'.$search.'%')
                            ->orWhere('address', 'like', '%'.$search.'%')
                            ->orWhere('owner_name', 'like', '%'.$search.'%')
                            ->orWhere('owner_phone', 'like', '%'.$search.'%')
                            ->orWhere('buyer_phone', 'like', '%'.$search.'%')
                            ->orWhere('object_key', 'like', '%'.$search.'%');

                        if (ctype_digit($search)) {
                            $property->orWhereKey((int) $search);
                        }
                    });
            });
        }

        foreach (['pipeline_id', 'stage_id', 'client_id', 'lead_id', 'client_need_id', 'responsible_agent_id', 'primary_property_id'] as $field) {
            if (! empty($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }

        if (! empty($validated['stage_slug'])) {
            $query->whereHas('stage', fn (Builder $stage) => $stage->where('slug', $validated['stage_slug']));
        }

        $statuses = collect(is_array($validated['source_property_status'] ?? null)
            ? $validated['source_property_status']
            : explode(',', (string) ($validated['source_property_status'] ?? '')))
            ->map(fn ($status) => trim((string) $status))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($statuses !== []) {
            $query->whereIn('source_property_status', $statuses);
        }

        if (! empty($validated['source'])) {
            $query->where('source', trim($validated['source']));
        }
        if (! empty($validated['pipeline_code'])) {
            $query->whereHas('pipeline', fn (Builder $pipeline) => $pipeline->where('code', trim($validated['pipeline_code'])));
        }
        if (! empty($validated['pipeline_type'])) {
            $query->whereHas('pipeline', fn (Builder $pipeline) => $pipeline->where('type', trim($validated['pipeline_type'])));
        }

        $role = $this->dealAccess->roleSlug($authUser);
        if (($this->dealAccess->isPrivilegedRole($role) || $this->dealAccess->isSecurityRole($role)) && ! empty($validated['branch_id'])) {
            $query->where('branch_id', $validated['branch_id']);
        }

        foreach (['created_from' => 'created_at', 'closed_from' => 'closed_at'] as $input => $column) {
            if (! empty($validated[$input])) {
                $query->where($column, '>=', Carbon::parse($validated[$input])->startOfDay());
            }
        }
        foreach (['created_to' => 'created_at', 'closed_to' => 'closed_at'] as $input => $column) {
            if (! empty($validated[$input])) {
                $query->where($column, '<=', Carbon::parse($validated[$input])->endOfDay());
            }
        }

        if (array_key_exists('overdue_activity', $validated) && $validated['overdue_activity'] !== null) {
            if ($request->boolean('overdue_activity')) {
                $query->whereNull('closed_at')->whereNotNull('next_activity_at')->where('next_activity_at', '<', now());
            } else {
                $query->where(function (Builder $activity) {
                    $activity->whereNotNull('closed_at')
                        ->orWhereNull('next_activity_at')
                        ->orWhere('next_activity_at', '>=', now());
                });
            }
        }

        return $query;
    }
}
