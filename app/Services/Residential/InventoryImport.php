<?php

namespace App\Services\Residential;

use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use App\Models\ResidentialImportBatch;
use App\Models\User;
use App\Services\Crm\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryImport
{
    public function __construct(private readonly ResidentialAccess $access, private readonly InventoryWriter $writer, private readonly AuditLogger $audit) {}

    /** Execute the real writer inside a rolled-back transaction so cross-row invariants match apply. */
    public function preview(User $actor, NewBuilding $building, string $mode, array $rows, ?string $sourceName = null): ResidentialImportBatch
    {
        $this->access->ensurePublish($actor, $building);
        abort_unless(in_array($mode, ['csv', 'bulk'], true), 422);
        if (! $rows || count($rows) > InventoryCsv::MAX_ROWS) {
            throw ValidationException::withMessages(['rows' => 'Требуется от 1 до '.InventoryCsv::MAX_ROWS.' записей.']);
        }
        $report = [];
        $plan = [];
        $seen = [];
        $counts = ['total' => count($rows), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0];
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $parent = NewBuilding::query()->lockForUpdate()->findOrFail($building->id);
            $this->access->ensurePublish($actor, $parent);
            abort_if($parent->publication_status === 'archived', 409, 'Сначала восстановите ЖК из архива.');
            $parentVersion = $parent->version;
            $externalKeys = array_values(array_unique(array_map(fn ($row) => mb_strtolower((string) ($row['data']['external_id'] ?? '')), $rows)));
            $existing = $mode === 'csv' ? $parent->units()->whereIn(DB::raw('LOWER(external_id)'), $externalKeys)->get()->groupBy(fn ($unit) => mb_strtolower($unit->external_id)) : collect();
            foreach ($rows as $row) {
                $entry = ['line' => $row['line'], 'external_id' => $row['data']['external_id'] ?? null, 'unit_id' => null, 'action' => 'error', 'changes' => [], 'errors' => $row['errors'] ?? []];
                $input = $row['data'];
                $unit = null;
                if ($mode === 'csv') {
                    $key = mb_strtolower((string) ($input['external_id'] ?? ''));
                    if (isset($seen[$key])) {
                        $entry['errors']['external_id'] = ['Повторный внешний ID в файле (регистр не различается).'];
                    }
                    $seen[$key] = true;
                    $matches = $existing->get($key, collect());
                    if ($matches->count() > 1) {
                        $entry['errors']['external_id'] = ['В базе несколько ID, различающихся регистром. Сначала устраните неоднозначность.'];
                    } else {
                        $unit = $matches->first();
                    }
                    if (! $unit && empty($entry['errors'])) {
                        foreach (['name', 'area'] as $required) {
                            if (! isset($input[$required]) || $input[$required] === '') {
                                $entry['errors'][$required] = ['Для новой квартиры требуется '.$required.'.'];
                            }
                        }
                        if (! isset($input['total_price']) && ! isset($input['price_per_sqm']) && ($input['price_on_request'] ?? false) !== true) {
                            $entry['errors']['price_on_request'] = ['Для нового лота укажите цену либо явно выберите «По запросу».'];
                        }
                    }
                } else {
                    $unit = $parent->units()->find($row['id']);
                    if (! $unit) {
                        $entry['errors']['unit_id'] = ['Квартира не найдена в этом ЖК.'];
                    }
                }
                if ($unit) {
                    $entry['unit_id'] = $unit->id;
                }
                if (! $entry['errors']) {
                    try {
                        $input = $this->normalizePrice($input);
                        $before = $unit ? $this->snapshot($unit) : [];
                        $version = $unit?->version;
                        $id = $unit?->id;
                        // Preserve an existing external ID's spelling instead of silently renaming it.
                        if ($unit && $mode === 'csv') {
                            $input['external_id'] = $unit->external_id;
                        }
                        $saved = $this->writer->unit($actor, $parent, $input + ($unit ? ['version' => $version] : []), $unit);
                        $after = $this->snapshot($saved);
                        $changes = [];
                        foreach ($after as $field => $value) {
                            if (($before[$field] ?? null) !== $value) {
                                $changes[] = ['field' => $field, 'before' => $before[$field] ?? null, 'after' => $value];
                            }
                        }
                        $entry['action'] = $id === null ? 'create' : ($changes ? 'update' : 'unchanged');
                        $entry['changes'] = $changes;
                        $counts[match ($entry['action']) {
                            'create' => 'created', 'update' => 'updated', default => 'unchanged'
                        }]++;
                        $plan[] = ['line' => $row['line'], 'id' => $id, 'version' => $version, 'action' => $entry['action'], 'data' => $input];
                    } catch (ValidationException $error) {
                        $entry['errors'] = $error->errors();
                    } catch (QueryException) {
                        $entry['errors']['record'] = ['Конфликт уникальности или связей. Проверьте номера, позиции и внешние ID.'];
                    }
                }
                if ($entry['errors']) {
                    $counts['errors']++;
                }
                $report[] = $entry;
            }
        } finally {
            // No inventory, snapshots or audit rows from the simulation may commit.
            DB::rollBack($level);
        }

        return ResidentialImportBatch::create(['new_building_id' => $building->id, 'actor_id' => $actor->id, 'mode' => $mode, 'source_name' => $sourceName, 'status' => $counts['errors'] ? 'invalid' : 'preview', 'building_version' => $parentVersion, 'rows' => $plan, 'report' => $report, 'counts' => $counts, 'expires_at' => now()->addMinutes(15)]);
    }

    public function apply(User $actor, NewBuilding $building, int $id): ResidentialImportBatch
    {
        $this->access->ensurePublish($actor, $building);

        return DB::transaction(function () use ($actor, $building, $id) {
            $batch = ResidentialImportBatch::query()->where('new_building_id', $building->id)->where('actor_id', $actor->id)->lockForUpdate()->findOrFail($id);
            $parent = NewBuilding::query()->lockForUpdate()->findOrFail($building->id);
            $this->access->ensurePublish($actor, $parent);
            if ($batch->status === 'applied') {
                return $batch;
            }
            abort_if($parent->publication_status === 'archived', 409, 'Сначала восстановите ЖК из архива.');
            abort_unless($batch->status === 'preview' && $batch->expires_at->isFuture(), 409, 'Предпросмотр содержит ошибки или истёк. Создайте новый предпросмотр.');
            abort_unless((int) $parent->version === (int) $batch->building_version, 409, 'ЖК или его структура изменились. Создайте новый предпросмотр.');
            $ids = [];
            $report = $batch->report;
            foreach ($batch->rows as $row) {
                $unit = $row['id'] ? $parent->units()->lockForUpdate()->find($row['id']) : null;
                if ($row['id']) {
                    abort_unless($unit && (int) $unit->version === (int) $row['version'], 409, 'Одна из квартир изменилась после предпросмотра. Ничего не применено.');
                } elseif ($parent->units()->whereRaw('LOWER(external_id) = ?', [mb_strtolower($row['data']['external_id'])])->exists()) {
                    abort(409, 'Внешний ID уже появился в фонде. Создайте новый предпросмотр.');
                }
                if ($row['action'] === 'unchanged') {
                    $ids[$row['line']] = $unit->id;

                    continue;
                }
                $saved = $this->writer->unit($actor, $parent, $row['data'] + ($unit ? ['version' => $row['version']] : []) + ['change_reason' => 'Импорт/массовое изменение #'.$batch->id], $unit);
                $ids[$row['line']] = $saved->id;
            }
            foreach ($report as &$entry) {
                $entry['unit_id'] = $ids[$entry['line']] ?? $entry['unit_id'];
            }
            unset($entry);
            $batch->update(['status' => 'applied', 'applied_at' => now(), 'result' => $batch->counts, 'report' => $report]);
            $this->audit->log($batch, $actor, 'residential.import.applied', [], $batch->counts, 'Применён подтверждённый предпросмотр.', ['new_building_id' => $building->id, 'mode' => $batch->mode]);

            return $batch->refresh();
        }, 3);
    }

    private function snapshot(DeveloperUnit $unit): array
    {
        $result = [];
        foreach ([...InventoryCsv::COLUMNS, 'moderation_status', 'is_available', 'currency'] as $field) {
            $value = $unit->getAttribute($field);
            $result[$field] = $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : (is_numeric($value) && ! is_bool($value) ? (string) $value : $value);
        }

        return $result;
    }

    private function normalizePrice(array $input): array
    {
        $total = isset($input['total_price']);
        $sqm = isset($input['price_per_sqm']);
        if ($total && $sqm || ($input['price_on_request'] ?? false) && ($total || $sqm)) {
            throw ValidationException::withMessages(['price' => 'Укажите одну исходную цену либо «По запросу». Производная цена рассчитывается сервером.']);
        }
        if ($total || $sqm) {
            $basis = $total ? 'total' : 'per_sqm';
            if (isset($input['pricing_basis']) && $input['pricing_basis'] !== $basis) {
                throw ValidationException::withMessages(['pricing_basis' => 'Основа цены не соответствует заполненному ценовому столбцу.']);
            }
            $input['pricing_basis'] = $basis;
            $input['price_on_request'] = false;
        } elseif (isset($input['pricing_basis'])) {
            throw ValidationException::withMessages(['price' => 'Для смены основы укажите соответствующую исходную цену.']);
        }

        return $input;
    }
}
