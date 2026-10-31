<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use App\Services\PropertyPublicationRepair;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PropertyPublicationRepairTest extends TestCase
{
    private string $directory;
    private User $actor;
    private PropertyPublicationRepair $repair;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('slug'); $t->json('permissions')->nullable(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('phone'); $t->foreignId('role_id'); $t->string('status')->default('active');
            $t->unsignedBigInteger('branch_id')->nullable(); $t->unsignedBigInteger('branch_group_id')->nullable(); $t->timestamps();
        });
        Schema::create('property_statuses', function (Blueprint $t): void { $t->id(); $t->string('name'); $t->string('slug'); $t->timestamps(); });
        Schema::create('building_types', function (Blueprint $t): void { $t->id(); $t->string('name'); $t->timestamps(); });
        Schema::create('properties', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('status_id')->nullable(); $t->string('title'); $t->text('description')->nullable();
            $t->unsignedBigInteger('created_by'); $t->unsignedBigInteger('agent_id'); $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('branch_group_id')->nullable(); $t->decimal('price', 15, 2); $t->decimal('discount_price', 15, 2)->nullable();
            $t->string('currency')->default('TJS'); $t->string('moderation_status')->default('approved'); $t->string('listing_type')->default('regular');
            $t->timestamp('sold_at')->nullable(); $t->unsignedBigInteger('sale_user_id')->nullable();
            $t->string('buyer_full_name')->nullable(); $t->decimal('actual_sale_price', 15, 2)->nullable();
            $t->decimal('company_commission_amount', 15, 2)->nullable(); $t->timestamp('listed_at')->nullable(); $t->timestamp('listing_updated_at')->nullable(); $t->timestamps();
        });
        Schema::create('property_logs', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('property_id'); $t->unsignedBigInteger('user_id')->nullable(); $t->string('action');
            $t->text('comment')->nullable(); $t->json('changes'); $t->timestamps();
        });
        Schema::create('property_status_history', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('property_id'); $t->string('from_status')->nullable(); $t->string('to_status');
            $t->unsignedBigInteger('changed_by')->nullable(); $t->timestamp('changed_at'); $t->timestamps();
        });
        Schema::create('property_agent_sales', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('property_id'); $t->unsignedBigInteger('agent_id'); $t->string('role'); $t->decimal('agent_commission_amount', 15, 2);
        });
        Schema::create('property_photos', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('property_id'); $t->string('file_path'); $t->integer('position'); $t->timestamps();
        });
        (require database_path('migrations/2026_09_04_100000_add_property_moderation_workflow.php'))->up();
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'Reviewer', 'slug' => 'reviewer', 'permissions' => json_encode(['properties.moderate', 'properties.manage_all'])],
            ['id' => 2, 'name' => 'Agent', 'slug' => 'agent', 'permissions' => json_encode(['properties.edit'])],
        ]);
        DB::table('users')->insert([
            ['id' => 10, 'name' => 'Reviewer', 'phone' => '900000010', 'role_id' => 1, 'branch_id' => 1],
            ['id' => 163, 'name' => 'Agent', 'phone' => '900000163', 'role_id' => 2, 'branch_id' => 1],
        ]);
        DB::table('property_statuses')->insert([['id' => 2, 'name' => 'Продан', 'slug' => 'sold'], ['id' => 3, 'name' => 'Арендован', 'slug' => 'rented']]);
        DB::table('building_types')->insert([['id' => 2, 'name' => 'Кирпичный'], ['id' => 3, 'name' => 'Монолитный']]);
        foreach ([...PropertyPublicationRepair::IDS, 9999] as $id) {
            $closed = $id === 9229;
            $status = $id === 4868 ? 2 : ($closed || $id === 9999 ? null : 3);
            DB::table('properties')->insert(['id' => $id, 'title' => 'Объект '.$id, 'description' => 'Историческое описание',
                'created_by' => 163, 'agent_id' => 163, 'branch_id' => 1, 'status_id' => $status, 'price' => 100000,
                'publication_status' => $closed ? 'archived' : 'published', 'deal_status' => $closed ? 'sold_by_owner' : 'available',
                'sold_at' => $closed ? '2026-10-24 14:57:19' : null, 'sale_user_id' => $closed ? 163 : null,
                'buyer_full_name' => $closed ? 'Исторический покупатель' : null, 'actual_sale_price' => $closed ? 95000 : null,
                'company_commission_amount' => $closed ? 5000 : null, 'moderation_version' => 7,
                'approved_content_snapshot' => json_encode(['price' => 100000, 'status_id' => $status, 'description' => 'Историческое описание']),
                'created_at' => '2026-05-20 10:00:00', 'updated_at' => '2026-10-29 10:00:00',
                'listed_at' => '2026-05-20 10:00:00', 'listing_updated_at' => '2026-05-20 10:00:00']);
            DB::table('property_logs')->insert(['property_id' => $id, 'user_id' => 163, 'action' => 'created', 'changes' => '{}', 'created_at' => '2026-05-20 10:00:00']);
            DB::table('property_photos')->insert(['property_id' => $id, 'file_path' => 'properties/old-'.$id.'.jpg', 'position' => 0]);
        }
        DB::table('property_agent_sales')->insert(['property_id' => 9229, 'agent_id' => 163, 'role' => 'primary', 'agent_commission_amount' => 500]);
        (require database_path('migrations/2026_10_31_160000_separate_property_building_type.php'))->up();
        $this->actor = User::findOrFail(10);
        $this->repair = app(PropertyPublicationRepair::class);
        $this->directory = sys_get_temp_dir().'/aura-repair-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        Bus::fake();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) unlink($path);
        rmdir($this->directory);
        parent::tearDown();
    }

    private function reviewed(): array
    {
        $manifest = $this->repair->export($this->actor);
        $manifest['reviewed_by'] = 10;
        foreach ($manifest['entries'] as &$entry) {
            $entry['decision'] = 'confirm_closed';
            $entry['deal_status'] = PropertyPublicationRepair::CLOSED_DEALS[$entry['id']];
            $entry['reason'] = 'Подтверждена техническая ошибка состояния публикации.';
            $entry['evidence'] = 'Тестовая подтверждённая история и проверка ответственным.';
        }
        return $manifest;
    }

    public function test_migration_export_and_preview_do_not_change_records(): void
    {
        $this->assertDatabaseCount('property_publication_repairs', 0);
        $this->assertSame(0, DB::table('properties')->whereNotNull('building_type_id')->count());
        $before = $this->repair->snapshot(4868);
        $export = $this->repair->export($this->actor);
        $this->assertSame(['hold'], array_values(array_unique(array_column($export['entries'], 'decision'))));
        $this->assertSame(['held'], array_values(array_unique(array_column($this->repair->run($export, $this->actor), 'status'))));
        $rows = $this->repair->run($this->reviewed(), $this->actor);
        $this->assertCount(8, $rows);
        $this->assertSame(['ready'], array_values(array_unique(array_column($rows, 'status'))));
        $this->assertSame($before, $this->repair->snapshot(4868));
        $this->assertDatabaseCount('property_logs', 9);
        $this->assertDatabaseCount('property_publication_repairs', 0);
        Bus::assertNothingDispatched();
    }

    public function test_eight_repairs_preserve_history_finance_and_dates_and_are_idempotent(): void
    {
        DB::table('property_status_history')->insert(['property_id' => 4868, 'from_status' => 'pending', 'to_status' => 'sold',
            'changed_by' => 163, 'changed_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $oldHistory = DB::table('property_status_history')->first();
        $manifest = $this->reviewed();
        $oldRows = DB::table('properties')->get()->keyBy('id');
        $oldLogs = DB::table('property_logs')->get()->toJson();
        $oldSales = DB::table('property_agent_sales')->get()->toJson();
        $outside = $this->repair->snapshot(9999);
        $result = $this->repair->run($manifest, $this->actor, true, $this->directory.'/before.json');
        $this->assertSame(['applied'], array_values(array_unique(array_column($result, 'status'))));
        $this->assertSame(0600, fileperms($this->directory.'/before.json') & 0777);
        $backup = json_decode(file_get_contents($this->directory.'/before.json'), true);
        $this->assertSame('Исторический покупатель', $backup['states'][9229]['property']['buyer_full_name']);
        $this->assertSame(0, Property::whereIn('id', PropertyPublicationRepair::IDS)->publicSearchable()->count());
        $this->assertSame(0, Property::whereIn('id', PropertyPublicationRepair::IDS)->where('moderation_status', 'approved')->count());
        foreach (PropertyPublicationRepair::IDS as $id) {
            $row = DB::table('properties')->where('id', $id)->first();
            foreach (['sold_at', 'sale_user_id', 'buyer_full_name', 'actual_sale_price', 'company_commission_amount', 'created_at', 'listed_at', 'listing_updated_at', 'price', 'agent_id', 'created_by', 'status_id', 'approved_content_snapshot', 'building_type_id', 'building_type_resolved_at'] as $field) {
                $this->assertSame($oldRows[$id]->$field, $row->$field, $id.':'.$field);
            }
            $this->assertSame(8, $row->moderation_version);
            $this->assertSame(PropertyPublicationRepair::CLOSED_DEALS[$id], $row->moderation_status);
            $this->assertSame('archived', $row->publication_status);
        }
        $this->assertSame($oldLogs, DB::table('property_logs')->where('action', 'created')->get()->toJson());
        $this->assertSame($oldSales, DB::table('property_agent_sales')->get()->toJson());
        $this->assertSame($outside, $this->repair->snapshot(9999));
        $this->assertDatabaseCount('property_publication_repairs', 8);
        $this->assertDatabaseCount('property_status_history', 9);
        $this->assertEquals($oldHistory, DB::table('property_status_history')->where('id', $oldHistory->id)->first());
        $this->assertDatabaseCount('property_moderation_events', 8);
        $repeat = $this->repair->run($manifest, $this->actor, true, $this->directory.'/repeat.json');
        $this->assertSame(['already_applied'], array_values(array_unique(array_column($repeat, 'status'))));
        $this->assertDatabaseCount('property_logs', 17);
        Bus::assertNothingDispatched();
    }

    public function test_changed_property_or_related_history_is_skipped_without_overwriting(): void
    {
        $manifest = $this->reviewed();
        DB::table('properties')->where('id', 4868)->update(['description' => 'Новое описание после проверки']);
        DB::table('property_photos')->where('property_id', 5045)->update(['position' => 2]);
        $rows = collect($this->repair->run($manifest, $this->actor, true, $this->directory.'/before.json'))->keyBy('id');
        foreach ([4868, 5045] as $id) $this->assertSame('RECORD_CHANGED_SINCE_REVIEW', $rows[$id]['reason']);
        $this->assertDatabaseHas('properties', ['id' => 4868, 'status_id' => 2, 'description' => 'Новое описание после проверки', 'moderation_version' => 7]);
        $this->assertDatabaseCount('property_publication_repairs', 6);
    }

    public function test_incident_decisions_cannot_be_changed_or_reopened(): void
    {
        $manifest = $this->reviewed();
        $manifest['entries'][0]['deal_status'] = 'sold';
        try {
            $this->repair->run($manifest, $this->actor, true, $this->directory.'/forbidden.json');
            $this->fail('Changed business decision was accepted.');
        } catch (\DomainException $e) {
            $this->assertSame('DECISION_DIFFERS_FROM_CONFIRMED_INCIDENT', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->directory.'/forbidden.json');
        $this->assertDatabaseCount('property_publication_repairs', 0);
    }

    public function test_schema_rollback_cannot_delete_building_data_or_repair_receipts(): void
    {
        try {
            (require database_path('migrations/2026_10_31_160000_separate_property_building_type.php'))->down();
            $this->fail('Destructive schema rollback was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('retains reviewed building types', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('properties', 'building_type_id'));
        $this->assertTrue(Schema::hasTable('property_publication_repairs'));
    }

    public function test_rollback_preserves_audit_and_does_not_overwrite_later_edits(): void
    {
        $manifest = $this->reviewed();
        $original = $this->repair->snapshot(4225);
        $this->repair->run($manifest, $this->actor, true, $this->directory.'/apply.json');
        DB::table('properties')->where('id', 4868)->update(['description' => 'Поздняя правка']);
        $preview = $this->repair->rollback($manifest['batch_id'], $this->actor);
        $this->assertSame('ready_to_rollback', $preview[0]['status']);
        $this->assertDatabaseHas('properties', ['id' => 4225, 'status_id' => 3, 'moderation_status' => 'rented']);
        $rows = collect($this->repair->rollback($manifest['batch_id'], $this->actor, true, $this->directory.'/rollback.json'))->keyBy('id');
        $this->assertSame('RECORD_CHANGED_AFTER_REPAIR', $rows[4868]['reason']);
        $restored = $this->repair->snapshot(4225);
        foreach ($original['property'] as $field => $value) {
            if (! in_array($field, ['moderation_version', 'updated_at'], true)) $this->assertSame($value, $restored['property'][$field], $field);
        }
        $this->assertSame(9, $restored['property']['moderation_version']);
        $this->assertDatabaseCount('property_logs', 24);
        $this->assertDatabaseCount('property_moderation_events', 15);
        $this->assertSame(7, DB::table('property_publication_repairs')->whereNotNull('rolled_back_at')->count());
        $repeat = $this->repair->rollback($manifest['batch_id'], $this->actor, true, $this->directory.'/rollback-repeat.json');
        $this->assertSame('already_rolled_back', $repeat[0]['status']);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('BATCH_ALREADY_ROLLED_BACK');
        $this->repair->run($manifest, $this->actor);
    }

    public function test_open_review_and_confirmed_duplicate_are_never_bypassed(): void
    {
        $case = DB::table('property_moderation_cases')->insertGetId(['property_id' => 4868, 'type' => 'content_review', 'status' => 'open', 'blocking' => true, 'submitted_by' => 163, 'submitted_at' => now()]);
        $parent = DB::table('property_moderation_cases')->insertGetId(['property_id' => 5045, 'type' => 'duplicate_review', 'status' => 'merged', 'blocking' => false, 'submitted_by' => 163, 'submitted_at' => now()]);
        DB::table('property_duplicate_candidates')->insert(['moderation_case_id' => $parent, 'candidate_property_id' => 9999, 'score' => 99, 'decision' => 'confirmed']);
        $rows = collect($this->repair->run($this->reviewed(), $this->actor))->keyBy('id');
        $this->assertSame('MODERATION_REVIEW_REQUIRED', $rows[4868]['reason']);
        $this->assertSame('DUPLICATE_REVIEW_REQUIRED', $rows[5045]['reason']);
    }

    public function test_failed_audit_rolls_back_the_record_and_requires_a_backup_before_writing(): void
    {
        $manifest = $this->reviewed();
        $manifest['entries'] = [$manifest['entries'][0]];
        $before = $this->repair->snapshot(4225);
        try { $this->repair->run($manifest, $this->actor, true); $this->fail('Backup required'); }
        catch (\DomainException $e) { $this->assertSame('PRIVATE_BACKUP_PATH_REQUIRED', $e->getMessage()); }
        DB::unprepared("CREATE TRIGGER fail_repair_audit BEFORE INSERT ON property_moderation_events BEGIN SELECT RAISE(ABORT, 'test audit failure'); END");
        try { $this->repair->run($manifest, $this->actor, true, $this->directory.'/backup.json'); $this->fail('Audit must be atomic'); }
        catch (\Illuminate\Database\QueryException) { $this->assertSame($before, $this->repair->snapshot(4225)); }
        $this->assertDatabaseCount('property_publication_repairs', 0);
    }

    public function test_manifest_requires_review_scope_and_matching_reviewer_before_any_backup(): void
    {
        $manifest = $this->reviewed();
        $manifest['reviewed_by'] = 163;
        try { $this->repair->run($manifest, User::findOrFail(163), true, $this->directory.'/denied.json'); $this->fail('Agent cannot repair'); }
        catch (\DomainException $e) { $this->assertSame('ACTOR_NOT_ALLOWED', $e->getMessage()); }
        $this->assertFileDoesNotExist($this->directory.'/denied.json');
        $manifest['reviewed_by'] = 10;
        $manifest['entries'][0]['id'] = 9999;
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->repair->run($manifest, $this->actor);
    }

    public function test_command_exports_private_hold_manifest_and_defaults_to_preview(): void
    {
        $path = $this->directory.'/review.json';
        $this->artisan('properties:reconcile-publication', ['--actor' => 10, '--export' => $path])->assertSuccessful();
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->artisan('properties:reconcile-publication', ['--actor' => 10, '--manifest' => $path])->assertSuccessful();
        file_put_contents($path, json_encode($this->reviewed()));
        $this->artisan('properties:reconcile-publication', ['--actor' => 10, '--manifest' => $path])
            ->expectsOutputToContain('deal_status: "available" -> "rented"')->assertSuccessful();
        $this->assertDatabaseCount('property_publication_repairs', 0);
        $this->artisan('properties:reconcile-publication', ['--actor' => 10, '--export' => $path])->assertFailed();
        $this->artisan('properties:reconcile-publication', ['--actor' => 10, '--export' => $this->directory.'/bad.json', '--apply' => true])->assertFailed();
    }

    public function test_confirmed_historical_closures_do_not_invent_sale_dates_or_credits(): void
    {
        $manifest = $this->reviewed();
        foreach ($manifest['entries'] as &$entry) {
            $entry['decision'] = 'confirm_closed';
            $entry['deal_status'] = $entry['id'] === 9229 ? 'sold_by_owner' : ($entry['id'] === 4868 ? 'sold' : 'rented');
            $entry['building_type_id'] = null;
        }
        $this->repair->run($manifest, $this->actor, true, $this->directory.'/closed.json');
        $this->assertSame(0, Property::whereIn('id', PropertyPublicationRepair::IDS)->publicSearchable()->count());
        $this->assertSame(7, DB::table('properties')->whereIn('id', PropertyPublicationRepair::IDS)->whereNull('sold_at')->count());
        $this->assertDatabaseCount('property_agent_sales', 1);
        $this->assertDatabaseCount('property_status_history', 8);
        Bus::assertNothingDispatched();
    }

    public function test_approved_snapshot_stays_unchanged_and_reused_batch_cannot_be_silently_changed(): void
    {
        DB::table('properties')->where('id', 4868)->update(['approved_content_snapshot' => json_encode(['status_id' => 3])]);
        $manifest = $this->reviewed();
        $rows = collect($this->repair->run($manifest, $this->actor, true, $this->directory.'/before.json'))->keyBy('id');
        $this->assertSame('applied', $rows[4868]['status']);
        $this->assertSame(['status_id' => 3], json_decode(DB::table('properties')->where('id', 4868)->value('approved_content_snapshot'), true));
        $manifest['entries'][0]['reason'] = 'Изменено решение после применения пакета.';
        $this->assertDatabaseCount('property_publication_repairs', 8);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('BATCH_CONTENT_CHANGED');
        $this->repair->run($manifest, $this->actor);
    }

    public function test_partial_batch_cannot_apply_remaining_rows_with_a_changed_manifest(): void
    {
        $manifest = $this->reviewed();
        $manifest['entries'][1]['decision'] = 'hold';
        $this->repair->run($manifest, $this->actor, true, $this->directory.'/partial.json');
        $this->assertDatabaseCount('property_publication_repairs', 7);
        $manifest['entries'][1]['decision'] = 'confirm_closed';
        try {
            $this->repair->run($manifest, $this->actor, true, $this->directory.'/changed.json');
            $this->fail('A changed manifest extended an already started batch.');
        } catch (\DomainException $e) {
            $this->assertSame('BATCH_CONTENT_CHANGED', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->directory.'/changed.json');
        $this->assertDatabaseHas('properties', ['id' => 4229, 'moderation_status' => 'approved']);
        $this->assertDatabaseCount('property_publication_repairs', 7);
    }
}
