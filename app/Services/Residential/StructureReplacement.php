<?php

namespace App\Services\Residential;

use App\Models\NewBuilding;
use App\Models\User;
use App\Services\Crm\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Atomic reference replacement; individual lot measurements and prices are never copied. */
final class StructureReplacement
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function usage(NewBuilding $building, string $kind, Model $record): array
    {
        $units = $kind === 'floor-plans' ? [] : $record->units()->orderBy('id')->get(['id', 'version'])->toArray();
        $plans = $kind === 'entrances' ? $building->floorPlans()->where('entrance_id', $record->id)->orderBy('id')->get(['id', 'version'])->toArray() : [];

        return ['version' => $record->version, 'units' => count($units), 'floor_plans' => count($plans),
            'usage_token' => hash('sha256', json_encode([$kind, $record->id, $record->version, $units, $plans], JSON_THROW_ON_ERROR))];
    }

    public function transfer(User $actor, NewBuilding $building, string $kind, Model $source, array $input): void
    {
        $usage = $this->usage($building, $kind, $source);
        if (isset($input['usage_token'])) {
            abort_unless(hash_equals($usage['usage_token'], $input['usage_token']), 409, 'Связанные записи изменились. Повторно проверьте количество и подтвердите замену.');
        }
        if ($usage['units'] + $usage['floor_plans'] === 0) {
            return;
        }
        abort_unless(isset($input['usage_token'], $input['replacement_id'], $input['replacement_version']), 409, 'Есть связанные данные. Выберите замену и подтвердите перенос.');
        abort_if((int) $input['replacement_id'] === (int) $source->id, 422, 'Нельзя заменить запись самой собой.');
        $relation = $kind === 'entrances' ? $building->entrances() : $building->layouts();
        $target = $relation->lockForUpdate()->find($input['replacement_id']);
        if (! $target || ($kind === 'entrances' && (int) $target->block_id !== (int) $source->block_id)) {
            throw ValidationException::withMessages(['replacement_id' => 'Замена должна быть в том же ЖК, а подъезд — в том же корпусе.']);
        }
        abort_if((int) $target->version !== (int) $input['replacement_version'], 409, 'Запись замены изменена. Выберите её заново.');
        $units = $source->units()->lockForUpdate()->get();
        $plans = $kind === 'entrances' ? $building->floorPlans()->where('entrance_id', $source->id)->lockForUpdate()->get() : collect();
        if ($kind === 'entrances') {
            foreach ($units as $unit) {
                if ($unit->floor !== null && ($unit->floor < $target->residential_floor_from || $unit->floor > $target->residential_floor_to || in_array((int) $unit->floor, $target->technical_floors ?? [], true))) {
                    abort(409, 'В подъезде замены нет нужного жилого этажа. Перенос не выполнен.');
                }
                if (($unit->number !== null && $target->units()->where('number', $unit->number)->exists()) || ($unit->position_on_floor !== null && $target->units()->where('floor', $unit->floor)->where('position_on_floor', $unit->position_on_floor)->exists())) {
                    abort(409, 'Номер или позиция квартиры заняты в подъезде замены. Перенос не выполнен.');
                }
            }
            foreach ($plans as $plan) {
                abort_if($plan->floor_from < $target->residential_floor_from || $plan->floor_to > $target->residential_floor_to || $building->floorPlans()->where('entrance_id', $target->id)->where('floor_from', '<=', $plan->floor_to)->where('floor_to', '>=', $plan->floor_from)->exists(), 409, 'Планы этажей несовместимы с подъездом замены. Перенос не выполнен.');
            }
        }
        $field = $kind === 'entrances' ? 'entrance_id' : 'layout_id';
        foreach ($units->concat($plans) as $record) {
            $old = $record->getAttributes();
            $record->update([$field => $target->id, 'version' => $record->version + 1]);
            $this->audit->log($record, $actor, 'residential.reference_replaced', $old, $record->getAttributes(), $input['change_reason'] ?? 'Замена связанной записи перед удалением');
        }
    }
}
