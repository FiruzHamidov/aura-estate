<?php

namespace App\Services;

use App\Models\Review;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class RealtorReviewService
{
    public function supportsReviews(): bool
    {
        if (!Schema::hasTable('reviews')) {
            return false;
        }

        return Schema::hasColumns('reviews', [
            'reviewable_type',
            'reviewable_id',
            'author_name',
            'rating',
            'text',
            'created_at',
        ]);
    }

    public function approvedSummary(User $realtor): array
    {
        if (!$this->supportsReviews()) {
            return [
                'count' => 0,
                'avg_rating' => null,
            ];
        }

        $stats = $this->approvedQuery($realtor)
            ->selectRaw('COUNT(*) as aggregate_count, AVG(rating) as aggregate_rating')
            ->first();

        $count = (int) ($stats?->aggregate_count ?? 0);

        return [
            'count' => $count,
            'avg_rating' => $count > 0 ? round((float) $stats->aggregate_rating, 2) : null,
        ];
    }

    public function latestApproved(User $realtor, int $limit = 10): Collection
    {
        if (!$this->supportsReviews()) {
            return collect();
        }

        return $this->approvedQuery($realtor)
            ->select(['id', 'author_name', 'rating', 'published_at', 'created_at', 'text'])
            ->latest('published_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function approvedPaginated(User $realtor, int $perPage = 10): LengthAwarePaginator
    {
        return $this->approvedQuery($realtor)
            ->select(['id', 'author_name', 'rating', 'published_at', 'created_at', 'text'])
            ->latest('published_at')
            ->latest('id')
            ->paginate($perPage);
    }

    private function approvedQuery(User $realtor): Builder
    {
        return Review::query()
            ->whereMorphedTo('reviewable', $realtor)
            ->approved();
    }
}
