<?php

namespace App\Services;

use App\Models\Property;
use App\Models\User;
use App\Services\GroupAccess\PropertyWriteLock;
use App\Services\PropertyModeration\PropertyModerationAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** A deliberately bounded repair, never a bulk reinterpretation of status_id. */
final class PropertyPublicationRepair
{
    public const IDS = [4225, 4229, 4230, 4231, 4232, 4868, 5045, 9229];
    public const CLOSED_DEALS = [4225 => 'rented', 4229 => 'rented', 4230 => 'rented', 4231 => 'rented',
        4232 => 'rented', 4868 => 'sold', 5045 => 'rented', 9229 => 'sold_by_owner'];

    private const RELATED_TABLES = [
        'property_logs', 'property_status_history', 'property_price_history',
        'property_moderation_events', 'property_moderation_cases', 'property_promotions',
        'property_agent_sales', 'property_photos', 'feature_property', 'property_tag',
        'document_type_property',
    ];

    public function export(User $actor): array
    {
        $this->assertSchema();
        $entries = [];
        foreach (self::IDS as $id) {
            $property = Property::find($id);
            if ($property) $this->assertActor($actor, $property);
            $state = $this->snapshot($id);
            $entries[] = ['id' => $id, 'expected_fingerprint' => $state ? $this->fingerprint($state) : null,
                'decision' => 'hold', 'deal_status' => self::CLOSED_DEALS[$id],
                'reason' => '', 'evidence' => '', 'observed' => $state];
        }
        return ['schema_version' => 1, 'batch_id' => (string) Str::uuid(), 'exported_at' => now()->toIso8601String(),
            'reviewed_by' => null, 'entries' => $entries];
    }

    public function run(array $manifest, User $actor, bool $apply = false, ?string $backup = null): array
    {
        $this->assertSchema();
        $this->validateManifest($manifest, $actor);
        $manifestHash = $this->fingerprint($manifest);
        $this->assertBatchContent($manifest['batch_id'], $manifestHash);
        $entries = collect($manifest['entries'])->sortBy('id')->values();
        foreach ($entries as $entry) {
            if ($property = Property::find($entry['id'])) $this->assertActor($actor, $property);
        }
        if ($apply) $this->writePrivateJson($backup, ['operation' => 'apply', 'manifest' => $manifest,
            'states' => $entries->mapWithKeys(fn ($entry) => [$entry['id'] => $this->snapshot($entry['id'])])->all()]);

        return $entries->map(function (array $entry) use ($manifest, $manifestHash, $actor, $apply): array {
            $id = (int) $entry['id'];
            if ($entry['decision'] === 'hold') return ['id' => $id, 'status' => 'held'];
            try {
                return DB::transaction(function () use ($id, $entry, $manifest, $manifestHash, $actor, $apply): array {
                    // One immutable manifest per batch, including after partial failure.
                    // Lock it before actor/property locks in both apply and rollback.
                    if ($apply) DB::table('property_publication_repair_batches')->insertOrIgnore([
                        'batch_id' => $manifest['batch_id'], 'actor_id' => $actor->id,
                        'manifest_hash' => $manifestHash, 'created_at' => now(),
                    ]);
                    $this->assertBatchContent($manifest['batch_id'], $manifestHash, true);
                    [$actor, $property] = app(PropertyWriteLock::class)->acquire($actor, $id);
                    $this->assertActor($actor, $property);
                    $receipt = DB::table('property_publication_repairs')->where('batch_id', $manifest['batch_id'])->where('property_id', $id)->first();
                    if ($receipt) {
                        $this->require($receipt->manifest_hash === $manifestHash, 'BATCH_CONTENT_CHANGED');
                        $this->require($receipt->rolled_back_at === null, 'BATCH_ALREADY_ROLLED_BACK');
                        return ['id' => $id, 'status' => 'already_applied'];
                    }
                    $before = $this->snapshot($id, true);
                    $this->require($before !== null && hash_equals($entry['expected_fingerprint'], $this->fingerprint($before)), 'RECORD_CHANGED_SINCE_REVIEW');
                    $this->assertNoBlockingReview($id);
                    $updates = $this->updates($entry, $before['property']);
                    $this->require($this->changes($before['property'], $updates) !== [], 'NO_REPAIR_NEEDED');
                    $updates['moderation_version'] = (int) $property->moderation_version + 1;
                    $updates['updated_at'] = now()->toDateTimeString();
                    $changes = $this->changes($before['property'], $updates);
                    $wasPublic = Property::whereKey($id)->publicSearchable()->exists();
                    $result = ['id' => $id, 'status' => $apply ? 'applied' : 'ready', 'changes' => $changes,
                        'public_before' => $wasPublic, 'public_after' => false,
                        'sold_at' => $before['property']['sold_at'] ?? null,
                        'report_effect' => ($before['property']['sold_at'] ?? null) === null ? 'closure_date_unknown' : 'existing_sale_date_preserved'];
                    if (! $apply) return $result;

                    // Deliberately no Eloquent sale observers: this is an audited
                    // historical correction, not a new sale, promotion or listing refresh.
                    DB::table('properties')->where('id', $id)->update($updates);
                    $this->audit($id, $actor, $manifest['batch_id'], 'publication_state_reconciled', $changes, $entry['reason'], $entry['evidence']);
                    $this->require(Property::whereKey($id)->publicSearchable()->exists() === $result['public_after'], 'UNEXPECTED_PUBLIC_VISIBILITY');
                    DB::table('property_publication_repairs')->insert([
                        'batch_id' => $manifest['batch_id'], 'property_id' => $id, 'actor_id' => $actor->id,
                        'manifest_hash' => $manifestHash, 'before_state' => $this->json($before),
                        'changes' => $this->json($changes), 'after_fingerprint' => $this->fingerprint($this->snapshot($id, true)),
                        'created_at' => now(),
                    ]);
                    return $result;
                });
            } catch (\DomainException $e) {
                return ['id' => $id, 'status' => 'skipped', 'reason' => $e->getMessage()];
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                return ['id' => $id, 'status' => 'skipped', 'reason' => 'PROPERTY_NOT_FOUND'];
            }
        })->all();
    }

