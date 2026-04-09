<?php

namespace App\Services\Crm\Matching;

use App\Models\Client;
use App\Models\ClientNeed;
use App\Models\Property;
use App\Models\User;
use App\Support\ClientAccess;
use Illuminate\Database\Eloquent\Builder;

class ClientPropertyMatcher
{
    public function __construct(
        private readonly ClientAccess $clientAccess,
        private readonly MatchScoreCalculator $scoreCalculator,
        private readonly MatchReasonBuilder $reasonBuilder,
    ) {
    }

    public function forProperty(User $authUser, Property $property, int $limit = 10): array
    {
        $roleSlug = $this->clientAccess->roleSlug($authUser);

        $clients = $this->clientAccess->visibleQuery($authUser)
            ->where('clients.status', 'active')
            ->whereIn('clients.contact_kind', Client::kindsMatchingFilter(Client::CONTACT_KIND_BUYER))
            ->whereHas('openNeeds', function (Builder $query) use ($property) {
                $this->applyOpenNeedScope($query);
                $this->applyNeedCoarseFilters($query, $property);
            })
            ->with([
                'openNeeds' => function ($query) use ($property) {
                    $this->applyOpenNeedScope($query);
                    $this->applyNeedCoarseFilters($query, $property);
                    $query->with(['type', 'status', 'location', 'propertyTypes', 'repairType']);
                },
            ])
            ->orderByDesc('clients.id')
            ->limit(max($limit * 5, 50));

        if ($roleSlug === 'agent') {
            $clients->where(function (Builder $query) use ($authUser) {
                $query->where('clients.responsible_agent_id', $authUser->id)
                    ->orWhere('clients.created_by', $authUser->id);
            });
        }

        $clients = $clients->get();

        return $clients
            ->flatMap(function (Client $client) use ($property) {
                return $client->openNeeds->map(function (ClientNeed $need) use ($client, $property) {
                    $match = $this->scoreCalculator->score($need, $property);
                    $score = (int) $match['score'];

                    return [
                        'id' => $client->id . ':' . $need->id,
                        'client_id' => $client->id,
                        'need_id' => $need->id,
                        'score' => $score,
                        'match_level' => $this->reasonBuilder->level($score),
                        'reasons' => $this->reasonBuilder->build($match['reason_codes']),
                        'client' => $this->clientSummary($client),
                        'need' => $this->needSummary($need),
                    ];
                });
            })
            ->filter(fn (array $match) => $match['score'] >= 40)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    public function forClient(Client $client, int $limitPerNeed = 10): array
    {
        $client->load([
            'openNeeds' => function ($query) {
                $this->applyOpenNeedScope($query);
                $query->with(['type', 'status', 'location', 'propertyTypes', 'repairType']);
            },
        ]);

        return $client->openNeeds
            ->map(function (ClientNeed $need) use ($limitPerNeed) {
                return [
                    'need' => $this->needSummary($need),
                    'matches' => $this->matchingPropertiesForNeed($need, $limitPerNeed),
                ];
            })
            ->filter(fn (array $entry) => $entry['matches'] !== [])
            ->values()
            ->all();
    }

    private function matchingPropertiesForNeed(ClientNeed $need, int $limitPerNeed): array
    {
        $properties = Property::query()
            ->whereNotIn('moderation_status', ['deleted', 'sold', 'rented', 'sold_by_owner'])
            ->where(function (Builder $query) use ($need) {
                if ($this->scoreCalculator->offerTypeMatches((string) ($need->type?->slug ?? ''), 'sale')) {
                    $query->orWhere('offer_type', 'sale');
                }

                if ($this->scoreCalculator->offerTypeMatches((string) ($need->type?->slug ?? ''), 'rent')) {
                    $query->orWhere('offer_type', 'rent');
                }
            })
            ->where(function (Builder $query) use ($need) {
                $this->applyPropertyCoarseFilters($query, $need);
            })
            ->orderByDesc('id')
            ->limit(max($limitPerNeed * 5, 50))
            ->get();

        return $properties
            ->map(function (Property $property) use ($need) {
                $match = $this->scoreCalculator->score($need, $property);
                $score = (int) $match['score'];

                return [
                    'id' => $property->id,
                    'score' => $score,
                    'match_level' => $this->reasonBuilder->level($score),
                    'reasons' => $this->reasonBuilder->build($match['reason_codes']),
                    'property' => $this->propertySummary($property),
                ];
            })
            ->filter(fn (array $match) => $match['score'] >= 40)
            ->sortByDesc('score')
            ->take($limitPerNeed)
            ->values()
            ->all();
    }

    private function applyOpenNeedScope($query): void
    {
        $query->whereHas('status', fn (Builder $statusQuery) => $statusQuery->where('is_closed', false))
            ->whereHas('type', fn (Builder $typeQuery) => $typeQuery->whereIn('slug', ['buy', 'rent', 'invest']));
    }

    private function applyNeedCoarseFilters($query, Property $property): void
    {
        $query->whereHas('type', function (Builder $typeQuery) use ($property) {
            if ($property->offer_type === 'rent') {
                $typeQuery->where('slug', 'rent');

                return;
            }

            $typeQuery->whereIn('slug', ['buy', 'invest']);
        });

        $query->where(function (Builder $builder) use ($property) {
            $builder->whereNull('location_id')
                ->orWhere('location_id', $property->location_id);
        });

        if (!empty($property->district)) {
            $district = trim((string) $property->district);
            $query->where(function (Builder $builder) use ($district) {
                $builder->whereNull('district')
                    ->orWhere('district', 'like', '%' . $district . '%');
            });
        }

        if (!empty($property->type_id)) {
            $query->where(function (Builder $builder) use ($property) {
                $builder->whereNull('property_type_id')
                    ->orWhere('property_type_id', $property->type_id)
                    ->orWhereHas('propertyTypes', fn (Builder $typesQuery) => $typesQuery->whereKey($property->type_id));
            });
        }

        if ($property->price !== null) {
            $price = (float) $property->price;
            $query->where(function (Builder $builder) use ($price) {
                $builder->where(function (Builder $blankBudget) {
                    $blankBudget->whereNull('budget_total')
                        ->whereNull('budget_to');
                })->orWhere('budget_total', '>=', $price * 0.85)
                    ->orWhere('budget_to', '>=', $price * 0.85);
            });
        }
    }

    private function applyPropertyCoarseFilters(Builder $query, ClientNeed $need): void
    {
        if (!empty($need->location_id)) {
            $query->where('location_id', $need->location_id);
        } elseif (!empty($need->district)) {
            $district = trim((string) $need->district);
            $query->where('district', 'like', '%' . $district . '%');
        }

        $propertyTypeIds = $need->property_type_ids;
        if ($propertyTypeIds !== []) {
            $query->whereIn('type_id', $propertyTypeIds);
        }

        if ($need->budget_total !== null || $need->budget_to !== null) {
            $maxBudget = $need->budget_total !== null ? (float) $need->budget_total : (float) $need->budget_to;
            $query->where('price', '<=', $maxBudget * 1.15);
        }

        if ($need->budget_from !== null) {
            $query->where('price', '>=', ((float) $need->budget_from) * 0.85);
        }
    }

    private function clientSummary(Client $client): array
    {
        return [
            'id' => $client->id,
            'full_name' => $client->full_name,
            'phone' => $client->phone,
            'contact_kind' => $client->contact_kind,
            'responsible_agent_id' => $client->responsible_agent_id,
        ];
    }

    private function propertySummary(Property $property): array
    {
        return [
            'id' => $property->id,
            'title' => $property->title,
            'price' => $property->price,
            'currency' => $property->currency,
            'offer_type' => $property->offer_type,
            'district' => $property->district,
            'rooms' => $property->rooms,
            'total_area' => $property->total_area,
            'moderation_status' => $property->moderation_status,
        ];
    }

    private function needSummary(ClientNeed $need): array
    {
        return [
            'id' => $need->id,
            'type' => $need->type?->only(['id', 'name', 'slug']),
            'status' => $need->status?->only(['id', 'name', 'slug']),
            'budget_from' => $need->budget_from,
            'budget_to' => $need->budget_to,
            'budget_total' => $need->budget_total,
            'currency' => $need->currency,
            'location_id' => $need->location_id,
            'district' => $need->district,
            'property_type_ids' => $need->property_type_ids,
            'rooms_from' => $need->rooms_from,
            'rooms_to' => $need->rooms_to,
            'area_from' => $need->area_from,
            'area_to' => $need->area_to,
            'repair_type_id' => $need->repair_type_id,
            'wants_mortgage' => $need->wants_mortgage,
        ];
    }
}
