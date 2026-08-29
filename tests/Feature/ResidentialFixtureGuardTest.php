<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ResidentialLoadFixture;
use Tests\Support\ResidentialSchema;
use Tests\TestCase;

class ResidentialFixtureGuardTest extends TestCase
{
    public static function guardedOperations(): array
    {
        return ['schema' => ['schema'], 'seed' => ['seed'], 'MySQL schema' => ['mysql']];
    }

    #[DataProvider('guardedOperations')]
    public function test_non_testing_environment_is_rejected_before_any_database_query(string $operation): void
    {
        $this->app->instance('env', 'production');
        DB::shouldReceive('connection')->never();
        DB::shouldReceive('selectOne')->never();
        $this->expectException(\LogicException::class);
        match ($operation) {
            'schema' => ResidentialSchema::create(),
            'seed' => ResidentialLoadFixture::seed(),
            'mysql' => ResidentialSchema::createMysql('/tmp'),
        };
    }

    public function test_mysql_fixture_rejects_normal_application_connection_without_querying_it(): void
    {
        config(['database.default' => 'mysql']);
        DB::shouldReceive('selectOne')->never();
        $this->expectException(\LogicException::class);
        ResidentialSchema::createMysql('/tmp');
    }

    public function test_load_fixture_refuses_to_replace_an_existing_inventory(): void
    {
        ResidentialSchema::create();
        $building = \App\Models\NewBuilding::create(['title' => 'Preserve existing data']);
        try {
            ResidentialLoadFixture::seed();
            $this->fail('Existing inventory must be rejected.');
        } catch (\LogicException $exception) {
            $this->assertSame('Load fixture requires an empty inventory.', $exception->getMessage());
        }
        $this->assertDatabaseCount('new_buildings', 1);
        $this->assertDatabaseHas('new_buildings', ['id' => $building->id, 'title' => 'Preserve existing data']);
    }
}