    public function rollback(string $batchId, User $actor, bool $apply = false, ?string $backup = null): array
    {
        $this->assertSchema();
        Validator::make(['batch_id' => $batchId], ['batch_id' => 'required|uuid'])->validate();
        $receipts = DB::table('property_publication_repairs')->where('batch_id', $batchId)->orderBy('property_id')->get();
        $this->require($receipts->isNotEmpty(), 'BATCH_NOT_FOUND');
        foreach ($receipts as $receipt) {
            $this->require(in_array((int) $receipt->property_id, self::IDS, true), 'PROPERTY_OUTSIDE_REPAIR_SCOPE');
            $this->assertActor($actor, Property::findOrFail($receipt->property_id));
        }
        if ($apply) $this->writePrivateJson($backup, ['operation' => 'rollback', 'batch_id' => $batchId,
            'states' => $receipts->mapWithKeys(fn ($receipt) => [$receipt->property_id => $this->snapshot($receipt->property_id)])->all()]);
        return $receipts->map(function ($receipt) use ($batchId, $actor, $apply): array {
            $id = (int) $receipt->property_id;
            try {
                return DB::transaction(function () use ($id, $receipt, $batchId, $actor, $apply): array {
                    DB::table('property_publication_repair_batches')->where('batch_id', $batchId)->lockForUpdate()->firstOrFail();
                    [$actor, $property] = app(PropertyWriteLock::class)->acquire($actor, $id);
                    $this->assertActor($actor, $property);
                    $receipt = DB::table('property_publication_repairs')->where('id', $receipt->id)->lockForUpdate()->first();
                    if ($receipt->rolled_back_at !== null) return ['id' => $id, 'status' => 'already_rolled_back'];
                    $current = $this->snapshot($id, true);
                    $this->require(hash_equals($receipt->after_fingerprint, $this->fingerprint($current)), 'RECORD_CHANGED_AFTER_REPAIR');
                    $updates = [];
                    foreach (json_decode($receipt->changes, true, flags: JSON_THROW_ON_ERROR) as $field => $change) {
                        if (! in_array($field, ['moderation_version', 'updated_at'], true)) $updates[$field] = $change['old'];
                    }
                    $updates['moderation_version'] = (int) $property->moderation_version + 1;
                    $updates['updated_at'] = now()->toDateTimeString();
                    $changes = $this->changes($current['property'], $updates);
                    if (! $apply) return ['id' => $id, 'status' => 'ready_to_rollback', 'changes' => $changes];
                    DB::table('properties')->where('id', $id)->update($updates);
                    $this->audit($id, $actor, $batchId, 'publication_repair_rolled_back', $changes, 'Отменено техническое исправление публикации.', 'batch:'.$batchId);
                    DB::table('property_publication_repairs')->where('id', $receipt->id)->update(['rolled_back_at' => now(), 'rollback_actor_id' => $actor->id]);
                    return ['id' => $id, 'status' => 'rolled_back'];
                });
            } catch (\DomainException $e) {
                return ['id' => $id, 'status' => 'skipped', 'reason' => $e->getMessage()];
            }
        })->all();
    }

