<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesAttributionService
{
    /**
     * @param Collection<int, object> $properties
     * @param list<string> $splitStatuses
     * @return array<int, array<int, float>> property_id => [user_id => credit]
     */
    public function creditsByProperty(Collection $properties, array $splitStatuses = ['sold', 'rented']): array
    {
        if ($properties->isEmpty()) {
            return [];
        }

        $propertyIds = $properties
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        if ($propertyIds === []) {
            return [];
        }

        $creditsByProperty = [];

        foreach ($properties as $property) {
            $propertyId = (int) ($property->id ?? 0);
            if ($propertyId <= 0) {
                continue;
            }

            $primarySellerId = $this->primarySellerId($property);
            if ($primarySellerId > 0) {
                $creditsByProperty[$propertyId][$primarySellerId] = 1.0;
            }
        }

        return $creditsByProperty;
    }

    /**
     * @param list<int> $propertyIds
     * @return array<int, list<int>> property_id => participant ids
     */
    public function participantsByProperty(array $propertyIds): array
    {
        $propertyIds = array_values(array_unique(array_map('intval', $propertyIds)));
        if ($propertyIds === [] || ! Schema::hasTable('property_agent_sales')) {
            return [];
        }

        return DB::table('property_agent_sales')
            ->select(['property_id', 'agent_id'])
            ->whereIn('property_id', $propertyIds)
            ->whereNotNull('agent_id')
            ->get()
            ->groupBy('property_id')
            ->map(function (Collection $rows) {
                return $rows
                    ->pluck('agent_id')
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn (int $id) => $id > 0)
                    ->unique()
                    ->values()
                    ->all();
            })
            ->toArray();
    }

    public function primarySellerId(object $property): int
    {
        $saleAgentId = (int) ($property->sale_agent_id ?? 0);
        if ($saleAgentId > 0) {
            return $saleAgentId;
        }

        $saleUserId = (int) ($property->sale_user_id ?? 0);
        if ($saleUserId > 0) {
            return $saleUserId;
        }

        $agentId = (int) ($property->agent_id ?? 0);
        if ($agentId > 0) {
            return $agentId;
        }

        return (int) ($property->created_by ?? 0);
    }
}
