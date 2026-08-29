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
        \Tests\Support\ResidentialSchema::create();
        (require database_path('migrations/2026_07_29_000003_create_reference_catalog_merge_audits_table.php'))->up();
    }

    public static function roles(): array
    {
        return [['agent'], ['mop']];
    }

    public static function rolesAndCatalogs(): array
    {
        $cases = [];
        foreach (['agent', 'mop', 'owner'] as $role) {
            foreach (['developers', 'construction-stages', 'materials', 'features', 'locations'] as $catalog) {
                $cases[$role.' '.$catalog] = [$role, $catalog];
            }
        }

        return $cases;
    }

    public static function catalogManagementRoles(): array
    {
        return [
            ['admin', true], ['superadmin', true], ['owner', true], ['agent', true], ['mop', true],
            ['rop', false], ['branch_director', false], ['hr', false], ['accountant', false],
            ['client', false], ['external_agent', false], ['unknown', false],
        ];
    }

    #[DataProvider('catalogManagementRoles')]
    public function test_residential_catalog_merge_permissions_match_frontend_roles(string $role, bool $allowed): void
    {
        $this->actingAsRole($role);
        foreach (['developers', 'construction-stages', 'materials', 'features', 'locations'] as $catalog) {
            $table = str_replace('-', '_', $catalog);
            $payload = $catalog === 'locations' ? ['city' => 'QA original', 'district' => 'District'] : ['name' => 'QA original'];
            $source = DB::table($table)->insertGetId([...$payload, ...(Schema::hasColumn($table, 'slug') ? ['slug' => 'qa-source'] : [])]);
            $replacement = DB::table($table)->insertGetId([
                ...($catalog === 'locations' ? ['city' => 'QA replacement', 'district' => 'District'] : ['name' => 'QA replacement']),
                ...(Schema::hasColumn($table, 'slug') ? ['slug' => 'qa-replacement'] : []),
            ]);
            $path = '/api/'.$catalog;
            $adminPath = '/api/admin/catalogs/'.$catalog.'/'.$source;
            if (! $allowed) {
                $before = DB::table($table)->orderBy('id')->get()->toJson();
                $this->getJson($adminPath.'/usage')->assertForbidden();
                $this->getJson($adminPath.'/replacements')->assertForbidden();
                $this->postJson($adminPath.'/merge', ['replacement_id' => $replacement, 'expected_usage_count' => 0])->assertForbidden();
                $this->deleteJson($adminPath)->assertForbidden();
                $this->assertSame($before, DB::table($table)->orderBy('id')->get()->toJson());

                continue;
            }
            $this->postJson($path, $payload)->assertCreated();
            $changed = $catalog === 'locations' ? ['city' => 'QA updated', 'district' => 'District'] : ['name' => 'QA updated'];
            $this->patchJson($path.'/'.$source, $changed)->assertOk();
            $this->getJson($adminPath.'/usage')->assertOk()->assertJsonPath('data.can_delete_directly', true);
            $this->getJson($adminPath.'/replacements')->assertOk();
            $this->postJson($adminPath.'/merge', ['replacement_id' => $replacement, 'expected_usage_count' => 0])->assertOk();
            $this->assertDatabaseMissing($table, ['id' => $source]);
            $this->deleteJson($path.'/'.$replacement)->assertSuccessful();
        }
    }

    public function test_owner_catalog_grant_does_not_extend_to_unrelated_catalogs(): void
    {
        $this->actingAsRole('owner');
        foreach (['roles', 'branches', 'branch-groups', 'property-types', 'tags', 'client-types'] as $catalog) {
            $path = '/api/admin/catalogs/'.$catalog.'/1';
            $this->getJson($path.'/usage')->assertForbidden();
            $this->getJson($path.'/replacements')->assertForbidden();
            $this->postJson($path.'/merge', ['replacement_id' => 2, 'expected_usage_count' => 0])->assertForbidden();
            $this->deleteJson($path)->assertForbidden();
        }
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
        $usage = $this->getJson($path.'/usage')->assertOk()
            ->assertJsonPath('data.usage.total', 1)
            ->assertJsonPath('data.replacement_required', true)
            ->assertJsonPath('data.can_delete_directly', false)
            ->assertJsonPath('data.replacement_options.0.id', $replacement);
        $buildingUsage = collect($usage->json('data.usage.breakdown'))->firstWhere('entity', 'new_buildings');
        $this->assertSame('Жилые комплексы', $buildingUsage['label']);
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
    public function test_developer_merge_preserves_properties_complexes_and_lots_and_rolls_back_on_audit_failure(string $role): void
    {
        $actor = $this->actingAsRole($role);
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('moderation_status');
            $table->timestamps();
        });
        (require database_path('migrations/2025_12_09_222014_add_business_owner_and_developer_fields_to_properties_table.php'))->up();
        $source = $this->postJson('/api/developers', ['name' => 'Source', 'description' => 'Original company'])
            ->assertCreated()->assertJsonPath('description', 'Original company')->json('id');
        $replacement = $this->createCatalogItem('developers', 'Replacement');
        foreach (['published', 'draft'] as $status) {
            DB::table('new_buildings')->insert(['title' => $status, 'developer_id' => $source, 'publication_status' => $status, 'version' => 7]);
        }
        DB::table('properties')->insert([
            ['title' => 'Linked property', 'moderation_status' => 'approved', 'developer_id' => $source],
            ['title' => 'Unrelated property', 'moderation_status' => 'approved', 'developer_id' => $replacement],
        ]);
        DB::table('developer_units')->insert(['new_building_id' => 1, 'name' => 'Unique lot', 'area' => 42.5, 'total_price' => 315000.55, 'version' => 9]);
        $before = [];
        foreach (['developers', 'properties', 'new_buildings', 'developer_units'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $path = '/api/admin/catalogs/developers/'.$source;
        $usage = $this->getJson($path.'/usage')->assertOk()->assertJsonPath('data.usage.total', 3);
        $breakdown = collect($usage->json('data.usage.breakdown'))->keyBy('entity');
        $this->assertSame(1, $breakdown['properties']['count']);
        $this->assertSame(2, $breakdown['new_buildings']['count']);
        $payload = ['replacement_id' => $replacement, 'expected_usage_count' => 3];

        // Fail after both reference tables were updated and the source was deleted.
        DB::statement("CREATE TRIGGER qa_reject_merge_audit BEFORE INSERT ON reference_catalog_merge_audits BEGIN SELECT RAISE(ABORT, 'QA audit failure'); END");
        $this->postJson($path.'/merge', $payload)->assertConflict()->assertJsonPath('code', 'REFERENCE_MERGE_CONFLICT');
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        }
        $this->assertDatabaseCount('reference_catalog_merge_audits', 0);
        DB::statement('DROP TRIGGER qa_reject_merge_audit');

        $this->postJson($path.'/merge', $payload)->assertOk()->assertJsonPath('data.reassigned.total', 3);
        foreach (['properties', 'new_buildings'] as $table) {
            foreach ($before[$table] as $row) {
                $this->assertSame([...$row, 'developer_id' => $replacement], (array) DB::table($table)->find($row['id']));
            }
        }
        $this->assertSame($before['developer_units'], DB::table('developer_units')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertDatabaseMissing('developers', ['id' => $source]);
        $this->assertSame($before['developers'][1], (array) DB::table('developers')->find($replacement));
        $this->assertDatabaseCount('reference_catalog_merge_audits', 1);
        $this->assertDatabaseHas('reference_catalog_merge_audits', ['actor_user_id' => $actor->id, 'catalog' => 'developers', 'source_id' => $source, 'replacement_id' => $replacement, 'reassigned_count' => 3]);
    }

    #[DataProvider('roles')]
    public function test_stage_replacement_counts_active_and_archived_blocks_and_preserves_inventory(string $role): void
    {
        $actor = $this->actingAsRole($role);
        $source = $this->createCatalogItem('construction-stages', 'Old stage');
        $replacement = $this->createCatalogItem('construction-stages', 'New stage');
        $building = DB::table('new_buildings')->insertGetId(['title' => 'QA linked structure', 'version' => 7]);
        $active = DB::table('new_building_blocks')->insertGetId(['new_building_id' => $building, 'name' => 'Active', 'construction_stage_id' => $source, 'version' => 4]);
        $archived = DB::table('new_building_blocks')->insertGetId(['new_building_id' => $building, 'name' => 'Archived', 'construction_stage_id' => $source, 'archived_at' => now(), 'version' => 5]);
        $unit = DB::table('developer_units')->insertGetId(['new_building_id' => $building, 'block_id' => $active, 'name' => 'QA unique lot', 'area' => 42.5, 'total_price' => 315000.55, 'version' => 9]);
        $lotBefore = (array) DB::table('developer_units')->find($unit);
        $blocksBefore = DB::table('new_building_blocks')->orderBy('id')->get()->map(fn ($block) => (array) $block)->all();
        $path = '/api/admin/catalogs/construction-stages/'.$source;
        $usage = $this->getJson($path.'/usage')->assertOk()
            ->assertJsonPath('data.usage.total', 2)
            ->assertJsonPath('data.can_delete_directly', false)
            ->assertJsonPath('data.replacement_required', true);
        $blockUsage = collect($usage->json('data.usage.breakdown'))->firstWhere('entity', 'new_building_blocks');
        $this->assertSame('Корпуса', $blockUsage['label']);
        $this->assertSame(2, $blockUsage['count']);
        foreach ([$path, '/api/construction-stages/'.$source] as $deletePath) {
            $this->deleteJson($deletePath)->assertConflict()->assertJsonPath('code', 'REFERENCE_CATALOG_IN_USE');
        }
        // A new parent reference after preview must invalidate confirmation without a partial move.
        DB::table('new_buildings')->where('id', $building)->update(['construction_stage_id' => $source]);
        $this->postJson($path.'/merge', ['replacement_id' => $replacement, 'expected_usage_count' => 2])
            ->assertConflict()->assertJsonPath('code', 'REFERENCE_USAGE_CHANGED');
        $this->assertSame($blocksBefore, DB::table('new_building_blocks')->orderBy('id')->get()->map(fn ($block) => (array) $block)->all());
        $this->assertDatabaseCount('reference_catalog_merge_audits', 0);
        $this->postJson($path.'/merge', ['replacement_id' => $replacement, 'expected_usage_count' => 3])
            ->assertOk()->assertJsonPath('data.reassigned.total', 3)->assertJsonPath('data.source_deleted', true);
        $this->assertDatabaseMissing('construction_stages', ['id' => $source]);
        $this->assertDatabaseHas('new_buildings', ['id' => $building, 'construction_stage_id' => $replacement, 'version' => 7]);
        foreach ($blocksBefore as $before) {
            $this->assertSame([...$before, 'construction_stage_id' => $replacement], (array) DB::table('new_building_blocks')->find($before['id']));
        }
        $this->assertSame($lotBefore, (array) DB::table('developer_units')->find($unit));
        $this->assertDatabaseHas('reference_catalog_merge_audits', ['actor_user_id' => $actor->id, 'catalog' => 'construction-stages', 'source_id' => $source, 'replacement_id' => $replacement, 'reassigned_count' => 3]);
        $this->postJson($path.'/merge', ['replacement_id' => $replacement, 'expected_usage_count' => 3])->assertNotFound();
        $this->assertDatabaseCount('reference_catalog_merge_audits', 1);
    }

    public function test_stage_catalog_search_and_pagination_cover_records_beyond_the_first_page(): void
    {
        for ($i = 1; $i <= 17; $i++) {
            DB::table('construction_stages')->insert(['name' => 'QA stage '.$i, 'slug' => 'qa-stage-'.$i, 'sort_order' => $i, 'is_active' => $i !== 17]);
        }
        $first = $this->getJson('/api/construction-stages?per_page=15')->assertOk()
            ->assertJsonPath('total', 17)->assertJsonPath('last_page', 2)->assertJsonCount(15, 'data');
        $second = $this->getJson('/api/construction-stages?per_page=15&page=2')->assertOk()
            ->assertJsonPath('current_page', 2)->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'qa-stage-16')->assertJsonPath('data.1.slug', 'qa-stage-17');
        $this->assertSame([], array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
        $this->getJson('/api/construction-stages?per_page=15&search=qa-stage-17')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'QA stage 17');
        $this->getJson('/api/construction-stages?search=stage%2016')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/construction-stages?search=qa-stage-17&active=true')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/construction-stages?search=missing')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/construction-stages')->assertOk()->assertJsonPath('per_page', 50);
    }

    public function test_stage_catalog_rejects_invalid_page_size_and_search(): void
    {
        foreach (['page=0', 'page=-1', 'per_page=0', 'per_page=101', 'search[]=x', 'search='.str_repeat('x', 256)] as $query) {
            $this->getJson('/api/construction-stages?'.$query)->assertUnprocessable();
        }
    }

    public static function otherPagedCatalogs(): array
    {
        return [['materials'], ['features'], ['developers']];
    }

    #[DataProvider('otherPagedCatalogs')]
    public function test_other_building_catalogs_support_complete_search_and_stable_pages(string $catalog): void
    {
        for ($i = 1; $i <= 17; $i++) {
            $values = ['name' => 'QA item '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)];
            if ($catalog !== 'developers') {
                $values['slug'] = 'qa-slug-'.$i;
            }
            DB::table($catalog)->insert($values);
        }
        $path = '/api/'.$catalog;
        $query = '?per_page=15&sort=name&dir=asc';
        $first = $this->getJson($path.$query)->assertOk()->assertJsonPath('total', 17)
            ->assertJsonPath('last_page', 2)->assertJsonCount(15, 'data');
        $second = $this->getJson($path.$query.'&page=2')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'QA item 16')->assertJsonPath('data.1.name', 'QA item 17');
        $this->assertSame([], array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
        $this->getJson($path.'?search=QA%20item%2017')->assertOk()->assertJsonPath('total', 1);
        if ($catalog !== 'developers') {
            $this->getJson($path.'?search=qa-slug-17')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'QA item 17');
        }
        $this->getJson($path.'?search=missing')->assertOk()->assertJsonPath('total', 0);
        foreach (['page=0', 'per_page=0', 'per_page=101', 'search[]=x'] as $invalid) {
            $this->getJson($path.'?'.$invalid)->assertUnprocessable();
        }
    }

    #[DataProvider('rolesAndCatalogs')]
    public function test_replacement_search_reaches_beyond_500_and_merge_preserves_links(string $role, string $catalog): void
    {
        $actor = $this->actingAsRole($role);
        $source = $this->createCatalogItem($catalog, 'Source');
        $table = str_replace('-', '_', $catalog);
        $rows = [];
        for ($i = 1; $i <= 501; $i++) {
            $label = sprintf('Alternative %03d', $i);
            $rows[] = $catalog === 'locations'
                ? ['city' => $label, 'district' => 'District']
                : ['name' => $label, ...(in_array($catalog, ['construction-stages', 'materials', 'features'], true) ? ['slug' => 'alternative-'.$i] : [])];
        }
        DB::table($table)->insert($rows);
        $replacement = (int) DB::table($table)->max('id');
        $column = match ($catalog) {
            'developers' => 'developer_id', 'construction-stages' => 'construction_stage_id',
            'materials' => 'material_id', 'locations' => 'location_id', default => null,
        };
        $building = DB::table('new_buildings')->insertGetId(['title' => 'Unique linked building', 'version' => 7, ...($column ? [$column => $source] : [])]);
        if (! $column) {
            DB::table('feature_new_building')->insert(['new_building_id' => $building, 'feature_id' => $source]);
        }
        $unit = DB::table('developer_units')->insertGetId(['new_building_id' => $building, 'name' => 'Unique lot', 'area' => 42.5, 'total_price' => 315000.55, 'version' => 9]);
        $before = (array) DB::table('developer_units')->find($unit);
        $path = '/api/admin/catalogs/'.$catalog.'/'.$source;
        $legacy = $this->getJson($path.'/usage')->assertOk()->assertJsonCount(500, 'data.replacement_options');
        $this->assertNotContains($replacement, array_column($legacy->json('data.replacement_options'), 'id'));
        $this->getJson($path.'/replacements?per_page=50&page=11')->assertOk()
            ->assertJsonPath('total', 501)->assertJsonPath('last_page', 11)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $replacement);
        $this->getJson($path.'/replacements?search=Alternative%20501')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $replacement);
        $this->getJson($path.'/replacements?search=Source')->assertOk()->assertJsonPath('total', 0);
        $this->postJson($path.'/merge', ['replacement_id' => $replacement, 'expected_usage_count' => 1])->assertOk()->assertJsonPath('data.reassigned.total', 1);
        $this->assertDatabaseMissing($table, ['id' => $source]);
        $this->assertDatabaseHas('new_buildings', ['id' => $building, 'version' => 7, ...($column ? [$column => $replacement] : [])]);
        if (! $column) {
            $this->assertDatabaseHas('feature_new_building', ['new_building_id' => $building, 'feature_id' => $replacement]);
        }
        $this->assertSame($before, (array) DB::table('developer_units')->find($unit));
        $this->assertDatabaseHas('reference_catalog_merge_audits', ['actor_user_id' => $actor->id, 'catalog' => $catalog, 'source_id' => $source, 'replacement_id' => $replacement, 'reassigned_count' => 1]);
        $this->getJson($path.'/replacements')->assertNotFound();
    }

    public function test_replacement_search_validates_query_and_searches_slugs(): void
    {
        $this->actingAsRole('agent');
        $source = $this->createCatalogItem('materials', 'Source');
        $replacement = DB::table('materials')->insertGetId(['name' => 'Other', 'slug' => 'needle-slug']);
        $path = '/api/admin/catalogs/materials/'.$source.'/replacements';
        $this->getJson($path.'?search=needle-slug')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $replacement);
        foreach (['page=0', 'per_page=0', 'per_page=101', 'search[]=x', 'search='.str_repeat('a', 256)] as $invalid) {
            $this->getJson($path.'?'.$invalid)->assertUnprocessable();
        }
    }

    #[DataProvider('roles')]
    public function test_other_catalogs_are_not_opened_to_agent_and_mop(string $role): void
    {
        $this->actingAsRole($role);
        foreach (['roles', 'branches', 'branch-groups', 'property-types', 'tags', 'client-types'] as $catalog) {
            $path = '/api/admin/catalogs/'.$catalog.'/1';
            $this->getJson($path.'/usage')->assertForbidden();
            $this->getJson($path.'/replacements')->assertForbidden();
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
            $this->getJson('/api/admin/catalogs/materials/1/replacements')->assertForbidden();
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
