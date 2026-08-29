<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RetiredExternalCrmIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('retired_integration_identifiers');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('bookings');
    }

    public function test_retired_external_crm_fields_are_archived_removed_and_reversible(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bitrix_contact_id')->nullable()->index();
        });
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('bitrix_activity_id')->nullable()->index();
        });
        DB::table('clients')->insert(['id' => 7, 'bitrix_contact_id' => 701]);
        DB::table('bookings')->insert(['id' => 8, 'bitrix_activity_id' => 'activity-801']);

        $migration = require database_path(
            'migrations/2026_08_29_010000_retire_external_crm_identifiers.php'
        );
        $migration->up();

        $this->assertTrue(Schema::hasTable('retired_integration_identifiers'));
        $this->assertFalse(Schema::hasColumn('clients', 'bitrix_contact_id'));
        $this->assertFalse(Schema::hasColumn('bookings', 'bitrix_activity_id'));
        $this->assertDatabaseHas('retired_integration_identifiers', [
            'provider' => 'bitrix24',
            'entity_type' => 'client',
            'entity_id' => 7,
            'identifier_type' => 'contact_id',
            'identifier_value' => '701',
        ]);
        $this->assertDatabaseHas('retired_integration_identifiers', [
            'provider' => 'bitrix24',
            'entity_type' => 'booking',
            'entity_id' => 8,
            'identifier_type' => 'activity_id',
            'identifier_value' => 'activity-801',
        ]);

        $this->postJson('/api/b24/token', ['domain' => 'example.test'])->assertNotFound();

        $migration->down();

        $this->assertFalse(Schema::hasTable('retired_integration_identifiers'));
        $this->assertSame(701, DB::table('clients')->where('id', 7)->value('bitrix_contact_id'));
        $this->assertSame(
            'activity-801',
            DB::table('bookings')->where('id', 8)->value('bitrix_activity_id')
        );
    }

    public function test_rollback_does_not_create_legacy_columns_that_were_already_absent(): void
    {
        Schema::create('clients', fn (Blueprint $table) => $table->id());
        Schema::create('bookings', fn (Blueprint $table) => $table->id());

        $migration = require database_path(
            'migrations/2026_08_29_010000_retire_external_crm_identifiers.php'
        );
        $migration->up();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('clients', 'bitrix_contact_id'));
        $this->assertFalse(Schema::hasColumn('bookings', 'bitrix_activity_id'));
    }
}
