<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

class PublicRealtorController extends Controller
{
    /**
     * Roles allowed to expose a public team profile.
     */
    private const PUBLIC_ROLE_SLUGS = ['agent'];

    public function show(int $id): JsonResponse
    {
        $columns = ['id', 'name', 'phone', 'photo', 'description', 'status', 'role_id'];

        if (Schema::hasColumn('users', 'position')) {
            $columns[] = 'position';
        }

        $realtor = User::query()
            ->select($columns)
            ->with('role:id,name,slug')
            ->whereKey($id)
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->whereIn('slug', self::PUBLIC_ROLE_SLUGS))
            ->first();

        abort_if(!$realtor, 404);

        [$rating, $reviewCount, $reviews] = $this->resolveReviewSummary($realtor);

        return response()
            ->json([
                'id' => $realtor->id,
                'name' => $realtor->name,
                'photo' => $realtor->photo,
                'description' => $realtor->description,
                'phone' => $realtor->phone,
                'position' => $this->resolvePosition($realtor),
                'rating' => $rating,
                'review_count' => $reviewCount,
                'reviews' => $reviews,
            ])
            ->header('Cache-Control', 'public, max-age=300, s-maxage=900, stale-while-revalidate=3600');
    }

    private function resolvePosition(User $realtor): ?string
    {
        if (Schema::hasColumn('users', 'position')) {
            return $realtor->getAttribute('position');
        }

        return match ($realtor->role?->slug) {
            'agent' => 'Специалист по недвижимости',
            'manager' => 'Менеджер',
            default => $realtor->role?->name,
        };
    }

    private function resolveReviewSummary(User $realtor): array
    {
        if (!$this->reviewsTableSupportsPublicPayload()) {
            return [null, 0, []];
        }

        $baseQuery = Review::query()
            ->whereMorphedTo('reviewable', $realtor)
            ->when(
                Schema::hasColumn('reviews', 'status'),
                fn ($query) => $query->where('status', 'approved')
            );

        $stats = (clone $baseQuery)
            ->selectRaw('COUNT(*) as aggregate_count, AVG(rating) as aggregate_rating')
            ->first();

        $reviews = (clone $baseQuery)
            ->select(['id', 'author_name', 'rating', 'published_at', 'created_at', 'text'])
            ->latest('published_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (Review $review) => [
                'id' => $review->id,
                'author' => $review->author_name,
                'rating' => (int) $review->rating,
                'date' => optional($review->published_at ?? $review->created_at)->toDateString(),
                'text' => $review->text,
            ])
            ->values()
            ->all();

        $count = (int) ($stats?->aggregate_count ?? 0);
        $rating = $count > 0 ? round((float) $stats->aggregate_rating, 2) : null;

        return [$rating, $count, $reviews];
    }

    private function reviewsTableSupportsPublicPayload(): bool
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
}
