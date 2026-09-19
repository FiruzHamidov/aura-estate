<?php

namespace App\Services\GroupAccess;

use App\Models\{Client, CrmAuditLog, Deal, Lead, Property, User};
use App\Support\RopGroupAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Copied audit payloads must not expose a referenced card that is no longer accessible. */
final class AuditSnapshotProjection
{
    private const REFERENCES = [
        'client_id' => Client::class, 'converted_client_id' => Client::class,
        'owner_client_id' => Client::class, 'buyer_client_id' => Client::class,
        'crm_client_id' => Client::class,
        'lead_id' => Lead::class, 'deal_id' => Deal::class, 'converted_deal_id' => Deal::class,
        'property_id' => Property::class, 'primary_property_id' => Property::class,
        'booking_id' => \App\Models\Booking::class,
    ];

    private function references(array $payload): array
    {
        $references = [];
        foreach ($payload as $field => $value) {
            if (isset(self::REFERENCES[$field]) && is_numeric($value) && (int) $value > 0) {
                $references[self::REFERENCES[$field]][(int) $value] = true;
            } elseif (is_array($value)) {
                foreach ($this->references($value) as $class => $ids) $references[$class] = ($references[$class] ?? []) + $ids;
            }
        }
        return $references;
    }

    public function prepare(Collection $logs, User $actor): void
    {
        $this->preparePayloads($logs->filter(fn ($log) => $log instanceof CrmAuditLog)
            ->map(fn ($log) => [$log->old_values ?? [], $log->new_values ?? [], $log->context ?? []]), $actor);
    }

    public function preparePayloads(Collection $payloads, User $actor): void
    {
        if (! $actor->hasRole('rop')) return;
        $references = [];
        foreach ($payloads as $payload) {
            foreach ($this->references((array) $payload) as $class => $ids) {
                $references[$class] = ($references[$class] ?? []) + $ids;
            }
        }
        $key = 'rop_audit_references.'.$actor->id;
        $cache = request()->attributes->get($key, []);
        foreach ($references as $class => $ids) {
            $missing = array_diff_key($ids, $cache[$class] ?? []);
            if (! $missing) continue;
            $table = (new $class)->getTable();
            $allowed = Schema::hasColumn($table, 'branch_group_id')
                ? app(RopGroupAccess::class)->scope($class::query(), $actor, $table.'.branch_group_id',
                    Schema::hasColumn($table, 'branch_id') ? $table.'.branch_id' : null)
                    ->whereKey(array_keys($missing))->pluck('id')->all() : [];
            $allowed = array_fill_keys($allowed, true);
            foreach ($missing as $id => $_) $cache[$class][$id] = isset($allowed[$id]);
        }
        request()->attributes->set($key, $cache);
    }

    public function hasHiddenReferences(array $payload, User $actor): bool
    {
        if (! $actor->hasRole('rop')) return false;
        $this->preparePayloads(collect([$payload]), $actor);
        $cache = request()->attributes->get('rop_audit_references.'.$actor->id, []);
        foreach ($this->references($payload) as $class => $ids) {
            foreach ($ids as $id => $_) if (! ($cache[$class][$id] ?? false)) return true;
        }
        return false;
    }

    public function attributes(CrmAuditLog $log, array $attributes, User $actor): array
    {
        if ($this->hasHiddenReferences([$log->old_values ?? [], $log->new_values ?? [], $log->context ?? []], $actor)) {
            $attributes['old_values'] = null;
            $attributes['new_values'] = null;
            $attributes['context'] = null;
            $attributes['message'] = 'Связанная запись недоступна';
            $attributes['details_unavailable'] = true;
        }
        return $attributes;
    }
}
