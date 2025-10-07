<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Services\Bitrix24Client;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Синхронизация объектов в смарт-процесс "Объект" Bitrix24.
 *
 * Принцип:
 *  - Берём объекты, обновлённые после курсора (updated_at > last_cursor)
 *  - Проверяем, есть ли элемент СП с таким UF_OBJ_ID
 *  - Если есть — crm.item.update, иначе — crm.item.add
 *  - В конце обновляем курсор (максимальный updated_at из обработанных)
 *
 * Запуск:
 *  php artisan b24:sync:properties
 *  php artisan b24:sync:properties --since="2025-01-01 00:00:00" --limit=1000 --entity=180
 */
class BitrixSyncProperties extends Command
{
    protected $signature = 'b24:sync:properties
        {--since= : Начать с этой даты-времени (YYYY-MM-DD HH:MM:SS), иначе используется сохранённый курсор}
        {--limit=500 : Максимум записей за один прогон}
        {--entity= : entityTypeId смарт-процесса (по умолчанию из env B24_ENTITY_TYPE_ID)}
        {--dry : Ничего не отправлять в B24, только вывести что бы сделали}';

    protected $description = 'Sync properties to Bitrix24 Smart Process "Object"';

    // ⚠️ УКАЖИТЕ РЕАЛЬНЫЕ КОДЫ UF-ПОЛЕЙ ИЗ ВАШЕГО ПОРТАЛА!
    // Их можно узнать через crm.item.fields для вашего entityTypeId.
    private const UF_OBJ_ID     = 'ufCrmUfObjId';
    private const UF_PRICE      = 'ufCrmUfPrice';
    private const UF_ROOMS      = 'ufCrmUfRooms';
    private const UF_ADDRESS    = 'ufCrmUfAddress';
    private const UF_AREA       = 'ufCrmUfArea';
    private const UF_LINK       = 'ufCrmUfLink';
    private const UF_MEDIA      = 'ufCrmUfMedia';
    private const UF_IS_ACTIVE  = 'ufCrmUfIsActive';

    private const CURSOR_KEY = 'b24:sync:properties:last_cursor';

    public function handle(Bitrix24Client $b24): int
    {
        $entityTypeId = (int) ($this->option('entity') ?: env('B24_ENTITY_TYPE_ID'));
        if (!$entityTypeId) {
            $this->error('B24_ENTITY_TYPE_ID не задан и не передан через --entity.');
            return self::FAILURE;
        }

        // 1) Курсор
        $sinceOpt = $this->option('since');
        $since = $sinceOpt ?: Cache::get(self::CURSOR_KEY);
        $this->info('Since: ' . ($since ?: '<none>'));

        // 2) Выборка свойств
        $limit = (int) $this->option('limit');
        $query = Property::query()
            ->when($since, fn ($q) => $q->where('updated_at', '>', $since))
            ->orderBy('updated_at', 'asc')
            ->limit($limit);

        $list = $query->get();
        if ($list->isEmpty()) {
            $this->info('Нет объектов для синхронизации.');
            return self::SUCCESS;
        }

        // 3) Проверим, какие уже существуют в СП по UF_OBJ_ID
        $ids = $list->pluck('id')->values()->all();
        $existing = $b24->itemList($entityTypeId, [
            self::UF_OBJ_ID => $ids,   // фильтр по массиву внешних ID
        ], ['id', self::UF_OBJ_ID]);

        $map = []; // propId => b24ItemId
        foreach ($existing['result']['items'] ?? [] as $it) {
            $extId = (int) ($it[self::UF_OBJ_ID] ?? 0);
            if ($extId) {
                $map[$extId] = (int) $it['id'];
            }
        }

        $dry = (bool) $this->option('dry');
        $synced = 0;
        $failed = 0;
        $maxUpdated = $since ? Carbon::parse($since) : null;

        foreach ($list as $prop) {
            $fields = $this->mapFields($prop);

            try {
                if (isset($map[$prop->id])) {
                    $itemId = $map[$prop->id];
                    if ($dry) {
                        $this->line("[dry] update #{$itemId} <- property {$prop->id}");
                    } else {
                        $b24->itemUpdate($entityTypeId, $itemId, $fields);
                        $this->line("update #{$itemId} <- property {$prop->id}");
                    }
                } else {
                    if ($dry) {
                        $this->line("[dry] add <- property {$prop->id}");
                    } else {
                        $res = $b24->itemAdd($entityTypeId, $fields);
                        $newId = Arr::get($res, 'result.item.id');
                        $this->line("add #{$newId} <- property {$prop->id}");
                    }
                }
                $synced++;
                $u = Carbon::parse($prop->updated_at);
                if (!$maxUpdated || $u->gt($maxUpdated)) {
                    $maxUpdated = $u;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Fail property {$prop->id}: ".$e->getMessage());
            }
        }

        // 4) Обновить курсор
        if (!$dry && $maxUpdated) {
            Cache::put(self::CURSOR_KEY, $maxUpdated->toDateTimeString(), now()->addDays(7));
            $this->info("Cursor updated: ".$maxUpdated->toDateTimeString());
        }

        $this->info("Synced: {$synced}, Failed: {$failed}");
        return $failed ? self::INVALID : self::SUCCESS;
    }

    /**
     * Маппинг полей Property -> UF_* вашего смарт-процесса.
     * ⚠️ Замените имена UF_* на реальные API-коды из crm.item.fields!
     */
    private function mapFields($prop): array
    {
        // Примерная логика активного статуса
        $isActive = in_array($prop->moderation_status, ['approved','sale','rent']) ? 'Y' : 'N';

        // Ссылка на публичную страницу объекта (замените, если нужно)
        $link = route('property.public', ['id' => $prop->id], false); // если роут есть; иначе соберите URL вручную

        return array_filter([
            'title'                 => $prop->title ?? ("Объект #{$prop->id}"),
            self::UF_OBJ_ID         => (int) $prop->id,
            self::UF_PRICE          => $prop->price !== null ? (float) $prop->price : null,
            self::UF_ROOMS          => $prop->rooms !== null ? (int) $prop->rooms : null,
            self::UF_ADDRESS        => $prop->address ?? null,
            self::UF_AREA           => $prop->total_area !== null ? (float) $prop->total_area : null,
            self::UF_LINK           => $link,
            self::UF_MEDIA          => is_countable($prop->photos ?? null) ? count($prop->photos) : null,
            self::UF_IS_ACTIVE      => $isActive,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
