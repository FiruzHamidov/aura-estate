<?php

namespace App\Services;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class PublicPropertyPresenter
{
    private ?array $resolvedRelations = null;

    public function __construct(
        private readonly PropertyBuildingType $buildingType,
    ) {}

    public function relations(): array
    {
        if ($this->resolvedRelations !== null) {
            return $this->resolvedRelations;
        }

        $relations = [];
        $tableRelations = [
            'property_types' => 'type',
            'property_statuses' => 'status',
            'locations' => 'location',
            'repair_types' => 'repairType',
            'property_photos' => 'photos',
            'contract_types' => 'contractType',
            'document_types' => 'documentType',
            'developers' => 'developer',
            'heating_types' => 'heating',
            'parking_types' => 'parking',
            'building_types' => 'buildingType',
        ];

        foreach ($tableRelations as $table => $relation) {
            if (Schema::hasTable($table)) {
                $relations[] = $relation;
            }
        }

        if (Schema::hasTable('users') && Schema::hasTable('roles')) {
            $relations[] = 'creator.role';
            $relations[] = 'agent.role';
        }

        if (Schema::hasTable('features') && Schema::hasTable('feature_property')) {
            $relations[] = 'features';
        }

        if (Schema::hasTable('tags') && Schema::hasTable('property_tag')) {
            $relations[] = 'tags';
        }

        return $this->resolvedRelations = $relations;
    }

    public function present(Property $property): array
    {
        return $this->serialize($property, true);
    }

    public function presentCard(Property $property): array
    {
        return $this->serialize($property, false);
    }

    private function serialize(Property $property, bool $includeAgentContact): array
    {
        $property->loadMissing($this->relations());
        $payload = $property->only([
            'id', 'title', 'description', 'created_by', 'agent_id', 'type_id', 'status_id', 'location_id',
            'repair_type_id', 'heating_type_id', 'parking_type_id', 'building_type_id', 'price',
            'discount_price', 'currency', 'offer_type', 'rooms', 'youtube_link', 'instagram_link',
            'total_area', 'land_size', 'living_area', 'floor', 'total_floors', 'year_built', 'condition',
            'construction_status', 'renovation_permission_status', 'has_garden', 'has_parking',
            'apartment_type', 'bathroom_type', 'is_mortgage_available', 'is_from_developer',
            'moderation_status', 'publication_status', 'publication_expires_at', 'deal_status',
            'listing_type', 'is_full_apartment', 'developer_id', 'views_count', 'landmark', 'latitude',
            'longitude', 'address', 'district', 'district_id', 'created_at', 'updated_at',
            'listing_updated_at', 'listed_at', 'contract_type_id', 'document_type_id',
            'public_price_badge',
        ]);

        foreach ([
            'type',
            'status',
            'location',
            'repairType',
            'photos',
            'contractType',
            'documentType',
            'developer',
            'heating',
            'parking',
            'buildingType',
            'features',
            'tags',
        ] as $relation) {
            if ($property->relationLoaded($relation)) {
                $payload[Str::snake($relation)] = $property->getRelation($relation)?->toArray();
            }
        }

        $payload['creator'] = $this->publicAgent($property->creator, $includeAgentContact);
        $payload['responsible_agent'] = $this->publicAgent($property->agent, $includeAgentContact);
        $payload['is_co_owned'] = (int) $property->getAttribute('co_owner_user_id') > 0;

        if ($property->relationLoaded('reels')) {
            $payload['reels'] = $property->reels
                ->map(fn ($reel): array => $reel->only([
                    'id', 'property_id', 'title', 'description', 'video_url', 'hls_url', 'mp4_url',
                    'preview_image', 'thumbnail_url', 'duration', 'aspect_ratio', 'status', 'sort_order',
                    'is_featured', 'views_count', 'likes_count', 'published_at',
                ]))
                ->values()
                ->all();
        }

        return $this->buildingType->present($property, $payload);
    }

    private function publicAgent(?User $user, bool $includeContact): ?array
    {
        if (! $user) {
            return null;
        }

        if (! $includeContact) {
            return $user->only(['id', 'name', 'photo']);
        }

        return array_merge(
            $user->only(['id', 'name', 'phone', 'photo']),
            ['role' => $user->role?->only(['name', 'slug'])]
        );
    }
}
