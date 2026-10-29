<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PropertyResponsibleAuthorBackfillTest extends TestCase
{
    public function test_backfill_assigns_only_missing_responsibility_without_changing_listing_history(): void
    {
        Schema::dropAllTables();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('active');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('deletion_requested_at')->nullable();
        });
        Schema::create('properties', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('sale_user_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('moderation_status');
            $table->string('deal_status');
            $table->string('publication_status');
            $table->text('status_comment');
            $table->unsignedInteger('moderation_version');
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('listing_updated_at');
            $table->timestamps();
        });
        DB::table('users')->insert(['id' => 1]);
        DB::table('users')->insert(['id' => 2]);
        DB::table('users')->insert(['id' => 3, 'status' => 'inactive']);
        DB::table('users')->insert(['id' => 4, 'deleted_at' => '2026-10-01 12:00:00']);
        DB::table('users')->insert(['id' => 5, 'deletion_requested_at' => '2026-10-01 12:00:00']);

        $make = fn (array $extra = []) => DB::table('properties')->insertGetId(array_merge([
            'created_by' => 1, 'agent_id' => null, 'sale_user_id' => 2, 'branch_group_id' => 20,
            'moderation_status' => 'denied', 'deal_status' => 'client_denied', 'publication_status' => 'unpublished',
            'status_comment' => 'Продано другим агентом', 'moderation_version' => 2,
            'sold_at' => null, 'created_at' => '2026-09-15 12:00:00',
            'updated_at' => '2026-09-18 23:24:07', 'listing_updated_at' => '2026-09-15 12:00:00',
        ], $extra));
        $assignedIds = [];
        foreach (['approved', 'pending', 'deposit', 'sold', 'sold_by_owner', 'rented', 'denied', 'rejected', 'draft', 'deleted'] as $status) {
            $assignedIds[] = $make(['moderation_status' => $status]);
        }
        $transferredId = $make(['agent_id' => 2]);
        foreach ([3, 4, 5, 999, null, 0] as $authorId) {
            $make(['created_by' => $authorId]);
        }
        $before = DB::table('properties')->orderBy('id')->get()->keyBy('id');
        $migration = require database_path('migrations/2026_10_29_120000_backfill_property_responsible_authors.php');
        $migration->up();

        foreach (DB::table('properties')->orderBy('id')->get() as $property) {
            $expected = (array) $before[$property->id];
            if (in_array($property->id, $assignedIds, true)) {
                $expected['agent_id'] = 1;
            }
            $this->assertSame($expected, (array) $property);
        }

        // Reruns and rollback must not undo a subsequent transfer.
        DB::table('properties')->where('id', $assignedIds[0])->update(['agent_id' => 2]);
        $afterTransfer = DB::table('properties')->orderBy('id')->get()->toJson();
        $migration->up();
        $migration->down();
        $this->assertSame($afterTransfer, DB::table('properties')->orderBy('id')->get()->toJson());
        $this->assertDatabaseHas('properties', ['id' => $transferredId, 'agent_id' => 2, 'created_by' => 1]);
    }
}
