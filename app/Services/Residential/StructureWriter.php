<?php

namespace App\Services\Residential;

use App\Models\NewBuilding;
use App\Models\User;
use App\Services\Crm\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StructureWriter
{
    public function __construct(private readonly ResidentialAccess $access, private readonly InventoryWriter $inventory, private readonly AuditLogger $audit) {}

    public function save(User $actor, NewBuilding $building, string $kind, array $input, ?int $id = null)
    {
        $this->access->ensureManage($actor, $building);

        return DB::transaction(function () use ($actor, $building, $kind, $input, $id) {
            $parent = NewBuilding::query()->lockForUpdate()->findOrFail($building->id);
            $this->access->ensureManage($actor, $parent);
            $relation = $this->relation($parent, $kind);
            $record = $id ? $relation->lockForUpdate()->findOrFail($id) : $relation->make();
            $rules = ['version' => [$id ? 'required' : 'sometimes', 'integer', 'min:1'], 'change_reason' => 'nullable|string|max:1000'];
            $rules += match ($kind) {
                'blocks' => [
                    'name' => ['required', 'string', 'max:255', Rule::unique('new_building_blocks')->where('new_building_id', $parent->id)->ignore($id)],
                    'code' => 'nullable|string|max:50', 'floors_from' => 'nullable|integer|between:0,200', 'floors_to' => 'nullable|integer|between:0,200',
                    'completion_at' => 'nullable|date', 'completion_precision' => 'sometimes|in:unknown,date,quarter,year',
                    'completion_year' => 'nullable|integer|between:1900,2200', 'completion_quarter' => 'nullable|integer|between:1,4',
                    'construction_stage_id' => 'nullable|integer|exists:construction_stages,id', 'sort_order' => 'sometimes|integer|min:0',
                ],
                'entrances' => [
                    'block_id' => ['required', 'integer', Rule::exists('new_building_blocks', 'id')->where('new_building_id', $parent->id)->whereNull('archived_at')],
                    'name' => ['required', 'string', 'max:100', Rule::unique('new_building_entrances')->where('block_id', $input['block_id'] ?? null)->ignore($id)],
                    'residential_floor_from' => 'required|integer|between:0,200', 'residential_floor_to' => 'required|integer|between:0,200|gte:residential_floor_from',
                    'technical_floors' => 'nullable|array|max:200', 'technical_floors.*' => 'integer|distinct|between:0,200', 'sort_order' => 'sometimes|integer|min:0',
                ],
                'layouts' => [
                    'code' => ['required', 'string', 'max:100', Rule::unique('unit_layouts')->where('new_building_id', $parent->id)->ignore($id)],
                    'rooms' => 'nullable|integer|between:0,20', 'typical_area' => 'nullable|numeric|gt:0|max:99999999.99', 'alt' => 'nullable|string|max:255',
                ],
                'floor-plans' => [
                    'block_id' => ['required', 'integer', Rule::exists('new_building_blocks', 'id')->where('new_building_id', $parent->id)->whereNull('archived_at')],
                    'entrance_id' => ['required', 'integer', Rule::exists('new_building_entrances', 'id')->where('new_building_id', $parent->id)->where('block_id', $input['block_id'] ?? null)],
                    'floor_from' => 'required|integer|between:0,200', 'floor_to' => 'required|integer|between:0,200|gte:floor_from',
                    'alt' => 'nullable|string|max:255', 'unit_regions' => 'nullable|array|max:500',
                ],
                default => abort(404),
            };
            $data = Validator::make($input, $rules)->validate();
            if ($id) {
                $this->inventory->checkVersion($record, $data);
            }
            $old = $record->getAttributes();
            $reason = $data['change_reason'] ?? null;
            unset($data['version'], $data['change_reason']);
            $record->fill($kind === 'blocks' ? $this->inventory->normalizeCompletionInput($data) : $data);
            if ($kind === 'floor-plans') {
                $entrance = $parent->entrances()->findOrFail($record->entrance_id);
                if ($record->floor_from < $entrance->residential_floor_from || $record->floor_to > $entrance->residential_floor_to) {
                    throw ValidationException::withMessages(['floor_from' => 'План выходит за диапазон этажей подъезда.']);
                }
                if ($parent->floorPlans()->where('entrance_id', $record->entrance_id)->where('floor_from', '<=', $record->floor_to)->where('floor_to', '>=', $record->floor_from)->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                    throw ValidationException::withMessages(['floor_from' => 'Для этих этажей уже есть план. Измените существующий или выберите непересекающийся диапазон.']);
                }
                $allowed = $parent->units()->where('block_id', $record->block_id)->where('entrance_id', $record->entrance_id)->whereBetween('floor', [$record->floor_from, $record->floor_to])->pluck('id')->map(fn ($id) => (int) $id)->all();
                $record->unit_regions = app(PlanRegions::class)->validate($record->unit_regions ?? [], 'unit_id', $allowed);
            }
            if ($kind === 'blocks') {
                if ($record->floors_from !== null && $record->floors_to !== null && $record->floors_from > $record->floors_to) {
                    throw ValidationException::withMessages(['floors_to' => 'Верхний этаж должен быть не ниже нижнего.']);
                }
                $this->inventory->validateCompletion($record->getAttributes());
                if ($id && $record->entrances()->where(fn ($q) => $q->when($record->floors_from !== null, fn ($q) => $q->where('residential_floor_from', '<', $record->floors_from))->when($record->floors_to !== null, fn ($q) => $q->orWhere('residential_floor_to', '>', $record->floors_to)))->exists() && ($record->floors_from !== null || $record->floors_to !== null)) {
                    throw ValidationException::withMessages(['floors_to' => 'В корпусе есть подъезды вне нового диапазона.']);
                }
            }
            if ($kind === 'entrances') {
                if ($id && $record->isDirty('block_id')) {
                    throw ValidationException::withMessages(['block_id' => 'Перенос подъезда требует отдельной операции.']);
                }
                $block = $parent->blocks()->findOrFail($record->block_id);
                if (($block->floors_from !== null && $record->residential_floor_from < $block->floors_from) || ($block->floors_to !== null && $record->residential_floor_to > $block->floors_to)) {
                    throw ValidationException::withMessages(['residential_floor_to' => 'Этажи выходят за границы корпуса.']);
                }
                if ($id && $record->units()->where(fn ($q) => $q->where('floor', '<', $record->residential_floor_from)->orWhere('floor', '>', $record->residential_floor_to)->orWhereIn('floor', $record->technical_floors ?? []))->exists()) {
                    throw ValidationException::withMessages(['residential_floor_to' => 'Новый диапазон исключает существующие квартиры.']);
                }
            }
            $record->version = $id ? $record->version + 1 : 1;
            $record->save();
            $this->reviewParent($parent, $actor);
            $this->audit->log($record, $actor, $id ? 'residential.updated' : 'residential.created', $old, $record->getAttributes(), $reason);

            return $record->refresh();
        }, 3);
    }

    public function remove(User $actor, NewBuilding $building, string $kind, int $id, array $input): void
    {
        $kind === 'blocks' ? $this->access->ensurePublish($actor, $building) : $this->access->ensureManage($actor, $building);
        DB::transaction(function () use ($actor, $building, $kind, $id, $input) {
            $parent = NewBuilding::query()->lockForUpdate()->findOrFail($building->id);
            $kind === 'blocks' ? $this->access->ensurePublish($actor, $parent) : $this->access->ensureManage($actor, $parent);
            $record = $this->relation($parent, $kind)->lockForUpdate()->findOrFail($id);
            $data = Validator::make($input, ['version' => 'required|integer|min:1', 'replacement_id' => 'nullable|integer|min:1', 'replacement_version' => 'nullable|integer|min:1', 'usage_token' => 'nullable|string|size:64', 'change_reason' => 'nullable|string|max:1000'])->validate();
            $this->inventory->checkVersion($record, $data);
            $old = $record->getAttributes();
            if ($kind === 'blocks') {
                // Archive the parent. The public query excludes its units without deleting any data.
                $record->update(['archived_at' => now(), 'version' => $record->version + 1]);
            } else {
                app(StructureReplacement::class)->transfer($actor, $parent, $kind, $record, $data);
                $record->delete();
            }
            $this->reviewParent($parent, $actor);
            $this->audit->log($record, $actor, 'residential.archived', $old, $kind === 'blocks' ? $record->getAttributes() : [], $input['change_reason'] ?? null);
        }, 3);
    }

    private function relation(NewBuilding $building, string $kind)
    {
        return match ($kind) {
            'blocks' => $building->blocks(), 'entrances' => $building->entrances(), 'layouts' => $building->layouts(), 'floor-plans' => $building->floorPlans(), default => abort(404)
        };
    }

    private function reviewParent(NewBuilding $parent, User $actor): void
    {
        if (! $this->access->canPublish($actor, $parent) && InventoryStatus::building($parent->getAttributes()) === 'published') {
            $this->inventory->building($actor, ['version' => $parent->version, 'publication_status' => 'pending', 'change_reason' => 'Изменена структура ЖК'], $parent);

            return;
        }
        // Import previews depend on the whole structure, even when publication stays unchanged.
        $old = $parent->getAttributes();
        $parent->version++;
        $parent->save();
        $this->audit->log($parent, $actor, 'residential.updated', $old, $parent->getAttributes(), 'Изменена структура ЖК');
    }
}
