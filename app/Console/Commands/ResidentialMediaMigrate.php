<?php

namespace App\Console\Commands;

use App\Models\DeveloperUnitPhoto;
use App\Models\NewBuildingPhoto;
use App\Services\Residential\LegacyMediaMigration;
use Illuminate\Console\Command;

class ResidentialMediaMigrate extends Command
{
    protected $signature = 'residential:media-migrate
        {--building= : Limit report/copy to this complex ID}
        {--limit=100 : Maximum rows per media type, 1–1000}
        {--apply : Copy, re-encode and switch records to private storage; retain public originals}
        {--quarantine= : Remove exactly one journaled public original, retaining verified private backup; maintenance required}
        {--confirm : Confirm the explicit copy/quarantine operation}';

    protected $description = 'Report or migrate legacy residential media with verified backups and a recovery journal.';

    public function handle(LegacyMediaMigration $migration): int
    {
        $building = $this->option('building');
        $limit = (string) $this->option('limit');
        $quarantine = $this->option('quarantine');
        if (($building !== null && ! preg_match('/^[1-9]\d*$/', $building)) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 1000 || ($quarantine !== null && ! preg_match('/^[1-9]\d*$/', $quarantine))) {
            $this->error('Use positive integer IDs and limit 1–1000.');

            return self::INVALID;
        }
        if (($this->option('apply') || $quarantine !== null) && ! $this->option('confirm')) {
            $this->error('Read the report and provide --confirm for an explicit mutation.');

            return self::INVALID;
        }
        if ($quarantine !== null) {
            if ($this->option('apply') || $building !== null) {
                $this->error('Quarantine is a separate operation identified by journal ID.');

                return self::INVALID;
            }
            try {
                $migration->quarantine((int) $quarantine);
                $this->info('Public original quarantined; private backup and journal retained.');

                return self::SUCCESS;
            } catch (\Throwable $error) {
                $this->error($this->safeReason($error));

                return self::FAILURE;
            }
        }
        $failed = false;
        foreach (LegacyMediaMigration::MODELS as $model) {
            $field = in_array($model, [NewBuildingPhoto::class, DeveloperUnitPhoto::class], true) ? 'path' : 'image_path';
            $query = $model::query()->where('storage_disk', 'public')->whereNotNull($field)->where($field, '!=', '');
            if ($building !== null) {
                $model === DeveloperUnitPhoto::class
                    ? $query->whereHas('unit', fn ($q) => $q->where('new_building_id', $building))
                    : $query->where('new_building_id', $building);
            }
            foreach ($query->orderBy('id')->limit((int) $limit)->get() as $record) {
                $result = ['entity' => $record->getTable(), 'id' => $record->id];
                try {
                    $migration->source($record->$field);
                    $result['status'] = $this->option('apply') ? 'copied_public_original_retained' : 'candidate_requires_image_validation';
                    if ($this->option('apply')) {
                        $result['migration_id'] = $migration->migrate($record);
                    }
                } catch (\Throwable $error) {
                    $failed = true;
                    $result['status'] = 'blocked';
                    $result['reason'] = $this->safeReason($error);
                }
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function safeReason(\Throwable $error): string
    {
        if ($error instanceof \Illuminate\Validation\ValidationException) {
            return 'invalid_image';
        }

        return $error instanceof \RuntimeException && preg_match('/^[a-z_]{1,80}$/', $error->getMessage()) ? $error->getMessage() : 'migration_failed';
    }
}
