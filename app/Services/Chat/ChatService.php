<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Support\Facades\Http;

class ChatService
{
    public function __construct(private PropertyRepository $props) {}

    public function reply(string $userMessage, ?string $sessionUuid, ?int $userId, array $context = []): array
    {
        $locale  = request()->attributes->get('client_locale', app()->getLocale());
        $session = $this->getOrCreateSession($sessionUuid, $userId, $locale);

        // Логируем сообщение пользователя
        $this->storeMessage($session->id, 'user', $userMessage);

        // Контекст (история) для модели
        $messages = [
            [
                'role' => 'system',
                'content' =>
                    "You are the official **Aura Estate** assistant (aura.tj).\n".
                    "- Your ONLY domain is real estate listings published on Aura Estate: apartments, houses, cottages, rooms, commercial properties, land plots, new-buildings.\n".
                    "- Never discuss or recommend unrelated topics such as cars, transport, electronics, services, jobs, etc.\n".
                    "- Detect the user's language (Russian, Tajik, or English) and always respond in that language.\n".
                    "- If the user is searching for real estate, extract relevant filters (city, district, offer type, property type, rooms, price range) and call the tool `search_properties`.\n".
                    "- When showing results, present 3–6 best matches with: title, price, currency, city/district, and a CTA link to aura.tj.\n".
                    "- Be concise, polite, and professional — answer in short helpful sentences, like a property consultant.\n".
                    "- If no properties are found, ask clarifying questions (e.g., budget, location, rooms) and offer to broaden the search.\n".
                    "- Always represent Aura Estate as the source: mention 'Aura Estate' or aura.tj when appropriate.\n",
            ],
            ['role' => 'user', 'content' => $userMessage],
        ];

        // Описание инструмента (Responses API: name на верхнем уровне)
        $tools = [[
            'type'        => 'function',
            'name'        => 'search_properties',
            'description' => 'Search Aura Estate DB for properties',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'city'          => ['type' => 'string'],
                    'district'      => ['type' => 'string'],
                    'offer_type'    => ['type' => 'string', 'enum' => ['sale','rent']],
                    'property_type' => ['type' => 'string'],
                    'rooms'         => ['type' => 'integer'],
                    'price_max'     => ['type' => 'number'],
                    'price_min'     => ['type' => 'number'],
                    'limit'         => ['type' => 'integer', 'default' => 6],
                ],
            ],
        ]];

        // === 1) Первый проход: модель решает, вызывать ли tool ===
        $res1 = $this->openai([
            'model'       => env('OPENAI_MODEL', 'o3-mini'),
            'input'       => $messages,
            'tools'       => $tools,
            'tool_choice' => 'auto',
            'store'       => false,
            'reasoning'   => ['effort' => 'low'],
        ]);

        $toolCalls     = $this->extractToolCalls($res1);
        $assistantText = $this->extractAssistantText($res1);

        if (!empty($toolCalls)) {
            $call = $toolCalls[0];

            if (($call['name'] ?? '') === 'search_properties') {
                $args  = json_decode($call['arguments'] ?? '{}', true) ?: [];
                $found = $this->props->search($args);

                // логируем tool-вызов
                $this->storeMessage($session->id, 'tool', null, [
                    'tool_name' => 'search_properties',
                    'tool_args' => $args,
                    'items'     => $found,
                ]);

                // Готовим "текстовую" вставку результатов для модели (без tool_outputs)
                $resultsEnvelope = [
                    'args'    => $args,
                    'results' => $found,
                    'total'   => count($found),
                ];

                // Добавляем в диалог служебное сообщение для модели (developer),
                // чтобы она на основе этих данных дала короткий ответ пользователю.
                $messages[] = [
                    'role'    => 'developer',
                    'content' =>
                        "Tool search_properties returned JSON. " .
                        "Use it to answer concisely in the user's language. " .
                        "If results are empty, politely ask clarifying filters. " .
                        "DATA:\n" . json_encode($resultsEnvelope, JSON_UNESCAPED_UNICODE),
                ];

                // Второй вызов: просто передаём обновлённый input (без tool_outputs и без role: tool)
                $res2 = $this->openai([
                    'model' => env('OPENAI_MODEL', 'o3-mini'),
                    'input' => $messages,
                    'store' => false,
                ]);

                $assistantText = $this->extractAssistantText($res2);

                $this->storeMessage($session->id, 'assistant', $assistantText, ['items' => $found]);

                $session->update([
                    'last_user_message_at'      => now(),
                    'last_assistant_message_at' => now(),
                ]);

                return [
                    'session_id' => $session->session_uuid,
                    'answer'     => $assistantText ?? '',
                    'items'      => $found,
                    'locale'     => $locale,
                ];
            }
        }

        // === 3) Tool не понадобился — просто логируем ответ ассистента ===
        $this->storeMessage($session->id, 'assistant', $assistantText ?? '');

        $session->update([
            'last_user_message_at'      => now(),
            'last_assistant_message_at' => now(),
        ]);

        return [
            'session_id' => $session->session_uuid,
            'answer'     => $assistantText ?? '',
            'items'      => [],
            'locale'     => $locale,
        ];
    }

    private function getOrCreateSession(?string $sessionUuid, ?int $userId, string $locale): ChatSession
    {
        if ($sessionUuid) {
            $s = ChatSession::where('session_uuid', $sessionUuid)->first();
            if ($s) return $s;
        }

        return ChatSession::create([
            'session_uuid' => $sessionUuid ?: null,
            'user_id'      => $userId,
            'language'     => $locale,
            'meta'         => ['ua' => request()->userAgent()],
        ]);
    }

    private function storeMessage(int $sessionId, string $role, ?string $content, array $extra = []): ChatMessage
    {
        return ChatMessage::create([
            'chat_session_id'   => $sessionId,
            'role'              => $role,
            'content'           => $content,
            'tool_name'         => $extra['tool_name'] ?? null,
            'tool_args'         => $extra['tool_args'] ?? null,
            'items'             => $extra['items'] ?? null,
            'prompt_tokens'     => $extra['prompt_tokens'] ?? null,
            'completion_tokens' => $extra['completion_tokens'] ?? null,
        ]);
    }

    private function openai(array $payload): array
    {
        try {
            $resp = Http::withToken(env('OPENAI_API_KEY'))
                ->baseUrl(env('OPENAI_BASE', 'https://api.openai.com'))
                ->timeout(60)
                ->post('/v1/responses', $payload);

            $status = $resp->status();
            $body   = $resp->body(); // сырой JSON

            // всегда логируем сырое тело (для отладки)
            \Log::info('[OpenAI] status='.$status.' body='.$body);

            // если ошибка статуса — вернём структуру с error и raw
            if ($status < 200 || $status >= 300) {
                return [
                    'error' => [
                        'status'  => $status,
                        'message' => optional(json_decode($body, true))['error']['message'] ?? 'Unknown error',
                    ],
                    'raw' => $body,
                ];
            }

            // успешный JSON
            return json_decode($body, true) ?? ['raw' => $body];

        } catch (\Throwable $e) {
            \Log::error('[OpenAI][EXCEPTION] '.$e->getMessage());
            return [
                'error' => [
                    'status'  => 0,
                    'message' => $e->getMessage(),
                ],
                'raw' => null,
            ];
        }
    }

    /**
     * Извлечь текст ассистента из структуры Responses API.
     * Пытаемся сначала взять короткий путь `output.text`, иначе парсим message/content.
     */
    private function extractAssistantText(array $res): ?string
    {
        // Короткий путь — иногда есть прямое поле
        if (!empty($res['output']['text']) && is_string($res['output']['text'])) {
            return $res['output']['text'];
        }

        // Универсальный путь — ищем message с output_text
        $output = $res['output'] ?? null;
        if (is_array($output)) {
            foreach ($output as $part) {
                if (($part['type'] ?? '') === 'message' && !empty($part['content'])) {
                    foreach ($part['content'] as $c) {
                        if (($c['type'] ?? '') === 'output_text' && isset($c['text'])) {
                            return $c['text'];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Извлечь tool_calls из ответа Responses API.
     * Поддерживаем оба варианта расположения.
     */
    private function extractToolCalls(array $res): array
    {
        // Вариант A: некоторые модели кладут в output.tool_calls
        if (!empty($res['output']['tool_calls']) && is_array($res['output']['tool_calls'])) {
            return $res['output']['tool_calls'];
        }

        $calls = [];
        $output = $res['output'] ?? null;
        if (!is_array($output)) {
            return $calls;
        }

        foreach ($output as $part) {
            // Вариант B: новый формат — отдельные части с type: "function_call"
            if (($part['type'] ?? '') === 'function_call') {
                $calls[] = [
                    'id'        => $part['id']        ?? null,          // может отсутствовать
                    'call_id'   => $part['call_id']   ?? null,          // ВАЖНО: используем его
                    'name'      => $part['name']      ?? null,
                    'arguments' => $part['arguments'] ?? '{}',
                    'type'      => 'function',
                ];
                continue;
            }

            // Вариант C: старый формат — внутри message -> tool_calls
            if (($part['type'] ?? '') === 'message' && !empty($part['tool_calls'])) {
                foreach ($part['tool_calls'] as $tc) {
                    $calls[] = $tc;
                }
            }
        }

        return $calls;
    }
}
