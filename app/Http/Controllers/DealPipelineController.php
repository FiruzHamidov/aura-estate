<?php

namespace App\Http\Controllers;

use App\Models\DealPipeline;
use App\Models\DealStage;
use App\Models\User;
use App\Services\Crm\AuditLogger;
use App\Support\DealAccess;
use App\Support\DealPipelineAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DealPipelineController extends Controller
{
    public function __construct(
        private readonly DealPipelineAccess $pipelineAccess,
        private readonly DealAccess $dealAccess,
        private readonly AuditLogger $auditLogger
    ) {}

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        abort_unless($user, 401, 'Unauthenticated.');

        $user->loadMissing('role');

        return $user;
    }

    private function relations(): array
    {
        return [
            'branch',
            'stages',
            'defaultStage',
            'auditLogs.actor',
        ];
    }

    private function normalizePayload(array $data): array
    {
        if (! array_key_exists('slug', $data) && ! empty($data['name'])) {
            $data['slug'] = Str::slug($data['name'], '_');
        }

        if (($data['type'] ?? null) === DealPipeline::TYPE_PROPERTY_CONTROL) {
            $data['code'] = DealPipeline::CODE_PROPERTY_CONTROL;
        } elseif (empty($data['code'])) {
            $data['code'] = ! empty($data['slug'])
                ? $data['slug']
                : Str::slug((string) ($data['name'] ?? 'pipeline'), '_');
        }

        $data['type'] = $data['type'] ?? DealPipeline::TYPE_SALES;

        return $data;
    }

    private function resetDefaultPipeline(?int $branchId, int $ignorePipelineId): void
    {
        DealPipeline::query()
            ->where('is_default', true)
            ->where('branch_id', $branchId)
            ->whereKeyNot($ignorePipelineId)
            ->update(['is_default' => false]);
    }

    private function defaultStagesPayload(string $type = DealPipeline::TYPE_SALES): array
    {
        if ($type === DealPipeline::TYPE_PROPERTY_CONTROL) {
            return [
                ['name' => 'Новая', 'slug' => 'new', 'color' => '#64748b', 'sort_order' => 10, 'is_default' => true, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
                ['name' => 'На проверке', 'slug' => 'in_review', 'color' => '#2563eb', 'sort_order' => 20, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
                ['name' => 'Связались', 'slug' => 'contacted', 'color' => '#0891b2', 'sort_order' => 30, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
                ['name' => 'Ждём владельца', 'slug' => 'waiting_owner', 'color' => '#f59e0b', 'sort_order' => 40, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
                ['name' => 'Возврат в работу', 'slug' => 'reactivation_in_progress', 'color' => '#8b5cf6', 'sort_order' => 50, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
                ['name' => 'Реактивирован', 'slug' => 'reactivated', 'color' => '#16a34a', 'sort_order' => 60, 'is_default' => false, 'is_closed' => true, 'is_lost' => false, 'is_active' => true],
                ['name' => 'Продан владельцем', 'slug' => 'owner_sold_confirmed', 'color' => '#dc2626', 'sort_order' => 70, 'is_default' => false, 'is_closed' => true, 'is_lost' => true, 'is_active' => true],
                ['name' => 'Нет ответа', 'slug' => 'no_answer', 'color' => '#f97316', 'sort_order' => 80, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
                ['name' => 'Неактуально', 'slug' => 'not_relevant', 'color' => '#6b7280', 'sort_order' => 90, 'is_default' => false, 'is_closed' => true, 'is_lost' => true, 'is_active' => true],
                ['name' => 'Закрыто', 'slug' => 'closed', 'color' => '#111827', 'sort_order' => 100, 'is_default' => false, 'is_closed' => true, 'is_lost' => true, 'is_active' => true],
            ];
        }

        return [
            ['name' => 'Новая', 'slug' => 'new', 'color' => '#64748b', 'sort_order' => 10, 'is_default' => true, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
            ['name' => 'В работе', 'slug' => 'in_progress', 'color' => '#2563eb', 'sort_order' => 20, 'is_default' => false, 'is_closed' => false, 'is_lost' => false, 'is_active' => true],
            ['name' => 'Успешно закрыта', 'slug' => 'won', 'color' => '#16a34a', 'sort_order' => 30, 'is_default' => false, 'is_closed' => true, 'is_lost' => false, 'is_active' => true],
            ['name' => 'Потеряна', 'slug' => 'lost', 'color' => '#dc2626', 'sort_order' => 40, 'is_default' => false, 'is_closed' => true, 'is_lost' => true, 'is_active' => true],
        ];
    }

    private function ensureUniqueCode(array $data, ?DealPipeline $pipeline = null): void
    {
        $query = DealPipeline::query()
            ->where('branch_id', $data['branch_id'] ?? null)
            ->where('code', $data['code']);

        if ($pipeline) {
            $query->whereKeyNot($pipeline->id);
        }

        abort_if($query->exists(), 422, 'Pipeline code must be unique inside branch.');
    }

    private function createStages(DealPipeline $pipeline, array $stages): void
    {
        $createdDefault = false;

        foreach (array_values($stages) as $index => $stage) {
            $payload = [
                'name' => $stage['name'],
                'slug' => $stage['slug'] ?? Str::slug($stage['name'], '_'),
                'color' => $stage['color'] ?? null,
                'sort_order' => $stage['sort_order'] ?? (($index + 1) * 10),
                'is_default' => $createdDefault ? false : (bool) ($stage['is_default'] ?? ($index === 0)),
                'is_closed' => (bool) (($stage['is_lost'] ?? false) || ($stage['is_closed'] ?? false)),
                'is_lost' => (bool) ($stage['is_lost'] ?? false),
                'is_active' => array_key_exists('is_active', $stage) ? (bool) $stage['is_active'] : true,
                'meta' => $stage['meta'] ?? null,
            ];

            if ($payload['is_default']) {
                $createdDefault = true;
            }

            $pipeline->stages()->create($payload);
        }
    }

    public function index()
    {
        $authUser = $this->authUser();

        return response()->json(
            $this->pipelineAccess->visibleQuery($authUser)
                ->withCount(['stages', 'deals'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $authUser = $this->authUser();

        abort_unless($this->pipelineAccess->canManage($authUser), 403, 'Forbidden');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:crm_deal_pipelines,slug',
            'code' => 'nullable|string|max:255',
            'type' => ['nullable', Rule::in([DealPipeline::TYPE_SALES, DealPipeline::TYPE_PROPERTY_CONTROL])],
            'branch_id' => 'nullable|integer|exists:branches,id',
            'sort_order' => 'sometimes|integer|min:0',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'meta' => 'nullable|array',
            'stages' => 'sometimes|array|min:1',
            'stages.*.name' => 'required|string|max:255',
            'stages.*.slug' => 'nullable|string|max:255',
            'stages.*.color' => 'nullable|string|max:24',
            'stages.*.sort_order' => 'nullable|integer|min:0',
            'stages.*.is_default' => 'nullable|boolean',
            'stages.*.is_closed' => 'nullable|boolean',
            'stages.*.is_lost' => 'nullable|boolean',
            'stages.*.is_active' => 'nullable|boolean',
            'stages.*.meta' => 'nullable|array',
        ]);

        $validated = $this->normalizePayload($validated);
        $validated = $this->pipelineAccess->normalizeMutationData($validated, $authUser);
        $this->pipelineAccess->validateMutationData($authUser, $validated);
        $this->ensureUniqueCode($validated);

        $pipeline = DealPipeline::create(Arr::only($validated, [
            'name',
            'slug',
            'code',
            'type',
            'branch_id',
            'sort_order',
            'is_default',
            'is_active',
            'meta',
        ]));

        if ($pipeline->is_default) {
            $this->resetDefaultPipeline($pipeline->branch_id, $pipeline->id);
        }

        $this->createStages($pipeline, $validated['stages'] ?? $this->defaultStagesPayload($pipeline->type));

        $this->auditLogger->log(
            $pipeline,
            $authUser,
            'created',
            [],
            Arr::only($pipeline->getAttributes(), [
                'name',
                'slug',
                'code',
                'type',
                'branch_id',
                'sort_order',
                'is_default',
                'is_active',
            ]),
            'Deal pipeline created.'
        );

        return response()->json($pipeline->load($this->relations()), 201);
    }

    public function show(DealPipeline $dealPipeline)
    {
        $this->pipelineAccess->ensureVisible($this->authUser(), $dealPipeline);

        return response()->json(
            $dealPipeline->load($this->relations())
                ->loadCount(['stages', 'deals'])
        );
    }

    public function update(Request $request, DealPipeline $dealPipeline)
    {
        $authUser = $this->authUser();
        $this->pipelineAccess->ensureManageable($authUser, $dealPipeline);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('crm_deal_pipelines', 'slug')->ignore($dealPipeline->id)],
            'code' => 'sometimes|string|max:255',
            'type' => ['sometimes', Rule::in([DealPipeline::TYPE_SALES, DealPipeline::TYPE_PROPERTY_CONTROL])],
            'branch_id' => 'sometimes|nullable|integer|exists:branches,id',
            'sort_order' => 'sometimes|integer|min:0',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'meta' => 'sometimes|nullable|array',
        ]);

        $validated = $this->normalizePayload($validated);
        $validated = $this->pipelineAccess->normalizeMutationData($validated, $authUser);
        $this->pipelineAccess->validateMutationData($authUser, array_merge([
            'branch_id' => $dealPipeline->branch_id,
            'code' => $dealPipeline->code,
        ], $validated));
        $this->ensureUniqueCode(array_merge([
            'branch_id' => $dealPipeline->branch_id,
            'code' => $dealPipeline->code,
        ], $validated), $dealPipeline);

        $dealPipeline->fill($validated);
        $dirty = $dealPipeline->getDirty();

        if (! empty($dirty)) {
            $oldValues = Arr::only($dealPipeline->getOriginal(), array_keys($dirty));
            $dealPipeline->save();

            if (! empty($validated['is_default'])) {
                $this->resetDefaultPipeline($dealPipeline->branch_id, $dealPipeline->id);
            }

            $this->auditLogger->log(
                $dealPipeline,
                $authUser,
                'updated',
                $oldValues,
                Arr::only($dealPipeline->getAttributes(), array_keys($dirty)),
                'Deal pipeline updated.'
            );
        }

        return response()->json($dealPipeline->fresh($this->relations()));
    }

    public function destroy(DealPipeline $dealPipeline)
    {
        $authUser = $this->authUser();
        $this->pipelineAccess->ensureManageable($authUser, $dealPipeline);

        if ($dealPipeline->deals()->exists()) {
            return response()->json([
                'message' => 'Нельзя удалить воронку: в ней есть сделки.',
            ], 409);
        }

        $this->auditLogger->log(
            $dealPipeline,
            $authUser,
            'deleted',
            Arr::only($dealPipeline->getAttributes(), ['name', 'slug', 'branch_id']),
            [],
            'Deal pipeline deleted.'
        );

        $dealPipeline->delete();

        return response()->json(['message' => 'Deal pipeline deleted']);
    }

    public function board(Request $request, DealPipeline $dealPipeline)
    {
        $authUser = $this->authUser();
        $this->pipelineAccess->ensureVisible($authUser, $dealPipeline);

        $validated = $request->validate([
            'search' => 'nullable|string',
            'responsible_agent_id' => 'nullable|integer|exists:users,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'lead_id' => 'nullable|integer|exists:leads,id',
        ]);

        $dealsQuery = $this->dealAccess->visibleQuery($authUser)
            ->where('pipeline_id', $dealPipeline->id)
            ->orderBy('board_position')
            ->orderBy('id');

        if (! empty($validated['search'])) {
            $term = trim($validated['search']);
            $dealsQuery->where(function ($builder) use ($term) {
                $builder
                    ->where('title', 'like', '%'.$term.'%')
                    ->orWhereHas('client', fn ($query) => $query->where('full_name', 'like', '%'.$term.'%'))
                    ->orWhereHas('lead', fn ($query) => $query->where('full_name', 'like', '%'.$term.'%'));
            });
        }

        foreach (['responsible_agent_id', 'client_id', 'lead_id'] as $field) {
            if (! empty($validated[$field])) {
                $dealsQuery->where($field, $validated[$field]);
            }
        }

        $deals = $dealsQuery->get()->groupBy('stage_id');

        $stages = $dealPipeline->stages()->get()->map(function (DealStage $stage) use ($deals) {
            $stageDeals = $deals->get($stage->id, collect())->values();

            $stage->setRelation('deals', $stageDeals);
            $stage->setAttribute('deals_count', $stageDeals->count());

            return $stage;
        });

        $dealPipeline->setRelation('stages', $stages);

        return response()->json($dealPipeline->loadMissing('branch', 'defaultStage'));
    }
}
