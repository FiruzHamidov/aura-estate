<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Http\Middleware\LogApiRequest;
use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use App\Models\ResidentialImportBatch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ResidentialSchema;
use Tests\TestCase;

class ResidentialImportTest extends TestCase
{
    private NewBuilding $building;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        ResidentialSchema::create();
        (require database_path('migrations/2026_08_28_190000_create_residential_import_batches.php'))->up();
        $this->withoutMiddleware([EnsureDailyReportSubmitted::class, LogApiRequest::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
        Sanctum::actingAs($this->actor());
        $this->building = NewBuilding::create(['title' => 'QA import', 'publication_status' => 'published']);
        $this->path = '/api/admin/new-buildings/'.$this->building->id.'/imports';
    }

    private function actor(string $slug = 'admin', ?int $branch = null): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);

        return User::create(['name' => 'QA '.$slug, 'phone' => '+992'.random_int(100000000, 999999999), 'role_id' => $role->id, 'branch_id' => $branch]);
    }

    private function csv(string $content)
    {
        return $this->postJson($this->path.'/preview', ['mode' => 'csv', 'file' => UploadedFile::fake()->createWithContent('units.csv', $content)]);
    }

    private function unit(array $values = []): DeveloperUnit
    {
        return DeveloperUnit::create(['new_building_id' => $this->building->id, 'external_id' => 'QA-'.random_int(1, 999999), 'name' => 'QA unit', 'area' => '50', 'rooms' => 2, 'bedrooms' => 2, 'publication_status' => 'published', 'availability_status' => 'available', 'moderation_status' => 'available', 'is_available' => true, 'pricing_basis' => 'per_sqm', 'price_per_sqm' => '1000', 'total_price' => '50000', 'price_on_request' => false, ...$values]);
    }

    public function test_csv_preview_rolls_back_and_confirmed_apply_is_atomic_idempotent_and_repeat_import_is_unchanged(): void
    {
        $csv = "\xEF\xBB\xBFexternal_id;name;area;rooms;total_price;publication_status\n0;\"Квартира; QA\";50,5;2;50500;published\n";
        $id = $this->csv($csv)->assertCreated()->assertJsonPath('status', 'preview')->assertJsonPath('counts.created', 1)->json('id');
        $this->assertDatabaseCount('developer_units', 0);
        $this->assertDatabaseCount('crm_audit_logs', 0);
        $this->postJson($this->path.'/'.$id.'/apply', [])->assertUnprocessable();
        $this->postJson($this->path.'/'.$id.'/apply', ['confirmed' => true])->assertOk()->assertJsonPath('status', 'applied');
        $this->assertDatabaseHas('developer_units', ['external_id' => '0', 'area' => 50.5, 'price_per_sqm' => 1000]);
        $audit = DB::table('crm_audit_logs')->count();
        $this->postJson($this->path.'/'.$id.'/apply', ['confirmed' => true])->assertOk();
        $this->assertDatabaseCount('developer_units', 1);
        $this->assertDatabaseCount('crm_audit_logs', $audit);
        $again = $this->csv($csv)->assertCreated()->assertJsonPath('counts.unchanged', 1)->json('id');
        $version = DeveloperUnit::first()->version;
        $this->postJson($this->path.'/'.$again.'/apply', ['confirmed' => true])->assertOk();
        $this->assertSame($version, DeveloperUnit::first()->version);
        $this->getJson($this->path)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_errors_and_duplicate_external_ids_block_entire_batch_and_keep_original_values(): void
    {
        $unit = $this->unit(['external_id' => 'QA-A']);
        $id = $this->csv("external_id;area;total_price\nqa-a;50;60000\nQA-A;50;70000\nQA-new;-1;200\n")->assertCreated()->assertJsonPath('status', 'invalid')->assertJsonPath('counts.errors', 2)->json('id');
        $this->assertDatabaseCount('developer_units', 1);
        $this->assertEquals('50000.00', $unit->fresh()->total_price);
        $this->postJson($this->path.'/'.$id.'/apply', ['confirmed' => true])->assertConflict();
        $this->assertDatabaseCount('crm_audit_logs', 0);
    }

    public function test_bulk_price_switches_basis_and_stale_second_row_rolls_back_first(): void
    {
        $one = $this->unit();
        $two = $this->unit();
        $payload = ['mode' => 'bulk', 'unit_ids' => [$one->id, $two->id], 'changes' => ['total_price' => '75000.55']];
        $id = $this->postJson($this->path.'/preview', $payload)->assertCreated()->assertJsonPath('counts.updated', 2)->json('id');
        $two->increment('version');
        $this->postJson($this->path.'/'.$id.'/apply', ['confirmed' => true])->assertConflict();
        $this->assertEquals('50000.00', $one->fresh()->total_price);
        $this->assertSame(1, $one->fresh()->version);
        $this->assertDatabaseCount('crm_audit_logs', 0);
        $id = $this->postJson($this->path.'/preview', $payload)->assertCreated()->json('id');
        $this->postJson($this->path.'/'.$id.'/apply', ['confirmed' => true])->assertOk();
        $this->assertEquals('75000.55', $one->fresh()->total_price);
        $this->assertEquals('1500.01', $one->fresh()->price_per_sqm);
        $this->assertSame('total', $one->fresh()->pricing_basis);
        $this->assertDatabaseHas('crm_audit_logs', ['event' => 'residential.import.applied']);
    }

    public function test_expired_parent_changed_and_external_id_collision_previews_do_not_apply(): void
    {
        $csv = "external_id;name;area;price_on_request\nQA-new;QA new;40;true\n";
        $expired = $this->csv($csv)->assertCreated()->json('id');
        ResidentialImportBatch::findOrFail($expired)->update(['expires_at' => now()->subSecond()]);
        $this->postJson($this->path.'/'.$expired.'/apply', ['confirmed' => true])->assertConflict();
        $stale = $this->csv($csv)->assertCreated()->json('id');
        $this->building->increment('version');
        $this->postJson($this->path.'/'.$stale.'/apply', ['confirmed' => true])->assertConflict();
        $collision = $this->csv($csv)->assertCreated()->json('id');
        $this->unit(['external_id' => 'qa-new']);
        $this->postJson($this->path.'/'.$collision.'/apply', ['confirmed' => true])->assertConflict();
        $this->assertDatabaseCount('developer_units', 1);
    }

    public function test_batch_is_actor_and_parent_scoped_and_authors_cannot_import(): void
    {
        $id = $this->csv("external_id;name;area;price_on_request\nQA-1;QA;50;true\n")->assertCreated()->json('id');
        Sanctum::actingAs($this->actor());
        $this->getJson($this->path.'/'.$id)->assertNotFound();
        $this->postJson($this->path.'/'.$id.'/apply', ['confirmed' => true])->assertNotFound();
        $this->getJson($this->path)->assertOk()->assertJsonCount(0, 'data');
        foreach (['agent', 'mop', 'hr', 'accountant', 'client', 'rop', 'branch_director'] as $role) {
            Sanctum::actingAs($actor = $this->actor($role));
            $this->building->update(['created_by' => $actor->id]);
            $this->getJson($this->path)->assertForbidden();
            $this->csv("external_id;name;area\nQA;QA;50\n")->assertForbidden();
        }
        $this->assertDatabaseCount('developer_units', 0);
    }

    public static function structureChanges(): array
    {
        $cases = [];
        foreach (['blocks', 'entrances', 'layouts', 'floor-plans'] as $kind) {
            foreach (['create', 'update', 'remove'] as $operation) {
                $cases[$kind.' '.$operation] = [$kind, $operation];
            }
        }

        return $cases;
    }

    #[DataProvider('structureChanges')]
    public function test_structure_changes_invalidate_preview_without_touching_lots(string $kind, string $operation): void
    {
        $block = $this->building->blocks()->create(['name' => 'A', 'floors_from' => 1, 'floors_to' => 5]);
        $entrance = $this->building->entrances()->create(['block_id' => $block->id, 'name' => '1', 'residential_floor_from' => 1, 'residential_floor_to' => 5]);
        $layout = $this->building->layouts()->create(['code' => 'A2', 'rooms' => 2, 'typical_area' => 50]);
        $plan = $kind === 'floor-plans' ? $this->building->floorPlans()->create(['block_id' => $block->id, 'entrance_id' => $entrance->id, 'floor_from' => 1, 'floor_to' => 1]) : null;
        $unit = $this->unit()->fresh();
        $before = $unit->getAttributes();
        $payload = ['mode' => 'bulk', 'unit_ids' => [$unit->id], 'changes' => ['total_price' => '75000']];
        $batch = $this->postJson($this->path.'/preview', $payload)->assertCreated()->assertJsonPath('status', 'preview')->json('id');
        $record = match ($kind) {
            'blocks' => $block, 'entrances' => $entrance, 'layouts' => $layout, 'floor-plans' => $plan,
        };
        $values = match ($kind) {
            'blocks' => ['name' => 'B', 'floors_from' => 1, 'floors_to' => 5],
            'entrances' => ['name' => '2', 'block_id' => $block->id, 'residential_floor_from' => 1, 'residential_floor_to' => 5],
            'layouts' => ['code' => 'A3', 'rooms' => 3, 'typical_area' => 60],
            'floor-plans' => ['block_id' => $block->id, 'entrance_id' => $entrance->id, 'floor_from' => 2, 'floor_to' => 2],
        };
        $path = ($kind === 'blocks' ? '/api/new-buildings/' : '/api/admin/new-buildings/').$this->building->id.'/'.$kind;
        match ($operation) {
            'create' => $this->postJson($path, $values)->assertCreated(),
            'update' => $this->patchJson($path.'/'.$record->id, $values + ['version' => 1])->assertOk(),
            'remove' => $this->deleteJson($path.'/'.$record->id, ['version' => 1])->assertNoContent(),
        };
        $this->assertSame($before, $unit->fresh()->getAttributes());
        $afterChange = DB::table('crm_audit_logs')->get()->toJson();
        $batchBefore = ResidentialImportBatch::findOrFail($batch)->getAttributes();
        $this->postJson($this->path.'/'.$batch.'/apply', ['confirmed' => true])->assertConflict();
        $this->assertSame($before, $unit->fresh()->getAttributes());
        $this->assertSame($batchBefore, ResidentialImportBatch::findOrFail($batch)->getAttributes());
        $this->assertSame($afterChange, DB::table('crm_audit_logs')->get()->toJson());
        $this->assertSame(2, $this->building->fresh()->version);
        $this->assertSame('published', $this->building->fresh()->publication_status);

        // A fresh confirmation remains usable after the structural edit.
        $fresh = $this->postJson($this->path.'/preview', $payload)->assertCreated()->assertJsonPath('status', 'preview')->json('id');
        $this->postJson($this->path.'/'.$fresh.'/apply', ['confirmed' => true])->assertOk();
        $this->assertSame('75000.00', $unit->fresh()->total_price);
        $this->assertSame(2, $unit->fresh()->version);
    }

    public static function moderators(): array
    {
        return [['admin', true], ['superadmin', true], ['owner', true], ['rop', false], ['branch_director', false]];
    }

    #[DataProvider('moderators')]
    public function test_moderators_import_only_within_their_current_scope(string $role, bool $global): void
    {
        $branch = DB::table('branches')->insertGetId(['name' => 'QA local']);
        $foreignBranch = DB::table('branches')->insertGetId(['name' => 'QA foreign']);
        Sanctum::actingAs($this->actor($role, $branch));
        foreach ([$branch, $foreignBranch] as $buildingBranch) {
            $building = NewBuilding::create(['title' => 'QA import scope', 'publication_status' => 'published', 'branch_id' => $buildingBranch]);
            $path = '/api/admin/new-buildings/'.$building->id.'/imports';
            $payload = ['mode' => 'csv', 'file' => UploadedFile::fake()->createWithContent('scope.csv', "external_id;name;area;rooms;price_on_request\nQA-scope;QA unit;50;2;true\n")];
            $before = DeveloperUnit::count();
            if (! $global && $buildingBranch === $foreignBranch) {
                $this->getJson($path)->assertForbidden();
                $this->postJson($path.'/preview', $payload)->assertForbidden();
                $this->assertDatabaseCount('developer_units', $before);
                $this->assertDatabaseMissing('residential_import_batches', ['new_building_id' => $building->id]);

                continue;
            }
            $this->getJson($path)->assertOk()->assertJsonCount(0, 'data');
            $batch = $this->postJson($path.'/preview', $payload)->assertCreated()->assertJsonPath('status', 'preview')->json('id');
            $this->assertDatabaseCount('developer_units', $before);
            $this->getJson($path.'/'.$batch)->assertOk()->assertJsonPath('id', $batch);
            $this->postJson($path.'/'.$batch.'/apply', ['confirmed' => true])->assertOk()->assertJsonPath('status', 'applied');
            $this->assertDatabaseCount('developer_units', $before + 1);
            $this->assertDatabaseHas('developer_units', ['new_building_id' => $building->id, 'external_id' => 'QA-scope']);
            $audit = DB::table('crm_audit_logs')->count();
            $this->postJson($path.'/'.$batch.'/apply', ['confirmed' => true])->assertOk();
            $this->assertDatabaseCount('developer_units', $before + 1);
            $this->assertDatabaseCount('crm_audit_logs', $audit);
        }
    }

    public function test_demoted_author_cannot_read_or_apply_an_existing_preview_even_when_assigned(): void
    {
        $owner = $this->actor('owner');
        $ownerRole = $owner->role_id;
        $this->building->update(['created_by' => $owner->id, 'responsible_agent_id' => $owner->id]);
        Sanctum::actingAs($owner);
        $unit = $this->unit();
        $payload = ['mode' => 'bulk', 'unit_ids' => [$unit->id], 'changes' => ['total_price' => '90000']];
        $id = $this->postJson($this->path.'/preview', $payload)->assertCreated()->assertJsonPath('status', 'preview')->json('id');
        $snapshot = $unit->fresh()->getAttributes();
        $batch = ResidentialImportBatch::findOrFail($id)->getAttributes();
        foreach (['agent', 'mop', 'hr', 'accountant', 'client', 'external_agent', 'unknown'] as $slug) {
            $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $owner->update(['role_id' => $role->id]);
            Sanctum::actingAs($owner->fresh());
            $this->getJson($this->path)->assertForbidden();
            $this->getJson($this->path.'/'.$id)->assertForbidden();
            $this->postJson($this->path.'/preview', $payload)->assertForbidden();
            $this->postJson($this->path.'/'.$id.'/apply', ['confirmed' => true])->assertForbidden();
            $this->assertSame($snapshot, $unit->fresh()->getAttributes());
            $this->assertSame($batch, ResidentialImportBatch::findOrFail($id)->getAttributes());
            $this->assertDatabaseCount('residential_import_batches', 1);
            $this->assertDatabaseCount('crm_audit_logs', 0);
        }
        $owner->update(['role_id' => $ownerRole]);
        Sanctum::actingAs($owner->fresh());
        $this->postJson($this->path.'/'.$id.'/apply', ['confirmed' => true])->assertOk()->assertJsonPath('status', 'applied');
        $this->assertEquals('90000.00', $unit->fresh()->total_price);
    }

    public function test_branch_reassignment_revokes_existing_preview_without_changing_inventory(): void
    {
        $branch = DB::table('branches')->insertGetId(['name' => 'QA local']);
        $foreignBranch = DB::table('branches')->insertGetId(['name' => 'QA foreign']);
        $this->building->update(['branch_id' => $branch]);
        $unit = $this->unit();
        foreach (['rop', 'branch_director'] as $role) {
            $actor = $this->actor($role, $branch);
            Sanctum::actingAs($actor);
            $id = $this->postJson($this->path.'/preview', ['mode' => 'bulk', 'unit_ids' => [$unit->id], 'changes' => ['total_price' => '90000']])->assertCreated()->json('id');
            $snapshot = $unit->fresh()->getAttributes();
            $batch = ResidentialImportBatch::findOrFail($id)->getAttributes();
            foreach ([$foreignBranch, null] as $newBranch) {
                $actor->update(['branch_id' => $newBranch]);
                Sanctum::actingAs($actor->fresh());
                $this->getJson($this->path)->assertForbidden();
                $this->getJson($this->path.'/'.$id)->assertForbidden();
                $this->postJson($this->path.'/'.$id.'/apply', ['confirmed' => true])->assertForbidden();
                $this->assertSame($snapshot, $unit->fresh()->getAttributes());
                $this->assertSame($batch, ResidentialImportBatch::findOrFail($id)->getAttributes());
                $this->assertDatabaseCount('crm_audit_logs', 0);
            }
            $actor->update(['branch_id' => $branch]);
            Sanctum::actingAs($actor->fresh());
            $this->getJson($this->path.'/'.$id)->assertOk();
        }
    }

    public function test_csv_structure_limits_and_report_pagination(): void
    {
        foreach (["bad;name\na;b", "external_id;external_id\na;b", "external_id,name\na,b"] as $csv) {
            $this->csv($csv)->assertUnprocessable();
        }
        $this->csv("external_id;name\nQA;QA;extra\n")->assertCreated()->assertJsonPath('counts.errors', 1);
        $rows = '';
        for ($i = 1; $i <= 21; $i++) {
            $rows .= "QA-$i;QA $i;50;true\n";
        }
        $id = $this->csv("external_id;name;area;price_on_request\n".$rows)->assertCreated()->assertJsonCount(20, 'data')->assertJsonPath('last_page', 2)->json('id');
        $this->getJson($this->path.'/'.$id.'?page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->csv("external_id\n".str_repeat("QA\n", 1001))->assertUnprocessable();
        $this->assertDatabaseCount('developer_units', 0);
    }

    public function test_bulk_rejects_ambiguous_price_outside_parent_and_archived_parent(): void
    {
        $this->csv("external_id;name;area\nQA-new;QA;50\n")->assertCreated()->assertJsonPath('status', 'invalid')->assertJsonPath('counts.errors', 1);
        $unit = $this->unit();
        $base = ['mode' => 'bulk', 'unit_ids' => [$unit->id]];
        $this->postJson($this->path.'/preview', $base + ['changes' => ['total_price' => 1, 'price_per_sqm' => 2]])->assertUnprocessable();
        $this->postJson($this->path.'/preview', $base + ['changes' => ['pricing_basis' => 'per_sqm', 'total_price' => 200]])->assertCreated()->assertJsonPath('status', 'invalid');
        $this->postJson($this->path.'/preview', ['mode' => 'bulk', 'unit_ids' => [99999], 'changes' => ['availability_status' => 'sold']])->assertCreated()->assertJsonPath('status', 'invalid');
        $id = $this->postJson($this->path.'/preview', $base + ['changes' => ['price_on_request' => true]])->assertCreated()->json('id');
        $this->postJson($this->path.'/'.$id.'/apply', ['confirmed' => true])->assertOk();
        $this->assertNull($unit->fresh()->total_price);
        $this->building->update(['publication_status' => 'archived']);
        $this->postJson($this->path.'/preview', $base + ['changes' => ['availability_status' => 'sold']])->assertConflict();
    }

    public function test_maximum_csv_batch_preserves_unique_lots_and_repeat_is_unchanged(): void
    {
        $csv = "external_id;name;area;rooms;total_price;publication_status\n";
        for ($i = 1; $i <= 1000; $i++) {
            $csv .= "QA-MAX-$i;QA lot $i;50.50;2;50000.55;draft\n";
        }
        $parentVersion = $this->building->fresh()->version;
        $started = microtime(true);
        $preview = $this->csv($csv)->assertCreated()->assertJsonPath('status', 'preview')
            ->assertJsonPath('counts.total', 1000)->assertJsonPath('counts.created', 1000)
            ->assertJsonPath('counts.errors', 0)->assertJsonPath('last_page', 50)->assertJsonCount(20, 'data');
        $previewMs = (microtime(true) - $started) * 1000;
        $this->assertLessThan(200 * 1024, strlen($preview->getContent()));
        $this->assertDatabaseCount('developer_units', 0);
        $this->assertDatabaseCount('crm_audit_logs', 0);
        $this->assertSame($parentVersion, $this->building->fresh()->version);
        $id = $preview->json('id');
        $this->getJson($this->path.'/'.$id.'?page=50')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('data.19.external_id', 'QA-MAX-1000');

        $started = microtime(true);
        $this->postJson($this->path.'/'.$id.'/apply', ['confirmed' => true])->assertOk()->assertJsonPath('status', 'applied')->assertJsonCount(20, 'data');
        $applyMs = (microtime(true) - $started) * 1000;
        $this->assertDatabaseCount('developer_units', 1000);
        $this->assertSame(1000, DeveloperUnit::query()->distinct()->count('external_id'));
        $this->assertSame(1000, DeveloperUnit::query()->where('publication_status', 'draft')->where('version', 1)->count());
        $this->assertSame('50000.55', DeveloperUnit::query()->where('external_id', 'QA-MAX-1000')->firstOrFail()->total_price);
        $this->assertSame('990.11', DeveloperUnit::query()->where('external_id', 'QA-MAX-1000')->firstOrFail()->price_per_sqm);
        $auditCount = DB::table('crm_audit_logs')->count();
        $parentVersion = $this->building->fresh()->version;
        $this->postJson($this->path.'/'.$id.'/apply', ['confirmed' => true])->assertOk();
        $this->assertDatabaseCount('developer_units', 1000);
        $this->assertDatabaseCount('crm_audit_logs', $auditCount);
        $this->assertSame($parentVersion, $this->building->fresh()->version);

        $started = microtime(true);
        $repeat = $this->csv($csv)->assertCreated()->assertJsonPath('counts.unchanged', 1000)->assertJsonPath('counts.created', 0)->assertJsonPath('counts.updated', 0)->assertJsonPath('counts.errors', 0)->json('id');
        $repeatMs = (microtime(true) - $started) * 1000;
        $this->postJson($this->path.'/'.$repeat.'/apply', ['confirmed' => true])->assertOk();
        $this->assertSame(1000, DeveloperUnit::query()->where('version', 1)->count());
        $this->assertDatabaseCount('developer_units', 1000);
        $this->assertSame($parentVersion, $this->building->fresh()->version);
        fwrite(STDERR, sprintf("\nCSV 1000 rows: preview %.0f ms, apply %.0f ms, unchanged preview %.0f ms, response %d bytes, peak %.1f MiB\n", $previewMs, $applyMs, $repeatMs, strlen($preview->getContent()), memory_get_peak_usage(true) / 1048576));
    }

    public function test_conflict_in_last_of_1000_bulk_rows_rolls_back_all_previous_rows(): void
    {
        $ids = [];
        for ($i = 1; $i <= 1000; $i++) {
            $ids[] = $this->unit(['external_id' => 'QA-BULK-'.$i])->id;
        }
        $parentVersion = $this->building->fresh()->version;
        $id = $this->postJson($this->path.'/preview', [
            'mode' => 'bulk', 'unit_ids' => $ids, 'changes' => ['total_price' => '60000.55'],
        ])->assertCreated()->assertJsonPath('counts.updated', 1000)->assertJsonPath('counts.errors', 0)->json('id');
        // A competing legacy writer may change the row without bumping its
        // parent. Apply must still roll back the preceding 999 successful rows.
        DeveloperUnit::query()->whereKey($ids[999])->increment('version');
        $started = microtime(true);
        $this->postJson($this->path.'/'.$id.'/apply', ['confirmed' => true])->assertConflict();
        $this->assertSame(1000, DeveloperUnit::query()->where('total_price', '50000.00')->where('pricing_basis', 'per_sqm')->count());
        $this->assertSame(999, DeveloperUnit::query()->where('version', 1)->count());
        $this->assertSame(2, DeveloperUnit::findOrFail($ids[999])->version);
        $this->assertSame($parentVersion, $this->building->fresh()->version);
        $this->assertDatabaseCount('crm_audit_logs', 0);
        $this->assertDatabaseHas('residential_import_batches', ['id' => $id, 'status' => 'preview', 'applied_at' => null]);
        fwrite(STDERR, sprintf("\nBulk 1000 late conflict: full rollback %.0f ms\n", (microtime(true) - $started) * 1000));
    }
}
