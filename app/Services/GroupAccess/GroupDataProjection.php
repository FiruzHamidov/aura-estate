<?php

namespace App\Services\GroupAccess;

use App\Models\Booking;
use App\Models\Client;
use App\Models\ClientNeed;
use App\Models\CrmTask;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Property;
use App\Models\User;
use App\Support\RopGroupAccess;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class GroupDataProjection
{
    private function taskReference(CrmTask $task): array
    {
        $field = match ($task->related_entity_type) {
            'ad', 'property' => 'property_id', 'showing' => 'booking_id',
            'client' => 'client_id', 'lead' => 'lead_id', 'deal' => 'deal_id', default => null,
        };
        return $field && $task->related_entity_id ? [$field => $task->related_entity_id] : [];
    }

    public function prepareTasks(Collection $tasks, User $actor): void
    {
        app(AuditSnapshotProjection::class)->preparePayloads($tasks->filter(fn ($task) => $task instanceof CrmTask)
            ->map(fn ($task) => $this->taskReference($task)), $actor);
    }

    /** A stored diagnostic may outlive access to its referenced property. */
    public function qualityIssues(Collection $issues, User $actor): Collection
    {
        if (! $actor->hasRole('rop')) return $issues;
        return $this->diagnostics($issues, $actor, 'details', 'title');
    }

    public function diagnostics(Collection $records, User $actor, string $payloadField, ?string $textField = null): Collection
    {
        if (! $actor->hasRole('rop')) return $records;
        $projection = app(AuditSnapshotProjection::class);
        $projection->preparePayloads($records->map(fn ($record) => (array) $record->$payloadField), $actor);
        return $records->map(function ($record) use ($projection, $actor, $payloadField, $textField) {
            if ($projection->hasHiddenReferences((array) $record->$payloadField, $actor)) {
                $record = clone $record;
                if ($textField) $record->$textField = $textField === 'title' ? 'KPI quality issue' : 'Связанная запись недоступна';
                $record->$payloadField = array_intersect_key((array) $record->$payloadField, ['metric_key' => true]);
                $record->setAttribute('details_unavailable', true);
            }
            return $record;
        });
    }

    /** Resolve a whole page once while preserving each selection's property order. */
    public function prepareSelections(Collection $selections, User $actor): void
    {
        $this->selectionPropertyIds($selections->flatMap(fn ($selection) => $selection->property_ids ?? [])->all(), $actor);
    }

    private function selectionPropertyIds(array $ids, User $actor): array
    {
        $ids = array_map('intval', $ids);
        $key = 'rop_selection_properties.'.$actor->id;
        $cache = request()->attributes->get($key, []);
        $missing = array_values(array_diff(array_unique($ids), array_keys($cache)));
        if ($missing !== []) {
            $allowed = app(RopGroupAccess::class)->scope(Property::query(), $actor, 'properties.branch_group_id', 'properties.branch_id')
                ->whereKey($missing)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $allowed = array_fill_keys($allowed, true);
            foreach ($missing as $id) $cache[$id] = isset($allowed[$id]);
            request()->attributes->set($key, $cache);
        }

        return array_values(array_filter($ids, fn ($id) => $cache[$id] ?? false));
    }

    public function attributes(Model $record, array $attributes, User $actor): array
    {
        if ($record instanceof CrmTask
            && app(AuditSnapshotProjection::class)->hasHiddenReferences($this->taskReference($record), $actor)) {
            $attributes['title'] = 'Связанная запись недоступна';
            $attributes['description'] = null;
            $attributes['related_entity_id'] = null;
            $attributes['related_entity_unavailable'] = true;
        }
        if ($record instanceof \App\Models\CrmAuditLog) {
            return app(AuditSnapshotProjection::class)->attributes($record, $attributes, $actor);
        }
        if ($record instanceof \App\Models\Selection) {
            $ids = $this->selectionPropertyIds($record->property_ids ?? [], $actor);
            $attributes['property_ids'] = $ids;
            unset($attributes['contact_id'], $attributes['deal_id']);
            if (isset($attributes['meta']['events'])) {
                $attributes['meta']['events'] = array_values(array_filter($attributes['meta']['events'],
                    fn ($event) => ! isset($event['payload']['property_id']) || in_array((int) $event['payload']['property_id'], $ids, true)));
            }
        }
        if ($record instanceof Property && isset($attributes['duplicate_candidates'])) {
            $attributes['duplicate_candidates'] = $this->duplicateResults(collect($attributes['duplicate_candidates']), $actor)->all();
        }
        $snapshots = match (true) {
            $record instanceof Property => [
                ['ownerClient', 'owner_client_id', ['owner_name', 'owner_phone']],
                ['buyerClient', 'buyer_client_id', ['buyer_full_name', 'buyer_phone', 'buyer_passport', 'buyer_passport_number']],
            ],
            $record instanceof Booking => [['client', 'crm_client_id', ['client_name', 'client_phone']]],
            default => [],
        };
        foreach ($snapshots as [$relation, $foreignKey, $fields]) {
            if (! $record->getAttribute($foreignKey)) {
                continue;
            }
            $client = $record->relationLoaded($relation) ? $record->getRelation($relation)
                : Client::query()->select(['id', 'branch_id', 'branch_group_id'])->find($record->getAttribute($foreignKey));
            if (! $client || ! $this->visible($client, $actor)) {
                foreach ([$foreignKey, ...$fields] as $field) {
                    unset($attributes[$field]);
                }
            }
        }

        return $attributes;
    }

    /** Filter display results only; duplicate detection itself remains global. */
    public function duplicateResults(Collection $results, User $actor): Collection
    {
        if (! $actor->hasRole('rop') || $results->isEmpty()) {
            return $results;
        }
        $ids = app(RopGroupAccess::class)->scope(Property::query(), $actor, 'properties.branch_group_id', 'properties.branch_id')
            ->whereIn('id', $results->pluck('id'))->pluck('id')->all();

        return $results->filter(fn ($item) => in_array((int) $item['id'], $ids, true))->values();
    }

    public function relations(Model $record, User $actor): array
    {
        $result = [];
        foreach ($record->getRelations() as $name => $relation) {
            if (in_array($name, $record->getHidden(), true)
                || ($record->getVisible() !== [] && ! in_array($name, $record->getVisible(), true))) {
                continue;
            }
            $key = $record::$snakeAttributes ? Str::snake($name) : $name;
            $result[$key] = $this->value($relation, $actor);
        }

        return $result;
    }

    private function value(mixed $value, User $actor): mixed
    {
        if ($value instanceof Collection) {
            app(AuditSnapshotProjection::class)->prepare($value, $actor);
            $this->prepareTasks($value, $actor);
            return $value->map(fn ($item) => $this->value($item, $actor))->filter(fn ($item) => $item !== null)->values()->all();
        }
        if ($value instanceof User && (int) $value->id !== (int) $actor->id) {
            if (! in_array($value->role?->slug, ['agent', 'mop'], true) || ! $this->visible($value, $actor)) {
                return $value->only(['id', 'name']);
            }
        } elseif ($value instanceof \App\Models\PropertyDuplicateCandidate) {
            if (! $value->candidateProperty || ! $this->visible($value->candidateProperty, $actor)) {
                return null;
            }
        } elseif ($value instanceof ClientNeed) {
            if (! $value->client || ! $this->visible($value->client, $actor)) {
                return null;
            }
        } elseif ($value instanceof Property || $value instanceof Client || $value instanceof Deal
            || $value instanceof Lead || $value instanceof Booking || $value instanceof CrmTask) {
            if (! $this->visible($value, $actor)) {
                return null;
            }
        }

        return $value instanceof Arrayable ? $value->toArray() : $value;
    }

    private function visible(Model $record, User $actor): bool
    {
        // Cache only inside the current HTTP request, never across authorization changes.
        $key = 'rop_projection_groups.'.$actor->id;
        $request = request();
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, app(RopGroupAccess::class)->groupIds($actor));
        }
        $attributes = $record->getAttributes();

        return in_array((int) ($attributes['branch_group_id'] ?? 0), $request->attributes->get($key), true)
            && (! array_key_exists('branch_id', $attributes) || (int) $attributes['branch_id'] === (int) $actor->branch_id);
    }
}
