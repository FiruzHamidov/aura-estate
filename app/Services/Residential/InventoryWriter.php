<?php

namespace App\Services\Residential;

use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use App\Models\NewBuildingEntrance;
use App\Models\UnitLayout;
use App\Models\User;
use App\Services\Crm\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InventoryWriter
{
    public function __construct(private readonly ResidentialAccess $access, private readonly UnitPrice $prices, private readonly AuditLogger $audit) {}

    public function building(User $actor, array $input, ?NewBuilding $building = null): NewBuilding
    {
        abort_unless($this->access->canCreate($actor), 403);
        $data = Validator::make($input, InventoryRules::building($building !== null))->validate();

        return DB::transaction(function () use ($actor, $data, $building) {
            $record = $building ? NewBuilding::query()->lockForUpdate()->findOrFail($building->id) : new NewBuilding;
            if ($record->exists) {
                $this->access->ensureManage($actor, $record);
                $this->checkVersion($record, $data);
            }
            $old = $record->getAttributes();
            $oldFeatures = array_key_exists('features', $data) && $record->exists ? $record->features()->orderBy('features.id')->pluck('features.id')->all() : [];
            $values = $data;
            unset($values['version'], $values['features'], $values['change_reason']);
            if (! $record->exists) {
                $values['title'] ??= 'Новый жилой комплекс';
                $values['created_by'] = $actor->id;
                $values['branch_id'] ??= $actor->branch_id;
                $values['responsible_agent_id'] ??= $actor->id;
            }
            if (! $this->access->global($actor) && array_key_exists('branch_id', $values) && (int) $values['branch_id'] !== (int) $actor->branch_id) {
                throw ValidationException::withMessages(['branch_id' => 'ЖК должен относиться к вашему филиалу.']);
            }
            $publication = $values['publication_status'] ?? (isset($values['moderation_status']) ? InventoryStatus::building(['moderation_status' => $values['moderation_status']]) : InventoryStatus::building($old));
            InventoryStatus::validateAliases($values, $publication);
            $moderator = $this->access->global($actor) || in_array($this->access->role($actor), ResidentialAccess::BRANCH_ROLES, true);
            if (! $moderator && in_array($publication, ['published', 'rejected', 'archived'], true)) {
                abort_if(isset($values['publication_status']) || isset($values['moderation_status']), 403, 'Отправьте ЖК на модерацию.');
                $publication = 'pending';
            }
            $record->fill($this->normalizeCompletionInput($values));
            $this->validateContact($record, $actor);
            $this->validateCompletion($record->getAttributes());
            if ($publication === 'published') {
                $errors = [];
                foreach (['title', 'location_id', 'address', 'responsible_agent_id', 'data_verified_at'] as $field) {
                    if (empty($record->$field)) {
                        $errors[$field] = 'Заполните поле перед публикацией.';
                    }
                }
                if ($errors) {
                    throw ValidationException::withMessages($errors);
                }
                $record->published_at ??= now();
            }
            $record->publication_status = $publication;
            $record->moderation_status = InventoryStatus::legacyBuilding($publication);
            $this->snapshot($record, $old, 'new_building');
            $record->version = $record->exists ? $record->version + 1 : 1;
            $record->save();
            if (array_key_exists('features', $data)) {
                $record->features()->sync($data['features']);
            }
            $after = $record->getAttributes();
            if (array_key_exists('features', $data)) {
                $old['features'] = $oldFeatures;
                $after['features'] = $record->features()->orderBy('features.id')->pluck('features.id')->all();
            }
            $this->audit->log($record, $actor, $record->wasRecentlyCreated ? 'residential.created' : 'residential.updated', $old, $after, $data['change_reason'] ?? null);

            return $record->refresh();
        }, 3);
    }

    public function unit(User $actor, NewBuilding $building, array $input, ?DeveloperUnit $unit = null): DeveloperUnit
    {
        $this->access->ensureManage($actor, $building);
        $data = Validator::make($input, InventoryRules::unit($unit !== null))->validate();

        return DB::transaction(function () use ($actor, $building, $data, $unit) {
            // Serializes nested uniqueness checks and changes against parent reassignment/archive.
            $parent = NewBuilding::query()->lockForUpdate()->findOrFail($building->id);
            $this->access->ensureManage($actor, $parent);
            $record = $unit ? $parent->units()->lockForUpdate()->findOrFail($unit->id) : new DeveloperUnit;
            if ($record->exists) {
                $this->checkVersion($record, $data);
            }
            $old = $record->getAttributes();
            $values = $data;
            unset($values['version'], $values['change_reason']);
            if (isset($values['new_building_id']) && (int) $values['new_building_id'] !== (int) $parent->id) {
                throw ValidationException::withMessages(['new_building_id' => 'Нельзя перенести квартиру подменой ЖК.']);
            }
            [$oldPublication, $oldAvailability] = InventoryStatus::unit($old);
            $publication = $values['publication_status'] ?? ($record->exists ? $oldPublication : 'draft');
            $availability = $values['availability_status'] ?? ($record->exists ? $oldAvailability : 'available');
            if (isset($values['moderation_status']) && ! isset($values['publication_status']) && ! isset($values['availability_status'])) {
                $legacy = $values['moderation_status'];
                if (in_array($legacy, ['available', 'approved', 'reserved', 'sold'], true)) {
                    $availability = $legacy === 'approved' ? 'available' : $legacy;
                    // Existing legacy availability buttons never grant publication to an author.
                    if (! $record->exists) {
                        $publication = $this->access->canPublish($actor, $parent) ? 'published' : 'pending';
                    }
                } else {
                    $publication = InventoryStatus::building(['moderation_status' => $legacy]);
                }
            }
            $moderator = $this->access->canPublish($actor, $parent);
            InventoryStatus::validateAliases($values, $publication, $availability);
            if (! $moderator && in_array($publication, ['published', 'rejected', 'archived'], true)) {
                abort_if(isset($values['publication_status']), 403, 'Отправьте квартиру на модерацию.');
                $contentFields = array_diff(array_keys($values), ['availability_status', 'moderation_status', 'is_available', 'data_verified_at', 'new_building_id']);
                if ($contentFields) {
                    $publication = 'pending';
                }
            }
            $values['new_building_id'] = $parent->id;
            $values['name'] ??= $record->name ?? 'Квартира';
            if (array_key_exists('rooms', $values)) {
                if (isset($values['bedrooms']) && (int) $values['bedrooms'] !== (int) ($values['rooms'] ?? 0)) {
                    throw ValidationException::withMessages(['rooms' => 'Комнатность противоречит прежнему полю bedrooms.']);
                }
                $values['bedrooms'] = $values['rooms'] ?? 0;
            } elseif (isset($values['bedrooms']) && $values['bedrooms'] > 0) {
                $values['rooms'] = $values['bedrooms'];
            }
            if (array_key_exists('bedrooms', $values) && $values['bedrooms'] === null) {
                $values['bedrooms'] = 0;
                $values['rooms'] = null;
            }
            $record->fill($values);
            $record->publication_status = $publication;
            $record->availability_status = $availability;
            $this->validateUnitLinks($record, $parent);
            foreach (['living_area', 'kitchen_area'] as $field) {
                if ($record->$field !== null && (float) $record->$field > (float) $record->area) {
                    throw ValidationException::withMessages([$field => 'Значение превышает общую площадь.']);
                }
            }
            if (! $record->exists && ! isset($values['area'])) {
                throw ValidationException::withMessages(['area' => 'Укажите площадь квартиры.']);
            }
            // Recalculate only when price inputs change; do not silently normalize old mismatches.
            if (! $record->exists || array_intersect(array_keys($values), ['total_price', 'price_per_sqm', 'pricing_basis', 'price_on_request', 'area'])) {
                $record->pricing_basis ??= isset($values['total_price']) ? 'total' : (isset($values['price_per_sqm']) ? 'per_sqm' : 'total');
                if (! $record->exists && ! isset($values['total_price']) && ! isset($values['price_per_sqm']) && ! array_key_exists('price_on_request', $values)) {
                    if (isset($values['publication_status']) || isset($values['availability_status']) || isset($values['pricing_basis'])) {
                        throw ValidationException::withMessages(['price_on_request' => 'Укажите цену или явно выберите «По запросу».']);
                    }
                    $record->price_on_request = true;
                }
                $record->fill($this->prices->calculate($record->getAttributes()));
            }
            if ($publication === 'published' && (InventoryStatus::rooms($record->getAttributes()) === null || (float) $record->area <= 0)) {
                throw ValidationException::withMessages(['rooms' => 'Для публикации подтвердите комнатность и площадь.']);
            }
            $record->fill(InventoryStatus::legacyUnit($publication, $availability));
            $this->snapshot($record, $old, 'developer_unit');
            $record->version = $record->exists ? $record->version + 1 : 1;
            $record->save();
            $this->audit->log($record, $actor, $old ? 'residential.updated' : 'residential.created', $old, $record->getAttributes(), $data['change_reason'] ?? null);

            return $record->refresh();
        }, 3);
    }

    public function checkVersion(\Illuminate\Database\Eloquent\Model $record, array $data): void
    {
        abort_if((int) ($data['version'] ?? 0) !== (int) $record->version, 409, 'Данные изменились. Обновите запись перед сохранением.');
    }

    private function validateContact(NewBuilding $record, User $actor): void
    {
        if (! $record->responsible_agent_id) {
            return;
        }
        $contact = User::query()->find($record->responsible_agent_id);
        if (! $contact || ! $this->access->canCreate($contact) || (int) $record->branch_id !== (int) $contact->branch_id) {
            throw ValidationException::withMessages(['responsible_agent_id' => 'Выберите действующего сотрудника Aura в филиале ЖК.']);
        }
        if (! $this->access->global($actor) && ! in_array($this->access->role($actor), ResidentialAccess::BRANCH_ROLES, true) && $record->isDirty('responsible_agent_id') && (int) $record->responsible_agent_id !== (int) $actor->id) {
            throw ValidationException::withMessages(['responsible_agent_id' => 'Назначение другого сотрудника доступно руководителю.']);
        }
    }

    public function normalizeCompletionInput(array $data): array
    {
        // Omitted precision belongs to a legacy/partial update: preserve existing dates.
        if (! array_key_exists('completion_precision', $data)) {
            return $data;
        }
        $retained = match ($data['completion_precision']) {
            'date' => ['completion_at'],
            'year' => ['completion_year'],
            'quarter' => ['completion_year', 'completion_quarter'],
            default => [],
        };
        foreach (['completion_at', 'completion_year', 'completion_quarter'] as $field) {
            if (! in_array($field, $retained, true)) {
                $data[$field] = null;
            }
        }

        return $data;
    }

    public function validateCompletion(array $data): void
    {
        $precision = $data['completion_precision'] ?? 'unknown';
        $required = match ($precision) {
            'date' => ['completion_at'], 'year' => ['completion_year'], 'quarter' => ['completion_year', 'completion_quarter'], default => []
        };
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw ValidationException::withMessages([$field => 'Уточните срок сдачи.']);
            }
        }
    }

    private function validateUnitLinks(DeveloperUnit $unit, NewBuilding $building): void
    {
        if ($unit->block_id && ! $building->blocks()->whereNull('archived_at')->whereKey($unit->block_id)->exists()) {
            throw ValidationException::withMessages(['block_id' => 'Корпус не принадлежит ЖК или архивирован.']);
        }
        if ($unit->layout_id && ! UnitLayout::query()->where('new_building_id', $building->id)->whereKey($unit->layout_id)->exists()) {
            throw ValidationException::withMessages(['layout_id' => 'Планировка не принадлежит ЖК.']);
        }
        if ($unit->entrance_id) {
            $entrance = NewBuildingEntrance::query()->where('new_building_id', $building->id)->where('block_id', $unit->block_id)->find($unit->entrance_id);
            if (! $entrance) {
                throw ValidationException::withMessages(['entrance_id' => 'Подъезд не принадлежит выбранному корпусу.']);
            }
            if ($unit->floor !== null && ($unit->floor < $entrance->residential_floor_from || $unit->floor > $entrance->residential_floor_to || in_array((int) $unit->floor, $entrance->technical_floors ?? [], true))) {
                throw ValidationException::withMessages(['floor' => 'Укажите жилой этаж этого подъезда.']);
            }
            $siblings = $building->units()->where('entrance_id', $entrance->id)->when($unit->exists, fn ($q) => $q->whereKeyNot($unit->id));
            if ($unit->number !== null && $unit->number !== '' && (clone $siblings)->where('number', $unit->number)->exists()) {
                throw ValidationException::withMessages(['number' => 'Номер уже занят в этом подъезде.']);
            }
            if ($unit->position_on_floor !== null && ($unit->floor === null || (clone $siblings)->where('floor', $unit->floor)->where('position_on_floor', $unit->position_on_floor)->exists())) {
                throw ValidationException::withMessages(['position_on_floor' => 'Укажите свободную позицию на жилом этаже.']);
            }
        } elseif ($unit->position_on_floor !== null || $unit->number !== null) {
            throw ValidationException::withMessages(['entrance_id' => 'Для номера и позиции выберите подъезд.']);
        }
        if ($unit->external_id !== null && $unit->external_id !== '' && $building->units()->where('external_id', $unit->external_id)->when($unit->exists, fn ($q) => $q->whereKeyNot($unit->id))->exists()) {
            throw ValidationException::withMessages(['external_id' => 'Внешний ID уже используется в этом ЖК.']);
        }
    }

    private function snapshot($record, array $old, string $type): void
    {
        if (! $old || ($old['publication_status'] ?? null) !== null) {
            return;
        }
        DB::table('residential_inventory_snapshots')->insert([
            'batch_id' => (string) Str::uuid(), 'entity_type' => $type, 'entity_id' => $record->id,
            'original_values' => json_encode($old, JSON_THROW_ON_ERROR), 'issues' => '[]', 'created_at' => now(),
        ]);
    }
}
