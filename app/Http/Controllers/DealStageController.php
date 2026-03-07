<?php

namespace App\Http\Controllers;

use App\Models\DealPipeline;
use App\Models\DealStage;
use App\Models\User;
use App\Services\Crm\AuditLogger;
use App\Services\Crm\DealBoardService;
use App\Support\DealPipelineAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DealStageController extends Controller
{
    public function __construct(
        private readonly DealPipelineAccess $pipelineAccess,
        private readonly DealBoardService $boardService,
        private readonly AuditLogger $auditLogger
    ) {
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        abort_unless($user, 401, 'Unauthenticated.');

        $user->loadMissing('role');

        return $user;
    }

    private function normalizePayload(array $data): array
    {
        if (!array_key_exists('slug', $data) && !empty($data['name'])) {
            $data['slug'] = Str::slug($data['name'], '_');
        }

        return $data;
    }

    private function ensureSingleDefault(DealPipeline $pipeline, DealStage $currentStage): void
    {
        DealStage::query()
            ->where('pipeline_id', $pipeline->id)
            ->where('is_default', true)
            ->whereKeyNot($currentStage->id)
            ->update(['is_default' => false]);
    }

    public function store(Request $request, DealPipeline $dealPipeline)
    {
        $authUser = $this->authUser();

        $this->pipelineAccess->ensureManageable($authUser, $dealPipeline);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('crm_deal_stages', 'slug')->where(fn ($query) => $query->where('pipeline_id', $dealPipeline->id))],
            'color' => 'nullable|string|max:24',
            'sort_order' => 'sometimes|integer|min:0',
            'is_default' => 'sometimes|boolean',
            'is_closed' => 'sometimes|boolean',
            'is_lost' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'meta' => 'nullable|array',
        ]);

        $validated = $this->normalizePayload($validated);
        if (!empty($validated['is_lost'])) {
            $validated['is_closed'] = true;
        }
        $validated['pipeline_id'] = $dealPipeline->id;
        $validated['sort_order'] ??= ((int) $dealPipeline->stages()->max('sort_order')) + 10;
        $validated['is_default'] = $dealPipeline->stages()->doesntExist()
            ? true
            : (bool) ($validated['is_default'] ?? false);

        $stage = DealStage::create($validated);

        if ($stage->is_default) {
            $this->ensureSingleDefault($dealPipeline, $stage);
        }

        $this->auditLogger->log(
            $stage,
            $authUser,
            'created',
            [],
            Arr::only($stage->getAttributes(), ['pipeline_id', 'name', 'slug', 'sort_order', 'is_default', 'is_closed', 'is_lost']),
            'Deal stage created.'
        );

        return response()->json($stage, 201);
    }

    public function show(DealStage $dealStage)
    {
        $this->pipelineAccess->ensureVisible($this->authUser(), $dealStage->pipeline()->firstOrFail());

        return response()->json($dealStage->load('pipeline'));
    }

    public function update(Request $request, DealStage $dealStage)
    {
        $authUser = $this->authUser();
        $dealStage->loadMissing('pipeline');
        $this->pipelineAccess->ensureManageable($authUser, $dealStage->pipeline);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('crm_deal_stages', 'slug')->where(fn ($query) => $query->where('pipeline_id', $dealStage->pipeline_id))->ignore($dealStage->id)],
            'color' => 'sometimes|nullable|string|max:24',
            'sort_order' => 'sometimes|integer|min:0',
            'is_default' => 'sometimes|boolean',
            'is_closed' => 'sometimes|boolean',
            'is_lost' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'meta' => 'sometimes|nullable|array',
        ]);

        $validated = $this->normalizePayload($validated);
        if (!empty($validated['is_lost'])) {
            $validated['is_closed'] = true;
        }

        if (
            array_key_exists('is_default', $validated)
            && $validated['is_default'] === false
            && $dealStage->is_default
            && !$dealStage->pipeline->stages()->whereKeyNot($dealStage->id)->where('is_default', true)->exists()
        ) {
            abort(422, 'Pipeline must have at least one default stage.');
        }

        $dealStage->fill($validated);
        $dirty = $dealStage->getDirty();

        if (!empty($dirty)) {
            $oldValues = Arr::only($dealStage->getOriginal(), array_keys($dirty));
            $dealStage->save();

            if (!empty($validated['is_default'])) {
                $this->ensureSingleDefault($dealStage->pipeline, $dealStage);
            }

            $this->auditLogger->log(
                $dealStage,
                $authUser,
                'updated',
                $oldValues,
                Arr::only($dealStage->getAttributes(), array_keys($dirty)),
                'Deal stage updated.'
            );
        }

        return response()->json($dealStage->fresh('pipeline'));
    }

    public function destroy(DealStage $dealStage)
    {
        $authUser = $this->authUser();
        $dealStage->loadMissing('pipeline');
        $this->pipelineAccess->ensureManageable($authUser, $dealStage->pipeline);

        if ($dealStage->deals()->exists()) {
            return response()->json([
                'message' => 'Нельзя удалить стадию: в ней есть сделки.',
            ], 409);
        }

        if ($dealStage->pipeline->stages()->count() <= 1) {
            return response()->json([
                'message' => 'В воронке должна остаться хотя бы одна стадия.',
            ], 409);
        }

        $wasDefault = $dealStage->is_default;

        $this->auditLogger->log(
            $dealStage,
            $authUser,
            'deleted',
            Arr::only($dealStage->getAttributes(), ['pipeline_id', 'name', 'slug', 'sort_order', 'is_default']),
            [],
            'Deal stage deleted.'
        );

        $dealStage->delete();

        if ($wasDefault) {
            $replacement = $dealStage->pipeline->stages()->first();

            if ($replacement) {
                $replacement->update(['is_default' => true]);
            }
        }

        return response()->json(['message' => 'Deal stage deleted']);
    }

    public function reorder(Request $request, DealPipeline $dealPipeline)
    {
        $authUser = $this->authUser();
        $this->pipelineAccess->ensureManageable($authUser, $dealPipeline);

        $validated = $request->validate([
            'stage_ids' => 'required|array|min:1',
            'stage_ids.*' => 'integer|exists:crm_deal_stages,id',
        ]);

        $existingIds = $dealPipeline->stages()->pluck('id')->values()->all();
        $orderedIds = array_map('intval', $validated['stage_ids']);

        sort($existingIds);
        $sortedOrderedIds = $orderedIds;
        sort($sortedOrderedIds);

        if ($existingIds !== $sortedOrderedIds) {
            abort(422, 'stage_ids must contain all pipeline stages.');
        }

        $oldOrder = $dealPipeline->stages()->pluck('sort_order', 'id')->all();

        $this->boardService->reorderStages($dealPipeline, $orderedIds);

        $newOrder = $dealPipeline->stages()->pluck('sort_order', 'id')->all();

        $this->auditLogger->log(
            $dealPipeline,
            $authUser,
            'stages_reordered',
            $oldOrder,
            $newOrder,
            'Deal stages reordered.'
        );

        return response()->json(
            $dealPipeline->stages()->get()
        );
    }
}
