<?php

namespace App\Services\Crm;

use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

final class ClientListFilters
{
    public static function rules(): array
    {
        return [
            'search' => 'nullable|string',
            'full_name' => 'nullable|string',
            'phone' => 'nullable|string',
            'email' => 'nullable|string',
            'responsible_agent_id' => 'nullable|integer|exists:users,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'client_type_id' => 'nullable|integer|exists:client_types,id',
            'source_id' => 'nullable|integer|exists:client_sources,id',
            'source_ids' => 'sometimes|array',
            'source_ids.*' => ['integer', 'distinct', Rule::exists('client_sources', 'id')],
            'contact_kind' => ['nullable', Rule::in(Client::contactKinds())],
            'is_business' => 'nullable|boolean',
            'has_open_needs' => 'nullable|boolean',
            'need_status_id' => 'nullable|integer|exists:client_need_statuses,id',
            'need_type_id' => 'nullable|integer|exists:client_need_types,id',
            'repair_type_id' => 'nullable|integer|exists:repair_types,id',
            'repair_type_ids' => 'sometimes|array',
            'repair_type_ids.*' => ['integer', 'distinct', Rule::exists('repair_types', 'id')],
            'property_type_ids' => 'sometimes|array',
            'property_type_ids.*' => ['integer', 'distinct', Rule::exists('property_types', 'id')],
            'wants_mortgage' => 'nullable|boolean',
            'has_cash_on_hand' => 'nullable|boolean',
            'cash_on_hand_amount_from' => 'nullable|numeric|min:0',
            'cash_on_hand_amount_to' => 'nullable|numeric|min:0',
            'budget_total_from' => 'nullable|numeric|min:0',
            'budget_total_to' => 'nullable|numeric|min:0',
            'budget_cash_from' => 'nullable|numeric|min:0',
            'budget_cash_to' => 'nullable|numeric|min:0',
            'budget_mortgage_from' => 'nullable|numeric|min:0',
            'budget_mortgage_to' => 'nullable|numeric|min:0',
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'status_id' => 'nullable|integer|exists:client_need_statuses,id',
        ];
    }

