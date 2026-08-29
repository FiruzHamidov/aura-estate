<?php

namespace App\Services\Residential;

use App\Models\BuildingFloorPlan;
use App\Models\CrmAuditLog;
use App\Models\DeveloperUnitPhoto;
use App\Models\NewBuilding;
use App\Models\NewBuildingPhoto;
use App\Models\UnitLayout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Explicit operator migration. Never publishes inventory or deletes source files on copy. */
final class LegacyMediaMigration
{
    public const MODELS = [NewBuildingPhoto::class, DeveloperUnitPhoto::class, UnitLayout::class, BuildingFloorPlan::class];

    public function __construct(private MediaAssets $media) {}

    public function source(string $path): string
    {
        if ($path === '' || strlen($path) > 255 || preg_match('/(^\/|\.\.|[:\\\\\x00-\x1f])/', $path)) {
            throw new RuntimeException('unsafe_source_path');
        }
        $root = realpath(Storage::disk('public')->path(''));
        if (! $root) {
            throw new RuntimeException('public_root_missing');
        }
        $candidate = $root;
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                throw new RuntimeException('unsafe_source_path');
            }
            $candidate .= DIRECTORY_SEPARATOR.$part;
            if (is_link($candidate)) {
                throw new RuntimeException('symlink_source');
            }
        }
        $resolved = realpath($candidate);
        if (! $resolved || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) || ! is_file($resolved)) {
            throw new RuntimeException('source_missing');
        }
        if (filesize($resolved) > 10 * 1024 * 1024) {
            throw new RuntimeException('source_exceeds_10mb');
        }

        return $resolved;
    }

    public function migrate(Model $record): int
    {
        $existing = DB::table('residential_media_migrations')->where('entity_type', $record->getTable())->where('entity_id', $record->id)->first();
        if ($existing && $record->fresh()?->storage_disk === 'residential') {
            return $existing->id;
        }
        if ($record->storage_disk !== 'public' || $existing) {
            throw new RuntimeException('record_not_unmigrated_public_media');
        }
        $field = $record instanceof NewBuildingPhoto || $record instanceof DeveloperUnitPhoto ? 'path' : 'image_path';
        $sourcePath = (string) $record->$field;
        if ($record->original_path && $record->original_path !== $sourcePath) {
            throw new RuntimeException('multiple_sources_require_manual_review');
        }
        $source = $this->source($sourcePath);
        $buildingId = $record instanceof DeveloperUnitPhoto ? $record->unit?->new_building_id : $record->new_building_id;
        if (! $buildingId) {
            throw new RuntimeException('parent_missing');
        }
        $bytes = file_get_contents($source);
        if ($bytes === false) {
            throw new RuntimeException('source_unreadable');
        }
        $hash = hash('sha256', $bytes);
        $backup = 'legacy-backups/'.Str::uuid().'/source.bin';
        $disk = Storage::disk('residential');
        $disk->put($backup, $bytes);
        unset($bytes);
        $stored = [];
        try {
            if (hash_file('sha256', $disk->path($backup)) !== $hash) {
                throw new RuntimeException('backup_verification_failed');
            }
            $stored = $this->media->upload(new UploadedFile($disk->path($backup), basename($sourcePath), null, null, true), $buildingId);

            return DB::transaction(function () use ($record, $field, $sourcePath, $source, $hash, $backup, $stored, $buildingId) {
                $building = NewBuilding::query()->lockForUpdate()->findOrFail($buildingId);
                $fresh = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
                $currentBuildingId = $fresh instanceof DeveloperUnitPhoto ? $fresh->unit?->new_building_id : $fresh->new_building_id;
                if ((int) $currentBuildingId !== (int) $buildingId) {
                    throw new RuntimeException('media_parent_changed_retry_report');
                }
                if ($fresh->storage_disk !== 'public' || $sourcePath !== $fresh->$field || $fresh->version !== $record->version || hash_file('sha256', $source) !== $hash) {
                    throw new RuntimeException('media_changed_retry_report');
                }
                $old = $fresh->getAttributes();
                $next = $stored;
                if ($field === 'image_path') {
                    $next['image_path'] = $next['path'];
                    unset($next['path']);
                }
                $fresh->fill($next);
                $fresh->version = (int) $fresh->version + 1;
                $fresh->save();
                $building->increment('version');
                $id = DB::table('residential_media_migrations')->insertGetId([
                    'entity_type' => $fresh->getTable(), 'entity_id' => $fresh->id, 'new_building_id' => $buildingId,
                    'source_path' => $sourcePath, 'source_sha256' => $hash, 'backup_path' => $backup,
                    'old_values' => json_encode($old, JSON_THROW_ON_ERROR), 'new_values' => json_encode($fresh->getAttributes(), JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                CrmAuditLog::create(['auditable_type' => $fresh::class, 'auditable_id' => $fresh->id, 'event' => 'residential.media.migrated', 'context' => ['migration_id' => $id, 'source_sha256' => $hash]]);

                return $id;
            });
        } catch (\Throwable $error) {
            $this->media->discard($stored);
            $disk->delete($backup);
            throw $error;
        }
    }

    public function quarantine(int $id): void
    {
        if (! app()->environment('testing') && ! app()->isDownForMaintenance()) {
            throw new RuntimeException('maintenance_mode_required_for_quarantine');
        }
        DB::transaction(function () use ($id) {
            $row = DB::table('residential_media_migrations')->lockForUpdate()->find($id);
            if (! $row) {
                throw new RuntimeException('migration_not_found');
            }
            if ($row->quarantined_at) {
                return;
            }
            // Only the legacy upload namespaces owned by residential objects.
            // Arbitrary/shared files require a separate operator review, never automatic deletion.
            if (! preg_match('#^(new-buildings/[1-9]\d*/|units/)[a-zA-Z0-9_.-]+\.(jpe?g|png|webp|avif)$#i', $row->source_path)) {
                throw new RuntimeException('source_namespace_requires_manual_review');
            }
            $disk = Storage::disk('residential');
            if (! $disk->exists($row->backup_path) || hash_file('sha256', $disk->path($row->backup_path)) !== $row->source_sha256) {
                throw new RuntimeException('verified_backup_required');
            }
            foreach (self::MODELS as $model) {
                $field = in_array($model, [NewBuildingPhoto::class, DeveloperUnitPhoto::class], true) ? 'path' : 'image_path';
                if ($model::query()->where('storage_disk', 'public')->where(fn ($q) => $q->where($field, $row->source_path)->orWhere('original_path', $row->source_path))->exists()) {
                    throw new RuntimeException('source_still_referenced');
                }
            }
            // Missing source after a crash is recoverable from the verified backup.
            if (Storage::disk('public')->exists($row->source_path)) {
                $source = $this->source($row->source_path);
                if (hash_file('sha256', $source) !== $row->source_sha256) {
                    throw new RuntimeException('source_changed_do_not_remove');
                }
                if (! unlink($source)) {
                    throw new RuntimeException('quarantine_failed');
                }
            }
            DB::table('residential_media_migrations')->where('id', $id)->update(['quarantined_at' => now(), 'updated_at' => now()]);
        });
    }
}
