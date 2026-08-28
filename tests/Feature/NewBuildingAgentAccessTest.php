<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Http\Middleware\LogApiRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NewBuildingAgentAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureDailyReportSubmitted::class, LogApiRequest::class]);
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->unique();
            $table->foreignId('role_id');
            $table->string('status')->default('active');
            $table->string('auth_method')->default('password');
            $table->timestamps();
        });
        foreach ([
            '2025_06_23_004353_create_locations_table.php',
            '2025_09_05_111317_create_construction_stages_table.php',
            '2025_09_05_111318_create_developers_table.php',
            '2025_09_05_111319_create_features_table.php',
            '2025_09_05_111320_create_materials_table.php',
            '2025_09_05_111321_create_new_buildings_table.php',
            '2025_09_05_111325_create_feature_new_building_table.php',
            '2025_12_11_151438_add_ceiling_height_to_new_buildings_table.php',
            '2026_07_29_000003_create_reference_catalog_merge_audits_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    public static function roles(): array
    {
        return [['agent'], ['mop']];
    }

    public static function rolesAndCatalogs(): array
    {
        $cases = [];
        foreach (['agent', 'mop'] as $role) {
            foreach (['developers', 'construction-stages', 'materials', 'features', 'locations'] as $catalog) {
                $cases[$role.' '.$catalog] = [$role, $catalog];
            }
        }

        return $cases;
    }

    #[DataProvider('roles')]
    public function test_agent_and_mop_can_fill_dictionaries_and_create_a_new_building(string $role): void
    {
        $this->actingAsRole($role);
        $developer = $this->createCatalogItem('developers', 'Developer');
        $stage = $this->createCatalogItem('construction-stages', 'Stage');
        $material = $this->createCatalogItem('materials', 'Material');
        $feature = $this->createCatalogItem('features', 'Feature');
        $location = $this->createCatalogItem('locations', 'City');
        $response = $this->postJson('/api/new-buildings', [
            'title' => 'Новый ЖК',
            'developer_id' => $developer,
            'construction_stage_id' => $stage,
            'material_id' => $material,
            'features' => [$feature],
            'location_id' => $location,
        ])->assertCreated()
            ->assertJsonPath('developer.id', $developer)
            ->assertJsonPath('material.id', $material)
            ->assertJsonPath('stage.id', $stage)
            ->assertJsonPath('features.0.id', $feature);
        $this->assertDatabaseHas('new_buildings', ['id' => $response->json('id'), 'location_id' => $location]);
    }

    #[DataProvider('rolesAndCatalogs')]
    public function test_used_dictionary_requires_replacement_and_preserves_the_building(string $role, string $catalog): void
    {
        $actor = $this->actingAsRole($role);
        $source = $this->createCatalogItem($catalog, 'Source');
        $replacement = $this->createCatalogItem($catalog, 'Replacement');
        $table = str_replace('-', '_', $catalog);
        $column = match ($catalog) {
            'developers' => 'developer_id',
            'construction-stages' => 'construction_stage_id',
            'materials' => 'material_id',
            'locations' => 'location_id',
            default => null,
        };
        $building = DB::table('new_buildings')->insertGetId(['title' => 'Linked building', ...($column ? [$column => $source] : [])]);
        if ($catalog === 'features') {
            // A replacement already attached to the building must not create a duplicate.
            DB::table('feature_new_building')->insert([
                ['feature_id' => $source, 'new_building_id' => $building],
                ['feature_id' => $replacement, 'new_building_id' => $building],
            ]);
        }
        $path = '/api/admin/catalogs/'.$catalog.'/'.$source;
        $this->getJson($path.'/usage')->assertOk()
            ->assertJsonPath('data.usage.total', 1)
            ->assertJsonPath('data.replacement_required', true)
            ->assertJsonPath('data.can_delete_directly', false)
            ->assertJsonPath('data.replacement_options.0.id', $replacement);
        foreach ([$path, '/api/'.$catalog.'/'.$source] as $deletePath) {
            $this->deleteJson($deletePath)->assertStatus(409)->assertJsonPath('code', 'REFERENCE_CATALOG_IN_USE');
        }
        $this->postJson($path.'/merge', ['replacement_id' => $replacement, 'expected_usage_count' => 0])
            ->assertStatus(409)->assertJsonPath('code', 'REFERENCE_USAGE_CHANGED');
        $this->postJson($path.'/merge', ['replacement_id' => $source, 'expected_usage_count' => 1])
            ->assertStatus(422)->assertJsonPath('code', 'REFERENCE_REPLACEMENT_SAME_AS_SOURCE');
        $this->assertDatabaseHas($table, ['id' => $source]);
        $this->postJson($path.'/merge', ['replacement_id' => $replacement, 'expected_usage_count' => 1])
            ->assertOk()->assertJsonPath('data.source_deleted', true)->assertJsonPath('data.reassigned.total', 1);
        $this->assertDatabaseMissing($table, ['id' => $source]);
        $this->assertDatabaseCount('new_buildings', 1);
        if ($column) {
            $this->assertDatabaseHas('new_buildings', ['id' => $building, $column => $replacement]);
        } else {
            $this->assertDatabaseCount('feature_new_building', 1);
            $this->assertDatabaseHas('feature_new_building', ['new_building_id' => $building, 'feature_id' => $replacement]);
        }
        $this->assertDatabaseHas('reference_catalog_merge_audits', ['actor_user_id' => $actor->id, 'catalog' => $catalog]);
        $unused = $this->createCatalogItem($catalog, 'Unused');
        $this->getJson('/api/admin/catalogs/'.$catalog.'/'.$unused.'/usage')->assertOk()
            ->assertJsonPath('data.can_delete_directly', true)->assertJsonPath('data.replacement_required', false);
        $this->deleteJson('/api/'.$catalog.'/'.$unused)->assertSuccessful();
        $this->assertDatabaseMissing($table, ['id' => $unused]);
    }

    #[DataProvider('roles')]
    public function test_other_catalogs_are_not_opened_to_agent_and_mop(string $role): void
    {
        $this->actingAsRole($role);
        foreach (['roles', 'branches', 'branch-groups', 'property-types', 'tags', 'client-types'] as $catalog) {
            $path = '/api/admin/catalogs/'.$catalog.'/1';
            $this->getJson($path.'/usage')->assertForbidden();
            $this->postJson($path.'/merge', ['replacement_id' => 2, 'expected_usage_count' => 0])->assertForbidden();
            $this->deleteJson($path)->assertForbidden();
        }
    }

    public function test_clients_and_accountants_cannot_create_buildings_or_manage_catalogs(): void
    {
        foreach (['client', 'external_agent', 'accountant'] as $role) {
            $this->actingAsRole($role);
            $this->postJson('/api/new-buildings', ['title' => 'Forbidden'])->assertForbidden();
            $this->postJson('/api/materials', ['name' => 'Forbidden'])->assertForbidden();
            $this->getJson('/api/admin/catalogs/materials/1/usage')->assertForbidden();
            $this->postJson('/api/admin/catalogs/materials/1/merge', ['replacement_id' => 2, 'expected_usage_count' => 0])->assertForbidden();
            $this->deleteJson('/api/admin/catalogs/materials/1')->assertForbidden();
        }
    }

    public function test_legacy_delete_keeps_existing_role_permissions_but_never_drops_linked_data(): void
    {
        foreach (['rop', 'marketing', 'branch_director'] as $role) {
            $this->actingAsRole($role);
            $material = $this->createCatalogItem('materials', $role);
            $building = DB::table('new_buildings')->insertGetId(['title' => $role, 'material_id' => $material]);
            $this->deleteJson('/api/materials/'.$material)->assertStatus(409)
                ->assertJsonPath('code', 'REFERENCE_CATALOG_IN_USE');
            $this->assertDatabaseHas('new_buildings', ['id' => $building, 'material_id' => $material]);
            $unused = $this->createCatalogItem('materials', $role.' unused');
            $this->deleteJson('/api/materials/'.$unused)->assertNoContent();
            $this->assertDatabaseMissing('materials', ['id' => $unused]);
        }
    }

    private function actingAsRole(string $slug): User
    {
        $role = Role::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        $user = User::query()->create([
            'name' => $slug, 'phone' => '+992'.random_int(100000000, 999999999),
            'role_id' => $role->id, 'status' => User::STATUS_ACTIVE, 'auth_method' => 'password',
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function createCatalogItem(string $catalog, string $label): int
    {
        $payload = $catalog === 'locations' ? ['city' => $label, 'district' => 'District'] : ['name' => $label];
        $created = $this->postJson('/api/'.$catalog, $payload)->assertCreated();

        return (int) $created->json('id');
    }
}