    public static function apply(Builder $query, array $validated): void
    {
        $effectiveRepairTypeIds = [];
        if (array_key_exists('repair_type_ids', $validated)) {
            $effectiveRepairTypeIds = $validated['repair_type_ids'];
        } elseif (!empty($validated['repair_type_id'])) {
            $effectiveRepairTypeIds = [$validated['repair_type_id']];
        }

        $effectiveSourceIds = [];
        if (array_key_exists('source_ids', $validated)) {
            $effectiveSourceIds = $validated['source_ids'];
        } elseif (!empty($validated['source_id'])) {
            $effectiveSourceIds = [$validated['source_id']];
        }

        if (!empty($validated['search'])) {
            $term = trim($validated['search']);
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('full_name', 'like', '%' . $term . '%')
                    ->orWhere('phone', 'like', '%' . $term . '%')
                    ->orWhere('email', 'like', '%' . $term . '%');
            });
        }

        if (!empty($validated['full_name'])) {
            $query->where('full_name', 'like', '%' . trim($validated['full_name']) . '%');
        }

        if (!empty($validated['phone'])) {
            $query->where('phone', 'like', '%' . trim($validated['phone']) . '%');
        }

        if (!empty($validated['email'])) {
            $query->where('email', 'like', '%' . trim($validated['email']) . '%');
        }

        if (!empty($validated['responsible_agent_id'])) {
            $query->where('responsible_agent_id', $validated['responsible_agent_id']);
        }

        if (!empty($validated['branch_id'])) {
            $query->where('branch_id', $validated['branch_id']);
        }

        if (!empty($validated['branch_group_id'])) {
            $query->where('branch_group_id', $validated['branch_group_id']);
        }

        if (!empty($validated['client_type_id'])) {
            $query->where('client_type_id', $validated['client_type_id']);
        }

        if (!empty($effectiveSourceIds)) {
            $query->whereIn('source_id', $effectiveSourceIds);
        }

        if (!empty($validated['contact_kind'])) {
            $query->whereIn('contact_kind', Client::kindsMatchingFilter($validated['contact_kind']));
        }

        if (array_key_exists('is_business', $validated) && $validated['is_business'] !== null) {
            $query->whereHas('type', fn ($builder) => $builder->where('is_business', $validated['is_business']));
        }

        if (array_key_exists('has_open_needs', $validated) && $validated['has_open_needs'] !== null) {
            if ((bool) $validated['has_open_needs']) {
                $query->whereHas('openNeeds');
            } else {
                $query->whereDoesntHave('openNeeds');
            }
        }

        $hasNeedFilters =
            !empty($validated['need_status_id'])
            || !empty($validated['need_type_id'])
            || !empty($effectiveRepairTypeIds)
            || !empty($validated['property_type_ids'])
            || (array_key_exists('wants_mortgage', $validated) && $validated['wants_mortgage'] !== null)
            || (array_key_exists('has_cash_on_hand', $validated) && $validated['has_cash_on_hand'] !== null)
            || (array_key_exists('cash_on_hand_amount_from', $validated) && $validated['cash_on_hand_amount_from'] !== null)
            || (array_key_exists('cash_on_hand_amount_to', $validated) && $validated['cash_on_hand_amount_to'] !== null)
            || (array_key_exists('budget_total_from', $validated) && $validated['budget_total_from'] !== null)
            || (array_key_exists('budget_total_to', $validated) && $validated['budget_total_to'] !== null)
            || (array_key_exists('budget_cash_from', $validated) && $validated['budget_cash_from'] !== null)
            || (array_key_exists('budget_cash_to', $validated) && $validated['budget_cash_to'] !== null)
            || (array_key_exists('budget_mortgage_from', $validated) && $validated['budget_mortgage_from'] !== null)
            || (array_key_exists('budget_mortgage_to', $validated) && $validated['budget_mortgage_to'] !== null);

        if ($hasNeedFilters) {
            $query->whereHas('needs', function ($builder) use ($validated, $effectiveRepairTypeIds) {
                if (!empty($validated['need_status_id'])) {
                    $builder->where('status_id', $validated['need_status_id']);
                }

                if (!empty($validated['need_type_id'])) {
                    $builder->where('type_id', $validated['need_type_id']);
                }

                if (!empty($effectiveRepairTypeIds)) {
                    $builder->whereIn('repair_type_id', $effectiveRepairTypeIds);
                }

                if (!empty($validated['property_type_ids'])) {
                    $propertyTypeIds = $validated['property_type_ids'];
                    $hasPropertyTypePivotTable = Schema::hasTable('client_need_property_type');

                    $builder->where(function ($needBuilder) use ($propertyTypeIds, $hasPropertyTypePivotTable) {
                        $needBuilder->whereHas(
                            'propertyType',
                            fn ($propertyTypeBuilder) => $propertyTypeBuilder->whereIn('property_types.id', $propertyTypeIds)
                        );

                        if ($hasPropertyTypePivotTable) {
                            $needBuilder->orWhereHas(
                                'propertyTypes',
                                fn ($propertyTypesBuilder) => $propertyTypesBuilder->whereIn('property_types.id', $propertyTypeIds)
                            );
                        }
                    });
                }

                if (array_key_exists('wants_mortgage', $validated) && $validated['wants_mortgage'] !== null) {
                    $builder->where('wants_mortgage', (bool) $validated['wants_mortgage']);
                }

                if (array_key_exists('has_cash_on_hand', $validated) && $validated['has_cash_on_hand'] !== null) {
                    $builder->where('has_cash_on_hand', (bool) $validated['has_cash_on_hand']);
                }

                if (array_key_exists('cash_on_hand_amount_from', $validated) && $validated['cash_on_hand_amount_from'] !== null) {
                    $builder->where('cash_on_hand_amount', '>=', $validated['cash_on_hand_amount_from']);
                }

                if (array_key_exists('cash_on_hand_amount_to', $validated) && $validated['cash_on_hand_amount_to'] !== null) {
                    $builder->where('cash_on_hand_amount', '<=', $validated['cash_on_hand_amount_to']);
                }

                if (array_key_exists('budget_total_from', $validated) && $validated['budget_total_from'] !== null) {
                    $builder->where('budget_total', '>=', $validated['budget_total_from']);
                }

                if (array_key_exists('budget_total_to', $validated) && $validated['budget_total_to'] !== null) {
                    $builder->where('budget_total', '<=', $validated['budget_total_to']);
                }

                if (array_key_exists('budget_cash_from', $validated) && $validated['budget_cash_from'] !== null) {
                    $builder->where('budget_cash', '>=', $validated['budget_cash_from']);
                }

                if (array_key_exists('budget_cash_to', $validated) && $validated['budget_cash_to'] !== null) {
                    $builder->where('budget_cash', '<=', $validated['budget_cash_to']);
                }

                if (array_key_exists('budget_mortgage_from', $validated) && $validated['budget_mortgage_from'] !== null) {
                    $builder->where('budget_mortgage', '>=', $validated['budget_mortgage_from']);
                }

                if (array_key_exists('budget_mortgage_to', $validated) && $validated['budget_mortgage_to'] !== null) {
                    $builder->where('budget_mortgage', '<=', $validated['budget_mortgage_to']);
                }
            });
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (!empty($validated['status_id']) && Schema::hasColumn('clients', 'status_id')) {
            $query->where('status_id', (int) $validated['status_id']);
        }
    }
}
