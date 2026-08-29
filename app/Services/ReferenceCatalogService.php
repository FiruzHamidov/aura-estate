<?php

namespace App\Services;

use App\Models\ReferenceCatalogMergeAudit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ReferenceCatalogService
{
    private const NEW_BUILDING_CATALOGS = ['developers', 'construction-stages', 'materials', 'features', 'locations'];

    public function assertCanManage(User $user, ?string $catalog = null): void
    {
        $user->loadMissing('role');

        if (in_array($user->role?->slug, ['admin', 'superadmin'], true)
            || (in_array($user->role?->slug, ['owner', 'agent', 'mop'], true)
                && in_array($catalog, self::NEW_BUILDING_CATALOGS, true))) {
            return;
        }

        $this->deny('REFERENCE_CATALOG_FORBIDDEN', 'Недостаточно прав для управления справочниками.');
    }

    public function usage(string $catalog, int $sourceId): array
    {
        $definition = $this->definition($catalog);
        $source = $this->item($definition, $sourceId);
        $usage = $this->usageFor($definition, $sourceId);
        $mergeAllowed = ! $this->isProtected($definition, $source);

        return [
            'catalog' => $catalog,
            'item' => $this->serializeItem($definition, $source),
            'usage' => $usage,
            'can_delete_directly' => $usage['total'] === 0 && $mergeAllowed,
            'replacement_required' => $usage['total'] > 0,
            'merge_allowed' => $mergeAllowed,
            'replacement_options' => $mergeAllowed
                ? $this->replacementOptions($definition, $sourceId)
                : [],
        ];
    }

    public function merge(
        User $actor,
        string $catalog,
        int $sourceId,
        int $replacementId,
        int $expectedUsageCount,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        $this->assertCanManage($actor, $catalog);
        if ($sourceId === $replacementId) {
            $this->deny('REFERENCE_REPLACEMENT_SAME_AS_SOURCE', 'Нельзя заменить запись самой собой.', 422);
        }

        $definition = $this->definition($catalog);
        $cleanupPath = null;

        try {
            $result = DB::transaction(function () use (
                $actor,
                $catalog,
                $definition,
                $sourceId,
                $replacementId,
                $expectedUsageCount,
                $ipAddress,
                $userAgent,
                &$cleanupPath,
            ) {
                $source = DB::table($definition['table'])->where('id', $sourceId)->lockForUpdate()->first();
                $replacement = DB::table($definition['table'])->where('id', $replacementId)->lockForUpdate()->first();

                if (! $source || ! $replacement) {
                    $this->deny('REFERENCE_CATALOG_ITEM_NOT_FOUND', 'Исходная запись или замена не найдена.', 404);
                }
                if ($this->isProtected($definition, $source)) {
                    $this->deny('REFERENCE_CATALOG_ITEM_PROTECTED', 'Системную запись справочника нельзя объединить или удалить.', 409);
                }

                $usage = $this->usageFor($definition, $sourceId);
                if ($usage['total'] !== $expectedUsageCount) {
                    throw new HttpResponseException(response()->json([
                        'code' => 'REFERENCE_USAGE_CHANGED',
                        'message' => 'Количество связанных записей изменилось. Обновите данные и подтвердите перенос повторно.',
                        'details' => [
                            'expected_usage_count' => $expectedUsageCount,
                            'actual_usage_count' => $usage['total'],
                            'usage' => $usage,
                        ],
                    ], 409));
                }

                foreach ($this->availableReferences($definition) as $reference) {
                    $this->replaceReference($reference, $sourceId, $replacementId);
                }

                $remaining = $this->usageFor($definition, $sourceId);
                if ($remaining['total'] !== 0) {
                    $this->deny('REFERENCE_MERGE_INCOMPLETE', 'Не все связанные записи удалось переназначить.', 409);
                }

                $cleanup = $definition['cleanup_file'] ?? null;
                if ($cleanup && isset($source->{$cleanup['column']})) {
                    $cleanupPath = (string) $source->{$cleanup['column']};
                }

                DB::table($definition['table'])->where('id', $sourceId)->delete();

                $response = [
                    'catalog' => $catalog,
                    'source' => $this->serializeItem($definition, $source),
                    'replacement' => $this->serializeItem($definition, $replacement),
                    'reassigned' => $usage,
                    'source_deleted' => true,
                ];

                if (Schema::hasTable('reference_catalog_merge_audits')) {
                    ReferenceCatalogMergeAudit::query()->create([
                        'actor_user_id' => $actor->id,
                        'catalog' => $catalog,
                        'source_id' => $sourceId,
                        'source_label' => $this->label($definition, $source),
                        'replacement_id' => $replacementId,
                        'replacement_label' => $this->label($definition, $replacement),
                        'reassigned_count' => $usage['total'],
                        'usage' => $usage,
                        'ip_address' => $ipAddress,
                        'user_agent' => mb_substr((string) $userAgent, 0, 500),
                    ]);
                }

                return $response;
            });
        } catch (QueryException $exception) {
            report($exception);
            $this->deny(
                'REFERENCE_MERGE_CONFLICT',
                'Перенос невозможен из-за конфликтующих связанных данных. Изменения отменены.',
                409,
            );
        }

        $this->deleteCleanupFile($definition, $cleanupPath);

        return $result;
    }

    // Callers enforce their route permissions; all deletion paths share this safety check.
    public function deleteUnused(string $catalog, int $sourceId): array
    {
        $definition = $this->definition($catalog);
        $cleanupPath = null;

        try {
            $result = DB::transaction(function () use ($catalog, $definition, $sourceId, &$cleanupPath) {
                $source = DB::table($definition['table'])->where('id', $sourceId)->lockForUpdate()->first();
                if (! $source) {
                    $this->deny('REFERENCE_CATALOG_ITEM_NOT_FOUND', 'Запись справочника не найдена.', 404);
                }
                if ($this->isProtected($definition, $source)) {
                    $this->deny('REFERENCE_CATALOG_ITEM_PROTECTED', 'Системную запись справочника нельзя объединить или удалить.', 409);
                }

                $usage = $this->usageFor($definition, $sourceId);
                if ($usage['total'] > 0) {
                    throw new HttpResponseException(response()->json([
                        'code' => 'REFERENCE_CATALOG_IN_USE',
                        'message' => 'Запись используется и не может быть удалена без выбора замены.',
                        'details' => ['usage' => $usage],
                    ], 409));
                }

                $cleanup = $definition['cleanup_file'] ?? null;
                if ($cleanup && isset($source->{$cleanup['column']})) {
                    $cleanupPath = (string) $source->{$cleanup['column']};
                }

                DB::table($definition['table'])->where('id', $sourceId)->delete();

                return [
                    'catalog' => $catalog,
                    'source' => $this->serializeItem($definition, $source),
                    'source_deleted' => true,
                ];
            });
        } catch (QueryException $exception) {
            report($exception);
            $this->deny(
                'REFERENCE_DELETE_CONFLICT',
                'Запись связана с данными, которые нельзя безопасно удалить. Изменения отменены.',
                409,
            );
        }

        $this->deleteCleanupFile($definition, $cleanupPath);

        return $result;
    }

    private function deleteCleanupFile(array $definition, ?string $cleanupPath): void
    {
        $cleanup = $definition['cleanup_file'] ?? null;
        if (! $cleanupPath || ! $cleanup) {
            return;
        }

        try {
            if (Storage::disk($cleanup['disk'])->exists($cleanupPath)) {
                Storage::disk($cleanup['disk'])->delete($cleanupPath);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function definition(string $catalog): array
    {
        $definition = config('reference_catalogs.'.$catalog);

        if (! is_array($definition) || ! isset($definition['table']) || ! Schema::hasTable($definition['table'])) {
            $this->deny('REFERENCE_CATALOG_NOT_FOUND', 'Справочник не найден.', 404);
        }

        return $definition;
    }

    private function item(array $definition, int $id): object
    {
        $item = DB::table($definition['table'])->where('id', $id)->first();

        if (! $item) {
            $this->deny('REFERENCE_CATALOG_ITEM_NOT_FOUND', 'Запись справочника не найдена.', 404);
        }

        return $item;
    }

    private function usageFor(array $definition, int $sourceId): array
    {
        $groups = [];

        foreach ($this->availableReferences($definition) as $reference) {
            $entityColumn = $reference['entity_column'] ?? 'id';
            $ids = DB::table($reference['table'])
                ->where($reference['column'], $sourceId)
                ->pluck($entityColumn)
                ->map(fn ($id) => (string) $id)
                ->all();
            $key = $reference['key'];
            $groups[$key] ??= [
                'entity' => $key,
                'label' => $reference['label'],
                'ids' => [],
            ];
            $groups[$key]['ids'] = array_values(array_unique([...$groups[$key]['ids'], ...$ids]));
        }

        $breakdown = collect($groups)
            ->map(fn (array $group) => [
                'entity' => $group['entity'],
                'label' => $group['label'],
                'count' => count($group['ids']),
            ])
            ->filter(fn (array $group) => $group['count'] > 0)
            ->values()
            ->all();

        return [
            'total' => array_sum(array_column($breakdown, 'count')),
            'breakdown' => $breakdown,
        ];
    }

    private function availableReferences(array $definition): array
    {
        return array_values(array_filter(
            $definition['references'] ?? [],
            fn (array $reference) => Schema::hasTable($reference['table'])
                && Schema::hasColumn($reference['table'], $reference['column'])
                && Schema::hasColumn($reference['table'], $reference['entity_column'] ?? 'id')
        ));
    }

    private function replaceReference(array $reference, int $sourceId, int $replacementId): void
    {
        $query = DB::table($reference['table'])->where($reference['column'], $sourceId);

        if (! ($reference['pivot'] ?? false)) {
            $query->update([$reference['column'] => $replacementId]);

            return;
        }

        $entityColumn = $reference['entity_column'];
        $targetEntityIds = DB::table($reference['table'])
            ->where($reference['column'], $replacementId)
            ->pluck($entityColumn);

        if ($targetEntityIds->isNotEmpty()) {
            DB::table($reference['table'])
                ->where($reference['column'], $sourceId)
                ->whereIn($entityColumn, $targetEntityIds)
                ->delete();
        }

        DB::table($reference['table'])
            ->where($reference['column'], $sourceId)
            ->update([$reference['column'] => $replacementId]);
    }

    public function replacements(string $catalog, int $sourceId, string $search = '', int $perPage = 50)
    {
        $definition = $this->definition($catalog);
        $source = $this->item($definition, $sourceId);
        $query = $this->replacementQuery($definition, $sourceId);
        if ($this->isProtected($definition, $source)) {
            $query->whereRaw('1 = 0');
        }
        $search = trim($search);
        if ($search !== '') {
            $columns = $definition['label_columns'];
            if (Schema::hasColumn($definition['table'], 'slug')) {
                $columns[] = 'slug';
            }
            $query->where(function ($text) use ($columns, $search) {
                foreach ($columns as $column) {
                    $text->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        return $query->paginate($perPage)->through(fn (object $item) => $this->serializeItem($definition, $item));
    }

    private function replacementOptions(array $definition, int $sourceId): array
    {
        return $this->replacementQuery($definition, $sourceId)->limit(500)->get()
            ->map(fn (object $item) => $this->serializeItem($definition, $item))->all();
    }

    private function replacementQuery(array $definition, int $sourceId)
    {
        $columns = collect(['id', ...$definition['label_columns']])
            ->filter(fn (string $column) => Schema::hasColumn($definition['table'], $column))
            ->unique()
            ->values()
            ->all();
        if (Schema::hasColumn($definition['table'], 'slug')) {
            $columns[] = 'slug';
        }
        if (Schema::hasColumn($definition['table'], 'is_active')) {
            $columns[] = 'is_active';
        }

        return DB::table($definition['table'])
            ->select(array_values(array_unique($columns)))
            ->where('id', '!=', $sourceId)
            ->orderBy($definition['label_columns'][0])
            ->orderBy('id');
    }

    private function serializeItem(array $definition, object $item): array
    {
        $payload = [
            'id' => (int) $item->id,
            'name' => $this->label($definition, $item),
        ];

        foreach (['slug', 'is_active'] as $column) {
            if (property_exists($item, $column)) {
                $payload[$column] = $column === 'is_active' ? (bool) $item->{$column} : $item->{$column};
            }
        }

        return $payload;
    }

    private function label(array $definition, object $item): string
    {
        return collect($definition['label_columns'])
            ->map(fn (string $column) => trim((string) ($item->{$column} ?? '')))
            ->filter()
            ->implode(' — ');
    }

    private function isProtected(array $definition, object $item): bool
    {
        if (! ($definition['protect_system_roles'] ?? false)) {
            return false;
        }

        $systemSlugs = array_column(config('roles.system', []), 'slug');

        return isset($item->slug) && in_array($item->slug, $systemSlugs, true);
    }

    private function deny(string $code, string $message, int $status = 403): never
    {
        throw new HttpResponseException(response()->json([
            'code' => $code,
            'message' => $message,
            'details' => (object) [],
        ], $status));
    }
}
