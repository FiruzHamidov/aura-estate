<?php

namespace App\Http\Controllers;

use App\Services\RealtorReviewService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

class PublicRealtorController extends Controller
{

    public function __construct(
        private readonly RealtorReviewService $reviewService
    ) {}

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
            ->whereHas('role', fn ($query) => $query->withPermission('public_team.subject.profile'))
            ->first();

        abort_if(!$realtor, 404, 'Realtor not found or not public');

        $summary = $this->reviewService->approvedSummary($realtor);
        $reviews = $this->reviewService
            ->latestApproved($realtor, 10)
            ->map(fn ($review) => $this->transformReview($review))
            ->values()
            ->all();

        return response()
            ->json([
                'id' => $realtor->id,
                'name' => $realtor->name,
                'photo' => $realtor->photo,
                'description' => $realtor->description,
                'phone' => $realtor->phone,
                'position' => $this->resolvePosition($realtor),
                'rating' => $summary['avg_rating'],
                'review_count' => $summary['count'],
                'reviews' => $reviews,
            ])
            ->header('Cache-Control', 'no-store');
    }

    private function resolvePosition(User $realtor): ?string
    {
        if (Schema::hasColumn('users', 'position')) {
            return $realtor->getAttribute('position');
        }

        return match ($realtor->role?->slug) {
            'agent' => 'Специалист по недвижимости',
            'mop' => 'МОП',
            'manager' => 'Менеджер',
            default => $realtor->role?->name,
        };
    }

    private function transformReview($review): array
    {
        return [
            'id' => $review->id,
            'author' => $review->author_name,
            'rating' => (int) $review->rating,
            'date' => optional($review->published_at ?? $review->created_at)->toDateString(),
            'text' => $review->text,
        ];
    }
}
