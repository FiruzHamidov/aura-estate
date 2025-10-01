<?php

namespace App\Http\Controllers;

use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    protected ChatService $chat;

    public function __construct(ChatService $chat)
    {
        $this->chat = $chat;
    }

    public function handle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'session_id' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer'],
            'context' => ['nullable', 'array'],
        ]);

        // Если есть авторизация — перезапишем user_id
        $userId = auth()->id() ?: ($validated['user_id'] ?? null);

        $reply = $this->chat->reply(
            $validated['message'],
            $validated['session_id'] ?? null,
            $userId,
            $validated['context'] ?? []
        );

        return response()->json($reply);
    }

    public function history(Request $request): JsonResponse
    {
        $sessionUuid = $request->query('session_id');
        if (!$sessionUuid) {
            return response()->json(['message' => 'session_id required'], 422);
        }

        $session = \App\Models\ChatSession::where('session_uuid', $sessionUuid)->first();
        if (!$session) {
            return response()->json(['session_id' => $sessionUuid, 'messages' => []], 200);
        }

        // Пагинация курсором (опционально в запросе: ?cursor=...&limit=50)
        $limit  = (int) $request->query('limit', 50);
        $cursor = $request->query('cursor'); // created_at ISO или id — ниже выберем по created_at

        $qb = \App\Models\ChatMessage::where('chat_session_id', $session->id)
            ->orderBy('created_at')
            ->orderBy('id'); // на случай одинаковых created_at

        if ($cursor) {
            // Берём всё «после» курсора по времени
            try {
                $cursorTs = \Carbon\Carbon::parse($cursor);
                $qb->where(function($q) use ($cursorTs) {
                    $q->where('created_at', '>', $cursorTs)
                        ->orWhere(function($qq) use ($cursorTs) {
                            $qq->where('created_at', '=', $cursorTs);
                            $qq->where('id', '>', 0);
                        });
                });
            } catch (\Throwable $e) {
                // игнорируем битый курсор
            }
        }

        $msgs = $qb->limit($limit)->get(['id','role','content','items','created_at']);

        // Безопасное преобразование JSON + нормализация времени
        $messages = [];
        foreach ($msgs as $m) {
            // items может быть: string(JSON) | array | null
            $rawItems = $m->items;

            if (is_string($rawItems)) {
                $decodedItems = json_decode($rawItems, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $decodedItems = null; // битый JSON – игнорируем
                }
            } elseif (is_array($rawItems)) {
                $decodedItems = $rawItems; // уже декодирован
            } else {
                $decodedItems = null; // null/другое
            }

            $messages[] = [
                'id'         => $m->id,
                'role'       => $m->role,
                'content'    => $m->content, // может быть null у tool
                'items'      => $decodedItems,
                'created_at' => optional($m->created_at)->toISOString(),
            ];
        }

        // next_cursor = время последнего сообщения
        $nextCursor = null;
        if (!empty($messages)) {
            $last = end($messages);
            $nextCursor = $last['created_at'];
        }

        return response()->json([
            'session_id'  => $sessionUuid,
            'messages'    => $messages,      // уже массив, не Collection
            'next_cursor' => $nextCursor,    // можно не использовать на фронте
        ], 200);
    }

}
