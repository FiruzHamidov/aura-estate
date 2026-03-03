<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\User;
use App\Services\RealtorReviewService;
use App\Services\SmsAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(
        private readonly RealtorReviewService $reviewService
    ) {}

    /**
     * Отправляем SMS-код на номер, чтобы подтвердить владение номером перед добавлением отзыва.
     * body: { phone: string }
     */
    public function requestCode(Request $request, SmsAuthService $smsAuthService): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
        ]);

        // Можно нормализовать phone тут (убрать пробелы, +, тире)
        $phone = $this->normalizePhone($data['phone']);

        try {
            $smsAuthService->sendVerificationCode($phone);
            return response()->json(['message' => 'Код отправлен']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Создание отзыва после проверки SMS-кода.
     * body: {
     *   agent_id: number,
     *   reviewer_name: string,
     *   reviewer_phone: string,
     *   code: string,         // SMS-код
     *   rating: 1..5,
     *   text?: string
     * }
     */
    public function store(Request $request, User $agent, SmsAuthService $smsAuthService): JsonResponse
    {
        $agent = $this->resolvePublicAgent($agent);

        $data = $request->validate([
            'reviewer_name'  => ['required', 'string', 'max:255'],
            'reviewer_phone' => ['required', 'string', 'max:64'],
            'code'           => ['required', 'string', 'max:10'],
            'rating'         => ['required', 'integer', 'min:1', 'max:5'],
            'text'           => ['nullable', 'string', 'max:5000'],
        ]);

        $phone = $this->normalizePhone($data['reviewer_phone']);

        if (!$smsAuthService->verifyCode($phone, $data['code'])) {
            return response()->json(['message' => 'Неверный или просроченный код'], 401);
        }

        $exists = Review::query()
            ->whereMorphedTo('reviewable', $agent)
            ->where('author_phone', $phone)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Отзыв с этого номера для данного агента уже существует'], 409);
        }

        $review = Review::create([
            'reviewable_type' => $agent->getMorphClass(),
            'reviewable_id' => $agent->id,
            'author_user_id' => null,
            'author_name' => $data['reviewer_name'],
            'author_phone' => $phone,
            'rating' => (int) $data['rating'],
            'text' => $data['text'] ?? null,
            'status' => 'approved',
            'published_at' => now(),
        ]);

        return response()->json([
            'message' => 'Отзыв создан',
            'review' => $this->transformReview($review),
            'agent' => $this->agentPayload($agent->fresh()),
        ], 201);
    }

    /**
     * Список отзывов по агенту (пагинация)
     * GET /api/agents/{agent}/reviews?page=1&per_page=10
     */
    public function index(User $agent, Request $request): JsonResponse
    {
        $agent = $this->resolvePublicAgent($agent);
        $perPage = min((int) $request->get('per_page', 10), 50);

        $reviews = $this->reviewService->approvedPaginated($agent, $perPage);
        $summary = $this->reviewService->approvedSummary($agent);

        return response()->json([
            'data' => collect($reviews->items())
                ->map(fn (Review $review) => $this->transformReview($review))
                ->values(),
            'pagination' => [
                'current_page' => $reviews->currentPage(),
                'last_page'    => $reviews->lastPage(),
                'per_page'     => $reviews->perPage(),
                'total'        => $reviews->total(),
            ],
            'summary' => [
                'count' => $summary['count'],
                'avg_rating' => $summary['avg_rating'],
            ],
        ]);
    }

    /**
     * Формируем полезную часть payload агента для фронта.
     */
    private function agentPayload(User $agent): array
    {
        $summary = $this->reviewService->approvedSummary($agent);

        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'phone' => $agent->phone,
            'photo' => $agent->photo,
            'description' => $agent->description,
            'rating' => $summary['avg_rating'],
            'reviewCount' => $summary['count'],
        ];
    }

    private function resolvePublicAgent(User $agent): User
    {
        $isPublicAgent = User::query()
            ->whereKey($agent->id)
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->where('slug', 'agent'))
            ->exists();

        abort_if(!$isPublicAgent, 404);

        return $agent;
    }

    private function transformReview(Review $review): array
    {
        return [
            'id' => $review->id,
            'author' => $review->author_name,
            'rating' => (int) $review->rating,
            'date' => optional($review->published_at ?? $review->created_at)->toDateString(),
            'text' => $review->text,
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        return $digits;
    }
}
