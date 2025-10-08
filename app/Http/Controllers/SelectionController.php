<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use App\Services\Bitrix24Client;
use App\Models\Selection;

class SelectionController extends Controller
{
    // Список моих подборок (для личного кабинета агента)
    public function index(Request $request)
    {
        $q = Selection::query()
            ->when($request->filled('deal_id'), fn($qq) => $qq->where('deal_id', $request->integer('deal_id')))
            ->when($request->filled('status'), fn($qq) => $qq->where('status', $request->string('status')));

        // Если используете Sanctum и роли — можно ограничить по created_by
        if (Auth::check()) {
            $q->where('created_by', Auth::id());
        }

        return $q->latest()->paginate((int) $request->input('per_page', 20));
    }

    // Создание подборки + (опционально) лог в Bitrix
    public function store(Request $request, Bitrix24Client $b24)
    {
        $validated = $request->validate([
            'title'        => 'nullable|string|max:255',
            'property_ids' => 'required|array|min:1',
            'property_ids.*' => 'integer|exists:properties,id',
            'channel'      => ['nullable', Rule::in(['whatsapp','telegram','sms','email'])],
            'note'         => 'nullable|string',
            'deal_id'      => 'nullable|integer',
            'contact_id'   => 'nullable|integer',
            'expires_at'   => 'nullable|date',
            'sync_to_b24'  => 'sometimes|boolean', // флаг: писать комментарий в таймлайн B24
        ]);

        // Генерация уникального hash и URL (подставьте свой домен/роут)
        $hash = Str::lower(Str::random(32));
        $url  = 'https://aura.tj/s/'.$hash;

        $selection = new Selection();
        $selection->created_by     = Auth::id();
        $selection->deal_id        = $validated['deal_id'] ?? null;
        $selection->contact_id     = $validated['contact_id'] ?? null;
        $selection->title          = $validated['title'] ?? null;
        $selection->property_ids   = $validated['property_ids'];
        $selection->channel        = $validated['channel'] ?? null;
        $selection->note           = $validated['note'] ?? null;
        $selection->selection_hash = $hash;
        $selection->selection_url  = $url;
        $selection->expires_at     = isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null;
        $selection->status         = 'draft';
        $selection->save();

        // Если требуется — отправим событие в таймлайн сделки (B24)
        $b24res = null;
        if ($request->boolean('sync_to_b24') && !empty($selection->deal_id)) {
            try {
                $comment = "Отправлена подборка (".count($selection->property_ids)."):\n".$selection->selection_url;
                $b24res = $b24->timelineCommentAdd([
                    'fields' => [
                        'ENTITY_TYPE' => 'deal',
                        'ENTITY_ID'   => (int) $selection->deal_id,
                        'COMMENT'     => $comment,
                    ],
                ]);
                $selection->status = 'sent';
                $selection->sent_at = now();
                $selection->save();
            } catch (\Throwable $e) {
                $b24res = ['error' => $e->getMessage()];
            }
        }

        return response()->json([
            'selection' => $selection,
            'bitrix'    => $b24res,
        ], 201);
    }

    // Детали (для агента в кабинете)
    public function show($id)
    {
        $sel = Selection::findOrFail($id);
        if (Auth::check() && $sel->created_by && $sel->created_by !== Auth::id()) {
            abort(403);
        }
        return $sel;
    }

    // Трекинг событий лендинга подборки (viewed/opened/requested_showing)
    public function event($id, Request $request, Bitrix24Client $b24)
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['viewed','opened','requested_showing'])],
            'payload' => 'nullable|array',
        ]);

        $sel = Selection::findOrFail($id);

        // простая логика обновления статусов
        if ($validated['type'] === 'viewed' && !$sel->viewed_at) {
            $sel->viewed_at = now();
            $sel->status = 'viewed';
        }

        // можно накапливать в meta последние события
        $meta = $sel->meta ? (array)$sel->meta : [];
        $meta['events'][] = [
            'type' => $validated['type'],
            'payload' => $validated['payload'] ?? null,
            'ts' => now()->toIso8601String(),
        ];
        $sel->meta = $meta;
        $sel->save();

        // при необходимости — отправим отметку в таймлайн сделки
        if (!empty($sel->deal_id)) {
            try {
                $text = match ($validated['type']) {
                    'viewed' => 'Клиент посмотрел подборку',
                    'opened' => 'Клиент открыл объект из подборки',
                    'requested_showing' => 'Клиент запросил показ из подборки',
                    default => 'Событие по подборке',
                };
                $b24->timelineCommentAdd([
                    'fields' => [
                        'ENTITY_TYPE' => 'deal',
                        'ENTITY_ID'   => (int) $sel->deal_id,
                        'COMMENT'     => $text . ' (ID подб.: '.$sel->id.')',
                    ],
                ]);
            } catch (\Throwable $e) {
                // глушим, не критично
            }
        }

        return response()->json(['ok' => true]);
    }

    // Публичный просмотр по hash (если нужен публичный JSON — опционально)
    public function publicShow($hash)
    {
        $sel = Selection::where('selection_hash', $hash)->firstOrFail();

        // проверка срока
        if ($sel->expires_at && now()->greaterThan($sel->expires_at)) {
            abort(410, 'Selection expired');
        }
        return [
            'title'        => $sel->title,
            'property_ids' => $sel->property_ids,
            'note'         => $sel->note,
            'status'       => $sel->status,
        ];
    }
}