    private function updates(array $entry, array $current): array
    {
        $deal = $entry['deal_status'];
        $legacySlug = DB::table('property_statuses')->where('id', $current['status_id'])->value('slug');
        $this->require($current['deal_status'] === $deal || ($current['deal_status'] === 'available' && $legacySlug === $deal), 'CLOSED_DEAL_EVIDENCE_CONFLICT');
        $this->require($current['moderation_status'] === 'approved'
            && in_array($current['publication_status'], ['published', 'archived'], true), 'MODERATION_STATE_CHANGED');
        return ['deal_status' => $deal, 'publication_status' => 'archived', 'moderation_status' => $deal];
    }

    private function audit(int $id, User $actor, string $batch, string $action, array $changes, string $reason, string $evidence): void
    {
        DB::table('property_logs')->insert(['property_id' => $id, 'user_id' => $actor->id, 'action' => $action,
            'comment' => $reason.' [batch:'.$batch.']', 'changes' => $this->json($changes), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('property_moderation_events')->insert(['property_id' => $id, 'event_type' => $action,
            'actor_id' => $actor->id, 'actor_role' => $actor->role?->slug, 'request_id' => $batch,
            'payload' => $this->json(['batch_id' => $batch, 'reason' => $reason, 'evidence' => $evidence, 'changes' => $changes]), 'created_at' => now()]);
        if (isset($changes['moderation_status'])) {
            DB::table('property_status_history')->insert(['property_id' => $id, 'from_status' => $changes['moderation_status']['old'],
                'to_status' => $changes['moderation_status']['new'], 'changed_by' => $actor->id, 'changed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function snapshot(int $id, bool $lock = false): ?array
    {
        $query = DB::table('properties')->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row) return null;
        $state = ['property' => (array) $row, 'relations' => []];
        foreach (self::RELATED_TABLES as $table) {
            if (! Schema::hasTable($table)) continue;
            $query = DB::table($table)->where('property_id', $id);
            $rows = ($lock ? $query->lockForUpdate() : $query)->get()->map(fn ($row) => (array) $row)->all();
            usort($rows, fn ($a, $b) => strcmp($this->json($a), $this->json($b)));
            $state['relations'][$table] = $rows;
        }
        $query = DB::table('property_duplicate_candidates')->whereIn('moderation_case_id',
            DB::table('property_moderation_cases')->where('property_id', $id)->select('id'));
        $state['relations']['property_duplicate_candidates'] = ($lock ? $query->lockForUpdate() : $query)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        return $state;
    }

    public function writePrivateJson(?string $path, array $data): void
    {
        $directory = $path ? realpath(dirname($path)) : false;
        $public = realpath(public_path());
        $this->require($path && str_starts_with($path, '/') && $directory && $directory !== $public
            && (! $public || ! str_starts_with($directory.'/', $public.'/')), 'PRIVATE_BACKUP_PATH_REQUIRED');
        $contents = $this->json($data);
        $file = @fopen($path, 'x');
        $this->require($file !== false, 'BACKUP_PATH_MUST_BE_NEW');
        try {
            $this->require(chmod($path, 0600), 'CANNOT_PROTECT_BACKUP');
            for ($offset = 0; $offset < strlen($contents); $offset += $written) {
                $written = fwrite($file, substr($contents, $offset));
                $this->require($written !== false && $written > 0, 'BACKUP_WRITE_FAILED');
            }
            $this->require(fflush($file) && fsync($file), 'BACKUP_FLUSH_FAILED');
        } finally {
            fclose($file);
        }
    }

    public function fingerprint(array $value): string
    {
        return hash('sha256', $this->json($value));
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function changes(array $before, array $updates): array
    {
        $changes = [];
        foreach ($updates as $field => $value) {
            if (($before[$field] ?? null) !== $value) $changes[$field] = ['old' => $before[$field] ?? null, 'new' => $value];
        }
        return $changes;
    }

    private function assertSchema(): void
    {
        foreach (['property_publication_repair_batches', 'property_publication_repairs', 'property_logs', 'property_status_history', 'property_moderation_events',
            'property_moderation_cases', 'property_duplicate_candidates', 'property_statuses', 'building_types'] as $table) {
            $this->require(Schema::hasTable($table), 'REPAIR_SCHEMA_NOT_READY:'.$table);
        }
        $this->require(Schema::hasColumn('properties', 'building_type_id'), 'BUILDING_TYPE_SCHEMA_NOT_READY');
    }

    private function assertBatchContent(string $batchId, string $manifestHash, bool $lock = false): void
    {
        $query = DB::table('property_publication_repair_batches')->where('batch_id', $batchId);
        $batch = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $batch) return;
        $this->require(hash_equals($batch->manifest_hash, $manifestHash), 'BATCH_CONTENT_CHANGED');
        $this->require(! DB::table('property_publication_repairs')->where('batch_id', $batchId)->whereNotNull('rolled_back_at')->exists(), 'BATCH_ALREADY_ROLLED_BACK');
    }

    private function assertActor(User $actor, Property $property): void
    {
        $this->require($actor->status === User::STATUS_ACTIVE && ! $actor->isDeletedAccount()
            && app(PropertyModerationAccess::class)->canModerate($actor, $property), 'ACTOR_NOT_ALLOWED');
    }

    private function assertNoBlockingReview(int $id): void
    {
        $this->require(! DB::table('property_moderation_cases')->where('property_id', $id)
            ->where(fn ($q) => $q->where('status', 'open')->orWhere(fn ($q) => $q->where('status', 'rejected')->where('blocking', true)))->exists(), 'MODERATION_REVIEW_REQUIRED');
        $this->require(! DB::table('property_duplicate_candidates')->whereIn('moderation_case_id',
            DB::table('property_moderation_cases')->where('property_id', $id)->select('id'))
            ->where('decision', 'confirmed')->whereNull('reversed_at')->exists(), 'DUPLICATE_REVIEW_REQUIRED');
    }

    private function validateManifest(array $manifest, User $actor): void
    {
        Validator::make($manifest, ['schema_version' => 'required|in:1', 'batch_id' => 'required|uuid',
            'reviewed_by' => 'nullable|integer', 'entries' => 'required|array|min:1|max:8',
            'entries.*.id' => 'required|integer|distinct|in:'.implode(',', self::IDS),
            'entries.*.decision' => 'required|in:hold,confirm_closed',
        ])->validate();
        foreach ($manifest['entries'] as $entry) {
            if ($entry['decision'] === 'hold') continue;
            $this->require((int) ($manifest['reviewed_by'] ?? 0) === (int) $actor->id, 'REVIEWER_MUST_MATCH_ACTOR');
            $this->require(($entry['deal_status'] ?? null) === self::CLOSED_DEALS[(int) $entry['id']], 'DECISION_DIFFERS_FROM_CONFIRMED_INCIDENT');
            Validator::make($entry, ['expected_fingerprint' => ['required', 'regex:/^[a-f0-9]{64}$/'],
                'reason' => 'required|string|min:10|max:2000', 'evidence' => 'required|string|min:10|max:2000',
                'deal_status' => 'required|in:sold,sold_by_owner,rented',
            ])->validate();
        }
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) throw new \DomainException($message);
    }
}
