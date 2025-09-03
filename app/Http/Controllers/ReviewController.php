<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\User;
use App\Services\SmsAuthService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    /**
     * Отправляем SMS-код на номер, чтобы подтвердить владение номером перед добавлением отзыва.
     * body: { phone: string }
     */
    public function requestCode(Request $request, SmsAuthService $smsAuthService)
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
    public function store(Request $request, SmsAuthService $smsAuthService)
    {
        $data = $request->validate([
            'agent_id'       => ['required', 'integer', Rule::exists('users', 'id')],
            'reviewer_name'  => ['required', 'string', 'max:255'],
            'reviewer_phone' => ['required', 'string', 'max:64'],
            'code'           => ['required', 'string', 'max:10'],
            'rating'         => ['required', 'integer', 'min:1', 'max:5'],
            'text'           => ['nullable', 'string', 'max:5000'],
        ]);

        $agent = User::findOrFail($data['agent_id']);

        // Нормализуем телефон
        $phone = $this->normalizePhone($data['reviewer_phone']);

        // Проверка SMS-кода
        if (!$smsAuthService->verifyCode($phone, $data['code'])) {
            return response()->json(['message' => 'Неверный или просроченный код'], 401);
        }

        // Защита от дублей: уникальность (agent_id, reviewer_phone)
        // Если нужно разрешить повторно через N дней — лучше убрать unique в БД,
        // а тут проверять вручную по дате.
        $exists = Review::where('agent_id', $agent->id)
            ->where('reviewer_phone', $phone)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Отзыв с этого номера для данного агента уже существует'], 409);
        }

        // Транзакция: создать отзыв и обновить агрегаты агента (если кэшируем)
        $review = DB::transaction(function () use ($agent, $data, $phone) {
            $review = Review::create([
                'agent_id'        => $agent->id,
                'reviewer_user_id'=> auth('sanctum')->id(), // если автор залогинен, иначе null
                'reviewer_name'   => $data['reviewer_name'],
                'reviewer_phone'  => $phone,
                'rating'          => (int)$data['rating'],
                'text'            => $data['text'] ?? null,
                'status'          => 'approved', // или 'pending' если нужна модерация
            ]);

            // Обновим кэш рейтинга, если колонки есть
            if (Schema()->hasColumn('users', 'reviews_count') && Schema()->hasColumn('users', 'reviews_avg_rating')) {
                // Пересчёт агрегатов (чтобы точно)
                $stats = Review::where('agent_id', $agent->id)
                    ->approved()
                    ->selectRaw('COUNT(*) as cnt, AVG(rating) as avg_rating')
                    ->first();

                $agent->update([
                    'reviews_count' => (int) ($stats->cnt ?? 0),
                    'reviews_avg_rating' => round((float) ($stats->avg_rating ?? 0), 2),
                ]);
            }

            return $review;
        });

        return response()->json([
            'message' => 'Отзыв создан',
            'review'  => $review,
            'agent'   => $this->agentPayload($agent->fresh()),
        ], 201);
    }

    /**
     * Список отзывов по агенту (пагинация)
     * GET /api/agents/{agent}/reviews?page=1&per_page=10
     */
    public function index(User $agent, Request $request)
    {
        $perPage = min((int) $request->get('per_page', 10), 50);

        $reviews = Review::approved()
            ->where('agent_id', $agent->id)
            ->latest()
            ->paginate($perPage);

        // Чтобы фронту было удобно — добавим агрегаты
        $stats = Review::where('agent_id', $agent->id)
            ->approved()
            ->selectRaw('COUNT(*) as cnt, AVG(rating) as avg_rating')
            ->first();

        return response()->json([
            'data' => $reviews->items(),
            'pagination' => [
                'current_page' => $reviews->currentPage(),
                'last_page'    => $reviews->lastPage(),
                'per_page'     => $reviews->perPage(),
                'total'        => $reviews->total(),
            ],
            'summary' => [
                'count' => (int) ($stats->cnt ?? 0),
                'avg_rating' => round((float) ($stats->avg_rating ?? 0), 2),
            ],
        ]);
    }

    /**
     * Формируем полезную часть payload агента для фронта.
     */
    private function agentPayload(User $agent): array
    {
        // Если кэшируем — берём из полей, иначе считаем налету:
        if (Schema()->hasColumn('users', 'reviews_count') && Schema()->hasColumn('users', 'reviews_avg_rating')) {
            $count = (int) $agent->reviews_count;
            $avg   = (float) $agent->reviews_avg_rating;
        } else {
            $count = (int) $agent->reviewsReceived()->approved()->count();
            $avg   = (float) $agent->reviewsReceived()->approved()->avg('rating');
        }

        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'phone' => $agent->phone,
            'photo' => $agent->photo,
            'description' => $agent->description,
            'rating' => round($avg, 2),
            'reviewCount' => $count,
        ];
    }

    private function normalizePhone(string $phone): string
    {
        // простой нормалайзер: оставим только цифры, и, например, добавим +992 при необходимости
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        // Если твой проект использует свой формат — приведи к нему тут.
        return $digits;
    }
}
