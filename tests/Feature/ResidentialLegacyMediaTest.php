<?php

namespace Tests\Feature;

use App\Models\NewBuilding;
use App\Models\NewBuildingPhoto;
use App\Models\UnitLayout;
use App\Services\Residential\LegacyMediaMigration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ResidentialSchema;
use Tests\TestCase;

class ResidentialLegacyMediaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ResidentialSchema::create();
        Storage::fake('public');
        Storage::fake('residential');
    }

    private function photo(string $path = 'new-buildings/1/test.png'): NewBuildingPhoto
    {
        $building = NewBuilding::create(['title' => 'Legacy QA', 'publication_status' => 'published']);
        $file = UploadedFile::fake()->image('source.png', 800, 600);
        Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));

        return $building->photos()->create(['path' => $path])->fresh();
    }

    public function test_report_is_read_only_copy_is_recoverable_idempotent_and_does_not_publish(): void
    {
        $photo = $this->photo();
        $old = $photo->fresh()->getAttributes();
        $bytes = Storage::disk('public')->get($photo->path);
        $this->artisan('residential:media-migrate')->assertSuccessful();
        $this->assertSame([], Storage::disk('residential')->allFiles());
        $this->assertDatabaseCount('residential_media_migrations', 0);
        $this->artisan('residential:media-migrate', ['--apply' => true])->assertExitCode(2);
        $this->artisan('residential:media-migrate', ['--apply' => true, '--confirm' => true, '--building' => $photo->new_building_id])->assertSuccessful();
        $journal = DB::table('residential_media_migrations')->first();
        $this->assertSame($bytes, Storage::disk('residential')->get($journal->backup_path));
        Storage::disk('public')->assertExists($old['path']);
        $this->assertSame($old, json_decode($journal->old_values, true));
        $photo->refresh();
        $this->assertSame('residential', $photo->storage_disk);
        $this->assertSame(2, $photo->version);
        $this->assertNotEmpty($photo->variants);
        $this->assertSame('published', $photo->newBuilding->publication_status);
        $this->assertSame(2, $photo->newBuilding->version);
        $this->assertSame($journal->id, app(LegacyMediaMigration::class)->migrate($photo));
        $this->assertDatabaseCount('residential_media_migrations', 1);
        $this->assertDatabaseHas('crm_audit_logs', ['event' => 'residential.media.migrated']);
    }

    public function test_quarantine_requires_all_public_references_migrated_and_preserves_backups(): void
    {
        $photo = $this->photo();
        $shared = $photo->newBuilding->photos()->create(['path' => $photo->path])->fresh();
        $service = app(LegacyMediaMigration::class);
        $id = $service->migrate($photo);
        try {
            $service->quarantine($id);
            $this->fail('Shared public reference must block quarantine');
        } catch (\RuntimeException $error) {
            $this->assertSame('source_still_referenced', $error->getMessage());
        }
        Storage::disk('public')->assertExists($photo->path);
        $other = $service->migrate($shared);
        $service->quarantine($id);
        $service->quarantine($other); // Shared file already removed, backup remains valid.
        $service->quarantine($id); // Idempotent retry.
        Storage::disk('public')->assertMissing($photo->path);
        foreach (DB::table('residential_media_migrations')->get() as $journal) {
            $this->assertNotNull($journal->quarantined_at);
            Storage::disk('residential')->assertExists($journal->backup_path);
        }
    }

    public function test_changed_source_or_invalid_backup_is_never_removed(): void
    {
        $photo = $this->photo();
        $service = app(LegacyMediaMigration::class);
        $id = $service->migrate($photo);
        $journal = DB::table('residential_media_migrations')->find($id);
        $original = Storage::disk('public')->get($photo->path);
        foreach (['source', 'backup'] as $target) {
            $disk = Storage::disk($target === 'source' ? 'public' : 'residential');
            $path = $target === 'source' ? $photo->path : $journal->backup_path;
            $disk->put($path, 'changed');
            try {
                $service->quarantine($id);
                $this->fail('Hash mismatch must block removal');
            } catch (\RuntimeException $error) {
                $this->assertSame($target === 'source' ? 'source_changed_do_not_remove' : 'verified_backup_required', $error->getMessage());
            }
            Storage::disk('public')->assertExists($photo->path);
            $this->assertNull(DB::table('residential_media_migrations')->find($id)->quarantined_at);
            $disk->put($path, $original);
        }
    }

    public function test_unsafe_missing_invalid_and_symlink_sources_are_blocked_without_record_changes(): void
    {
        $photo = $this->photo();
        $service = app(LegacyMediaMigration::class);
        foreach (['../private.png', '/absolute.png', 'https://example.com/a.png', 'missing.png', 'new-buildings//test.png'] as $path) {
            try {
                $service->source($path);
                $this->fail('Invalid path must fail');
            } catch (\RuntimeException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
        $alias = Storage::disk('public')->path('alias.png');
        symlink(Storage::disk('public')->path($photo->path), $alias);
        try {
            $service->source('alias.png');
            $this->fail('Symlink must fail');
        } catch (\RuntimeException $error) {
            $this->assertSame('symlink_source', $error->getMessage());
        }
        Storage::disk('public')->put($photo->path, '<svg onload="bad()"/>');
        $this->artisan('residential:media-migrate', ['--apply' => true, '--confirm' => true])->assertFailed();
        $this->assertSame('public', $photo->fresh()->storage_disk);
        $this->assertDatabaseCount('residential_media_migrations', 0);
        $this->assertSame([], Storage::disk('residential')->allFiles());
    }

    public function test_layout_uses_image_path_and_arbitrary_namespace_cannot_be_quarantined(): void
    {
        $photo = $this->photo('shared-materials/test.png');
        $layout = UnitLayout::create(['new_building_id' => $photo->new_building_id, 'code' => 'QA', 'image_path' => $photo->path])->fresh();
        $service = app(LegacyMediaMigration::class);
        $id = $service->migrate($layout);
        $layout->refresh();
        $this->assertSame('residential', $layout->storage_disk);
        Storage::disk('residential')->assertExists($layout->image_path);
        $this->expectExceptionMessage('source_namespace_requires_manual_review');
        $service->quarantine($id);
    }

    public function test_non_testing_environment_requires_maintenance_before_quarantine(): void
    {
        $photo = $this->photo();
        $id = app(LegacyMediaMigration::class)->migrate($photo);
        $maintenance = \Mockery::mock(\Illuminate\Contracts\Foundation\MaintenanceMode::class);
        $maintenance->shouldReceive('active')->once()->ordered()->andReturn(false);
        $maintenance->shouldReceive('active')->once()->ordered()->andReturn(true);
        $this->app->instance(\Illuminate\Contracts\Foundation\MaintenanceMode::class, $maintenance);
        $this->app['env'] = 'staging';
        try {
            $this->artisan('residential:media-migrate', ['--quarantine' => $id, '--confirm' => true])
                ->expectsOutput('maintenance_mode_required_for_quarantine')->assertFailed();
            Storage::disk('public')->assertExists($photo->path);
            $this->assertNull(DB::table('residential_media_migrations')->find($id)->quarantined_at);
            $this->artisan('residential:media-migrate', ['--quarantine' => $id, '--confirm' => true])->assertSuccessful();
            Storage::disk('public')->assertMissing($photo->path);
            $this->assertNotNull(DB::table('residential_media_migrations')->find($id)->quarantined_at);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_rollback_cannot_drop_a_nonempty_recovery_journal(): void
    {
        $photo = $this->photo();
        $id = app(LegacyMediaMigration::class)->migrate($photo);
        $journal = DB::table('residential_media_migrations')->find($id);
        $privateFiles = Storage::disk('residential')->allFiles();
        try {
            (require database_path('migrations/2026_08_28_210000_create_residential_media_migrations.php'))->down();
            $this->fail('Rollback must retain the recovery journal');
        } catch (\RuntimeException $error) {
            $this->assertSame('Keep the media migration journal and recovery metadata during rollback.', $error->getMessage());
        }
        $this->assertEquals($journal, DB::table('residential_media_migrations')->find($id));
        $this->assertSame($privateFiles, Storage::disk('residential')->allFiles());
        $this->assertSame('residential', $photo->fresh()->storage_disk);
    }
}
