<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

/** Read-only presentation of the same eligibility used by the public catalogue. */
final class PropertyPublicationVisibility
{
    public function forProperty(Property $property): array
    {
        return $this->forProperties(new Collection([$property]))[$property->id];
    }

    /** One public-eligibility query per page, never one query per card. */
    public function forProperties(Collection $properties): array
    {
        if ($properties->isEmpty()) {
            return [];
        }

        $publicIds = Property::query()->whereKey($properties->modelKeys())->publicSearchable()->pluck('id')->flip();
        $hasPublicationStatus = Schema::hasColumn('properties', 'publication_status');
        $hasCatalogStatuses = Schema::hasTable('property_statuses');
        if ($hasCatalogStatuses) {
            $properties->loadMissing('status');
        }

        $result = [];
        foreach ($properties as $property) {
            $isPublic = $publicIds->has($property->id);
            $reasons = [];
            if (! $isPublic) {
                if (in_array($property->deal_status, ['sold', 'sold_by_owner', 'rented', 'client_denied'], true)) {
                    $reasons[] = 'deal_'.$property->deal_status;
                }
                if ($hasCatalogStatuses && in_array($property->status?->slug, Property::CLOSED_STATUS_SLUGS, true)) {
                    $reasons[] = 'legacy_'.$property->status->slug;
                }
                if ($property->sold_at !== null) {
                    $reasons[] = 'sale_recorded';
                }
                if ($hasPublicationStatus && $property->publication_status !== 'published') {
                    $reasons[] = 'publication_'.($property->publication_status ?: 'unpublished');
                }
                if (in_array($property->moderation_status, ['sold', 'sold_by_owner', 'rented', 'denied'], true)
                    || (! $hasPublicationStatus && $property->moderation_status !== Property::PUBLIC_MODERATION_STATUS)) {
                    $reasons[] = 'moderation_'.$property->moderation_status;
                }
                // Remaining exclusions in publicSearchable are blocking cases / confirmed duplicates.
                if ($reasons === []) {
                    $reasons[] = 'moderation_blocked';
                }
            }
            $result[$property->id] = ['is_public' => $isPublic, 'reason_codes' => $reasons];
        }

        return $result;
    }
}
