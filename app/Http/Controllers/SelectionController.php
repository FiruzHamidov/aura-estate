<?php

namespace App\Http\Controllers;

use App\Models\Selection;
use App\Models\Property;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SelectionController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications
    ) {}

    // Список моих подборок (для личного кабинета агента)
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'status' => 'nullable|string|max:24',
            'deal_id' => 'nullable|integer|min:1',
            'branch_group_id' => 'nullable|integer|min:1',
        ]);
        abort_if(Auth::user()?->hasRole('rop') && ! empty($validated['deal_id']), 422, 'LEGACY_SELECTION_LINK_NOT_SUPPORTED');
        $q = Selection::query()
            ->when(! empty($validated['branch_group_id']), fn ($query) => $query->where('branch_group_id', $validated['branch_group_id']))
            ->when($request->filled('deal_id'), fn ($qq) => $qq->where('deal_id', $request->integer('deal_id')))
            ->when($request->filled('status'), fn ($qq) => $qq->where('status', $request->string('status')));

        // Если используете Sanctum и роли — можно ограничить по created_by
        if (Auth::user()?->hasRole('rop')) {
            app(\App\Support\RopGroupAccess::class)->scope($q, Auth::user(), 'selections.branch_group_id');
        } elseif (Auth::check()) {
            $q->where('created_by', Auth::id());
        }

        $page = $q->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 20))->withQueryString();
        if (Auth::user()?->hasRole('rop')) {
            app(\App\Services\GroupAccess\GroupDataProjection::class)->prepareSelections($page->getCollection(), Auth::user());
        }

        return $page;
    }

    // Создание подборки во внутренней CRM Aura
    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'title' => 'nullable|string|max:255',
            'property_ids' => 'required|array|min:1',
            'property_ids.*' => 'integer|exists:properties,id',
            'channel' => ['nullable', Rule::in(['whatsapp', 'telegram', 'sms', 'email'])],
            'note' => 'nullable|string',
            'deal_id' => 'nullable|integer',
            'contact_id' => 'nullable|integer',
            'expires_at' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($validated) {
            $properties = Property::query()->whereKey($validated['property_ids'])->get();
            [$actor, $properties] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquireMany(
                Auth::user(), $properties->all(), [], [$validated['branch_group_id'] ?? null]
            );
            $access = app(\App\Support\RopGroupAccess::class);
            if ($access->applies($actor)) {
                abort_if(! empty($validated['deal_id']) || ! empty($validated['contact_id']), 422, 'LEGACY_SELECTION_LINK_NOT_SUPPORTED');
                $groupId = $access->creationGroup($actor, $validated['branch_group_id'] ?? null);
                foreach ($properties as $property) $access->ensureVisible($actor, $property);
            } else {
                $groupId = $actor->branch_group_id;
            }
            // Генерация уникального hash и URL (подставьте свой домен/роут)
            $hash = Str::lower(Str::random(32));
            $url = 'https://aura.tj/s/'.$hash;

            $selection = new Selection;
            $selection->created_by = $actor->id;
            $selection->branch_group_id = $groupId;
            $selection->deal_id = $validated['deal_id'] ?? null;
            $selection->contact_id = $validated['contact_id'] ?? null;
            $selection->title = $validated['title'] ?? null;
            $selection->property_ids = $validated['property_ids'];
            $selection->channel = $validated['channel'] ?? null;
            $selection->note = $validated['note'] ?? null;
            $selection->selection_hash = $hash;
            $selection->selection_url = $url;
            $selection->expires_at = isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null;
            $selection->status = 'draft';
            $selection->save();

            return response()->json([
                'selection' => $selection,
            ], 201);
        });
    }

    // Детали (для агента в кабинете)
    public function show($id)
    {
        $sel = Selection::findOrFail($id);
        if (Auth::user()?->hasRole('rop')) {
            app(\App\Support\RopGroupAccess::class)->ensureVisible(Auth::user(), $sel);
        } elseif (Auth::check() && $sel->created_by && $sel->created_by !== Auth::id()) {
            abort(403);
        }

        return $sel;
    }

    // Трекинг событий лендинга подборки (viewed/opened/requested_showing)
    public function publicEvent(string $hash, Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['viewed', 'opened', 'requested_showing'])],
            'payload' => 'nullable|array:property_id',
            'payload.property_id' => 'required_unless:type,viewed|integer',
        ]);

        return DB::transaction(function () use ($hash, $validated) {
            $sel = Selection::where('selection_hash', $hash)->lockForUpdate()->firstOrFail();
            abort_if($sel->expires_at && now()->greaterThan($sel->expires_at), 410, 'Selection expired');
            if (isset($validated['payload']['property_id'])) {
                abort_unless(in_array((int) $validated['payload']['property_id'], array_map('intval', $sel->property_ids), true), 422, 'Объект не входит в подборку.');
                if (Schema::hasTable('properties')) {
                    Property::query()->publicSearchable()->findOrFail((int) $validated['payload']['property_id']);
                }
            }

            // простая логика обновления статусов
            if ($validated['type'] === 'viewed' && ! $sel->viewed_at) {
                $sel->viewed_at = now();
                $sel->status = 'viewed';
            }

            // можно накапливать в meta последние события
            $meta = $sel->meta ? (array) $sel->meta : [];
            $meta['events'][] = [
                'type' => $validated['type'],
                'payload' => $validated['payload'] ?? null,
                'ts' => now()->toIso8601String(),
            ];
            $meta['events'] = array_slice($meta['events'], -100);
            $sel->meta = $meta;
            $sel->save();

            $this->notifications->handleSelectionEvent($sel, $validated['type'], $validated['payload'] ?? null);

            return response()->json(['ok' => true]);
        });
    }

    // Публичный просмотр по hash (если нужен публичный JSON — опционально)
    public function publicShow($hash)
    {
        $sel = Selection::where('selection_hash', $hash)->firstOrFail();

        // проверка срока
        if ($sel->expires_at && now()->greaterThan($sel->expires_at)) {
            abort(410, 'Selection expired');
        }

        $publicPropertyIds = Schema::hasTable('properties')
            ? Property::query()->publicSearchable()->whereKey((array) $sel->property_ids)->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
            : array_map('intval', (array) $sel->property_ids);

        return [
            'id' => $sel->id,
            'title' => $sel->title,
            'property_ids' => $publicPropertyIds,
            'note' => $sel->note,
            'status' => $sel->status,
        ];
    }
}
