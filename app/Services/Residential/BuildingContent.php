<?php

namespace App\Services\Residential;

use App\Models\NewBuilding;
use App\Models\NewBuildingNearbyPlace;
use App\Models\NewBuildingVideo;
use App\Models\User;
use App\Services\Crm\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class BuildingContent
{
    public const CATEGORIES = ['mosque', 'bus_stop', 'downtown', 'hospital', 'gym', 'park', 'school', 'kindergarten', 'supermarket'];

    public const LIMITS = ['nearby-places' => 100, 'videos' => 20];

    public function __construct(private readonly ResidentialAccess $access, private readonly InventoryWriter $versions, private readonly AuditLogger $audit, private readonly VideoLink $videos) {}

    public function query(NewBuilding $building, string $kind): Builder
    {
        $class = match ($kind) {
            'nearby-places' => NewBuildingNearbyPlace::class, 'videos' => NewBuildingVideo::class, default => abort(404)
        };

        return $class::query()->where('new_building_id', $building->id);
    }

    public function index(NewBuilding $building, bool $private = false): array
    {
        $result = [];
        foreach (self::LIMITS as $kind => $limit) {
            $result[str_replace('-', '_', $kind)] = $this->query($building, $kind)->orderBy('sort_order')->orderBy('id')->limit($limit)->get()->map(fn ($record) => $this->serialize($record, $building, $private));
        }

        return $result;
    }

    public function save(User $actor, NewBuilding $building, string $kind, array $input, ?int $id = null): Model
    {
        $this->access->ensureManage($actor, $building);
        abort_unless(isset(self::LIMITS[$kind]), 404);
        $required = $id ? 'sometimes|required' : 'required';
        $rules = ['version' => [$id ? 'required' : 'sometimes', 'integer', 'min:1'], 'sort_order' => 'sometimes|integer|between:0,10000', 'data_verified_at' => $required.'|date|before_or_equal:now', 'change_reason' => 'nullable|string|max:1000'];
        $rules += $kind === 'videos' ? ['title' => $required.'|string|max:255', 'url' => $required.'|url:https|max:2000'] : [
            'name' => $required.'|string|max:255', 'type' => $required.'|in:'.implode(',', self::CATEGORIES),
            'latitude' => $required.'|numeric|between:-90,90', 'longitude' => $required.'|numeric|between:-180,180', 'source_url' => $required.'|url:https|max:2000',
        ];
        $data = Validator::make($input, $rules)->validate();

        return DB::transaction(function () use ($actor, $building, $kind, $id, $data) {
            $parent = NewBuilding::query()->lockForUpdate()->findOrFail($building->id);
            $this->access->ensureManage($actor, $parent);
            $query = $this->query($parent, $kind);
            if (! $id && $query->count() >= self::LIMITS[$kind]) {
                throw ValidationException::withMessages(['limit' => 'Достигнут лимит объектов в разделе: '.self::LIMITS[$kind].'. Удалите неактуальные записи перед добавлением.']);
            }
            $record = $id ? $query->lockForUpdate()->findOrFail($id) : $query->getModel()->newInstance(['new_building_id' => $parent->id]);
            if ($id) {
                $this->versions->checkVersion($record, $data);
            }
            $before = $record->getAttributes();
            $values = $data;
            unset($values['version'], $values['change_reason']);
            if ($kind === 'videos' && isset($values['url'])) {
                $values = array_replace($values, $this->videos->parse($values['url']));
                if ($this->query($parent, $kind)->where('provider', $values['provider'])->where('provider_id', $values['provider_id'])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                    throw ValidationException::withMessages(['url' => 'Этот ролик уже добавлен в ЖК.']);
                }
            }
            $record->fill($values);
            if ($kind === 'nearby-places') {
                $record->fill(['distance_meters' => $this->distance($parent, $record), 'distance_method' => 'straight_line', 'distance_origin_latitude' => $parent->latitude, 'distance_origin_longitude' => $parent->longitude]);
            }
            $record->version = $id ? $record->version + 1 : 1;
            $record->save();
            $this->audit->log($record, $actor, $id ? 'residential.content.updated' : 'residential.content.created', $before, $record->getAttributes(), $data['change_reason'] ?? null);
            $this->changed($parent, $actor);

            return $record->refresh();
        }, 3);
    }

    public function remove(User $actor, NewBuilding $building, string $kind, int $id, array $input): void
    {
        $this->access->ensureManage($actor, $building);
        $data = Validator::make($input, ['version' => 'required|integer|min:1', 'change_reason' => 'nullable|string|max:1000'])->validate();
        DB::transaction(function () use ($actor, $building, $kind, $id, $data) {
            $parent = NewBuilding::query()->lockForUpdate()->findOrFail($building->id);
            $this->access->ensureManage($actor, $parent);
            $record = $this->query($parent, $kind)->lockForUpdate()->findOrFail($id);
            $this->versions->checkVersion($record, $data);
            $old = $record->getAttributes();
            $record->delete();
            $this->audit->log($record, $actor, 'residential.content.deleted', $old, [], $data['change_reason'] ?? null);
            $this->changed($parent, $actor);
        }, 3);
    }

    private function changed(NewBuilding $building, User $actor): void
    {
        $old = $building->getAttributes();
        if (! $this->access->canPublish($actor, $building) && InventoryStatus::building($old) === 'published') {
            $building->publication_status = 'pending';
            $building->moderation_status = 'pending';
        }
        $building->version++;
        $building->save();
        $this->audit->log($building, $actor, 'residential.content.changed', $old, $building->getAttributes());
    }

    public function distance(NewBuilding $building, NewBuildingNearbyPlace $place): ?int
    {
        if ($building->latitude === null || $building->longitude === null) {
            return null;
        }
        $a = deg2rad((float) $building->latitude);
        $b = deg2rad((float) $place->latitude);
        $deltaLat = $b - $a;
        $deltaLon = deg2rad((float) $place->longitude - (float) $building->longitude);
        $h = pow(sin($deltaLat / 2), 2) + cos($a) * cos($b) * pow(sin($deltaLon / 2), 2);

        return (int) round(6371000 * 2 * asin(sqrt(min(1, max(0, $h)))));
    }

    public function serialize(Model $record, NewBuilding $building, bool $private = false): array
    {
        if ($record instanceof NewBuildingVideo) {
            $result = $record->only(['id', 'title', 'provider', 'provider_id', 'url', 'data_verified_at', 'sort_order']);
            $result['embed_url'] = $this->videos->embed($record->provider, $record->provider_id);
        } else {
            $result = $record->only(['id', 'name', 'type', 'latitude', 'longitude', 'source_url', 'data_verified_at', 'sort_order']);
            $result['distance_meters'] = $this->distance($building, $record);
            $result['distance_method'] = 'straight_line';
        }

        return $private ? $result + ['version' => $record->version] : $result;
    }
}
