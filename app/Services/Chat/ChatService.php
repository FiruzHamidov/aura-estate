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

        // Загрузка истории сообщений из БД (user+assistant, последние 20)
        $history = ChatMessage::where('chat_session_id', $session->id)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id', 'asc')
            ->limit(20)
            ->get();

        // Формируем массив messages: system + история + текущее сообщение пользователя
        $messages = [
            [
                'role' => 'system',
                'content' =>
                    "You are the official **Aura Estate** assistant (aura.tj).\n".
                    "- Your ONLY domain is real estate listings published on Aura Estate: apartments, houses, cottages, rooms, commercial properties, land plots, new-buildings.\n".
                    "- Never discuss or recommend unrelated topics such as cars, transport, electronics, services, jobs, etc.\n".
                    "- Detect the user's language (Russian, Tajik, or English) and always respond in that language.\n".
                    "- If the user is searching for real estate, extract relevant filters (city, district, offer type, property type, rooms, price range).\n".
                    "- Users may provide filters gradually across multiple messages. You MUST remember and combine previous filters from the conversation.\n".
                    "- When enough filters are collected, call the tool `search_properties`.\n".
                    "- When showing results, present 3–6 best matches with: title, price, currency, city/district, and a CTA link to aura.tj.\n".
                    "- If no properties are found, ask clarifying questions.\n".
                    "- Always represent Aura Estate as the source.\n".
                    "- Use ONLY the exact filter parameter names defined in search_properties.\n".
                    "- For ranges, ALWAYS use From/To suffixes (priceFrom, roomsTo, etc).\n".
                    "- For districts, ALWAYS pass an array: districts[].\n".
                    "- Do NOT invent fields that are not listed.\n".
                    "- AVAILABLE DISTRICTS (strict values from database):\n".
                    "  • Сино\n".
                    "  • И Сомони\n".
                    "  • Шохмансур\n".
                    "  • Фирдавси\n".
                    "- If user input is similar or misspelled (e.g. \"фирдоуси\", \"сомони\"), map it to the closest value from the list above.\n".
                    "- ALWAYS pass district names EXACTLY as stored in the database.\n",
            ],
        ];
        foreach ($history as $h) {
            if ($h->content) {
                $messages[] = [
                    'role' => $h->role,
                    'content' => $h->content,
                ];
            }
        }
        $messages[] = [
            'role' => 'user',
            'content' => $userMessage,
        ];

        // Описание инструмента (Responses API: name на верхнем уровне)
        $tools = [[
            'type'        => 'function',
            'name'        => 'search_properties',
            'description' => 'Search Aura Estate properties using advanced filters',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [

                    // мульти-статусы
                    'moderation_status' => [
                        'type'  => 'array',
                        'items' => ['type' => 'string']
                    ],

                    // districts fuzzy
                    'districts' => [
                        'type'  => 'array',
                        'items' => ['type' => 'string']
                    ],

                    // текстовые поля
                    'title'           => ['type' => 'string'],
                    'description'     => ['type' => 'string'],
                    'address'         => ['type' => 'string'],
                    'landmark'        => ['type' => 'string'],
                    'condition'       => ['type' => 'string'],
                    'apartment_type'  => ['type' => 'string'],
                    'owner_phone'     => ['type' => 'string'],

                    // точные поля
                    'type_id'                => ['type' => 'integer'],
                    'status_id'              => ['type' => 'integer'],
                    'location_id'            => ['type' => 'integer'],
                    'repair_type_id'         => ['type' => 'integer'],
                    'currency'               => ['type' => 'string'],
                    'offer_type'             => ['type' => 'string', 'enum' => ['sale','rent']],
                    'agent_id'               => ['type' => 'integer'],
                    'listing_type'           => ['type' => 'string'],
                    'created_by'             => ['type' => 'integer'],
                    'contract_type_id'       => ['type' => 'integer'],
                    'developer_id'            => ['type' => 'integer'],
                    'heating_type_id'        => ['type' => 'integer'],
                    'parking_type_id'        => ['type' => 'integer'],

                    // boolean
                    'has_garden'             => ['type' => 'boolean'],
                    'has_parking'            => ['type' => 'boolean'],
                    'is_mortgage_available'  => ['type' => 'boolean'],
                    'is_from_developer'      => ['type' => 'boolean'],
                    'is_business_owner'      => ['type' => 'boolean'],
                    'is_full_apartment'      => ['type' => 'boolean'],
                    'is_for_aura'             => ['type' => 'boolean'],

                    // диапазоны
                    'priceFrom'        => ['type' => 'number'],
                    'priceTo'          => ['type' => 'number'],
                    'roomsFrom'        => ['type' => 'number'],
                    'roomsTo'          => ['type' => 'number'],
                    'total_areaFrom'   => ['type' => 'number'],
                    'total_areaTo'     => ['type' => 'number'],
                    'living_areaFrom'  => ['type' => 'number'],
                    'living_areaTo'    => ['type' => 'number'],
                    'floorFrom'        => ['type' => 'number'],
                    'floorTo'          => ['type' => 'number'],
                    'total_floorsFrom' => ['type' => 'number'],
                    'total_floorsTo'   => ['type' => 'number'],
                    'year_builtFrom'   => ['type' => 'number'],
                    'year_builtTo'     => ['type' => 'number'],

                    // даты
                    'date_from'        => ['type' => 'string'],
                    'date_to'          => ['type' => 'string'],

                    // служебные
                    'limit'            => ['type' => 'integer', 'default' => 6],
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

        if (!empty($res1['error'])) {
            $msg = "Сервис ИИ сейчас недоступен. Попробуйте позже, пожалуйста.";
            $this->storeMessage($session->id, 'assistant', $msg);
            return [
                'session_id' => $session->session_uuid,
                'answer'     => $msg,
                'items'      => [],
                'locale'     => $locale,
            ];
        }

        $toolCalls     = $this->extractToolCalls($res1);
        $assistantText = $this->extractAssistantText($res1);

        if (!empty($toolCalls)) {
            $call = $toolCalls[0];

            if (($call['name'] ?? '') === 'search_properties') {
                $args  = json_decode($call['arguments'] ?? '{}', true) ?: [];

                // === NORMALIZE FILTERS FOR PropertyRepository ===
                // price range
                if (array_key_exists('priceFrom', $args)) {
                    $args['price_min'] = is_numeric($args['priceFrom']) ? (float)$args['priceFrom'] : null;
                }
                if (array_key_exists('priceTo', $args)) {
                    $args['price_max'] = is_numeric($args['priceTo']) ? (float)$args['priceTo'] : null;
                }
                // rooms range: поддержка "1-2 комнатные"
                if (array_key_exists('roomsFrom', $args) && array_key_exists('roomsTo', $args)) {
                    $args['rooms_min'] = (int)$args['roomsFrom'];
                    $args['rooms_max'] = (int)$args['roomsTo'];
                } elseif (array_key_exists('roomsFrom', $args)) {
                    $args['rooms'] = (int)$args['roomsFrom'];
                } elseif (array_key_exists('roomsTo', $args)) {
                    $args['rooms'] = (int)$args['roomsTo'];
                }

                // --- Normalize districts to strict DB values (safety net)
                if (!empty($args['districts']) && is_array($args['districts'])) {
                    $allowedDistricts = ['Сино', 'И Сомони', 'Шохмансур', 'Фирдавси'];

                    $normalized = [];
                    foreach ($args['districts'] as $input) {
                        $inputLower = mb_strtolower($input, 'UTF-8');
                        foreach ($allowedDistricts as $allowed) {
                            if (mb_strtolower($allowed, 'UTF-8') === $inputLower) {
                                $normalized[] = $allowed;
                                continue 2;
                            }
                        }
                    }

                    if (!empty($normalized)) {
                        $args['districts'] = array_values(array_unique($normalized));
                    }
                }

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

                // Добавляем в диалог сообщение для модели (assistant),
                // чтобы она на основе этих данных дала короткий ответ пользователю.
                $messages[] = [
                    'role'    => 'assistant',
                    'content' =>
                        "Here are the search results in JSON format. ".
                        "Use them to answer the user concisely in the user's language. ".
                        "If results are empty, politely ask clarifying questions.\n".
                        json_encode($resultsEnvelope, JSON_UNESCAPED_UNICODE),
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
            $base = env('OPENAI_BASE', 'https://api.openai.com');
            $url  = rtrim($base, '/') . '/v1/responses';

            // без ключей/PII
            $safeHeaders = [
                'X-Relay-Key' => env('RELAY_SHARED_KEY') ? '***set***' : '***empty***',
            ];
            $payloadPreview = mb_substr(json_encode($payload, JSON_UNESCAPED_UNICODE), 0, 2000);

            \Log::info('[OpenAI][REQ]', [
                'url'     => $url,
                'headers' => $safeHeaders,
                'payload_preview' => $payloadPreview,
            ]);

            $start = microtime(true);

            $resp = Http::withHeaders([
                'X-Relay-Key' => env('RELAY_SHARED_KEY'),
            ])
                ->baseUrl($base)       // важно: без /v1
                ->timeout(60)
                ->post('/v1/responses', $payload);

            $ms = (int) ((microtime(true) - $start) * 1000);

            $status   = $resp->status();
            $headers  = $resp->headers(); // массив заголовков ответа
            $body     = $resp->body();

            // урежем тело, чтобы не превращать лог в простыню
            $bodyPreview = mb_substr($body ?? '', 0, 3000);

            \Log::info('[OpenAI][RESP]', [
                'status'  => $status,
                'ms'      => $ms,
                'headers' => $headers,
                'body_preview' => $bodyPreview,
            ]);

            if ($status < 200 || $status >= 300) {
                return [
                    'error' => [
                        'status'  => $status,
                        'message' => optional(json_decode($body, true))['error']['message'] ?? 'Unknown error',
                    ],
                    'raw' => $bodyPreview,
                ];
            }

            return json_decode($body, true) ?? ['raw' => $bodyPreview];

        } catch (\Throwable $e) {
            \Log::error('[OpenAI][EXCEPTION] '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
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
