<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Http\Middleware\LogApiRequest;
use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use App\Models\NewBuildingEntrance;
use App\Models\Role;
use App\Models\User;
use App\Services\Residential\InventoryStatus;
use App\Services\Residential\InventoryWriter;
use App\Services\Residential\UnitPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ResidentialSchema;
use Tests\TestCase;

class ResidentialInventoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ResidentialSchema::create();
        $this->withoutMiddleware([EnsureDailyReportSubmitted::class, LogApiRequest::class]);
        Http::preventStrayRequests();
    }

    private function actor(string $role, ?int $branch = null): User
    {
        $role = Role::firstOrCreate(['slug' => $role], ['name' => $role]);

        return User::create(['name' => $role->slug, 'phone' => '+992'.random_int(100000000, 999999999), 'role_id' => $role->id, 'branch_id' => $branch]);
    }

    private function building(array $attributes = []): NewBuilding
    {
        return NewBuilding::create(['title' => 'ЖК Aura', 'publication_status' => 'published', 'moderation_status' => 'approved', ...$attributes]);
    }

    private function unit(NewBuilding $building, array $attributes = []): DeveloperUnit
    {
        return $building->units()->create(['name' => 'Квартира', 'area' => '60.00', 'rooms' => 2, 'bedrooms' => 2, 'price_per_sqm' => '10000.00', 'total_price' => '600000.00', 'publication_status' => 'published', 'availability_status' => 'available', 'moderation_status' => 'available', 'is_available' => true, ...$attributes]);
    }

    public function test_catalog_matches_all_apartment_filters_in_the_same_lot(): void
    {
        $building = $this->building();
        $this->unit($building, ['rooms' => 1, 'total_price' => '100000.00']);
        $this->unit($building, ['rooms' => 2, 'total_price' => '900000.00']);
        $this->getJson('/api/new-buildings?rooms[]=2&price_max=200000')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('meta.total_available_units', 0);
        $this->getJson('/api/new-buildings?rooms[]=2&price_min=900000&price_max=900000')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.available_count', 1)->assertJsonPath('data.0.min_total_price', '900000.00');
    }

    public function test_catalog_aggregates_and_total_units_preserve_building_scope_across_pages_and_price_sort(): void
    {
        $first = $this->building(['district' => 'QA selected']);
        $second = $this->building(['district' => 'QA selected']);
        $empty = $this->building(['district' => 'QA selected']);
        foreach ([$first, $second] as $building) {
            $this->unit($building, ['total_price' => '500000.25']);
            $this->unit($building, ['total_price' => '700000.75']);
            $this->unit($building, ['total_price' => '1.00', 'availability_status' => 'reserved']);
            $this->unit($building, ['total_price' => '2.00', 'publication_status' => 'draft']);
            $archived = $building->blocks()->create(['name' => 'Archived', 'archived_at' => now()]);
            $this->unit($building, ['block_id' => $archived->id, 'total_price' => '3.00']);
        }
        $this->unit($this->building(['district' => 'QA other']), ['total_price' => '4.00']);
        $this->unit($this->building(['district' => 'QA selected', 'publication_status' => 'draft']));
        $this->unit($this->building(['district' => 'QA selected', 'publication_status' => 'archived']));
        // An exact tie is ordered by ID; an unknown price stays last in either direction.
        foreach (['price_asc', 'price_desc'] as $sort) {
            foreach ([$first, $second, $empty] as $index => $building) {
                $this->getJson('/api/new-buildings?'.http_build_query(['district' => 'QA selected', 'sort' => $sort, 'per_page' => 1, 'page' => $index + 1]))
                    ->assertOk()->assertJsonPath('total', 3)->assertJsonPath('meta.total_available_units', 4)
                    ->assertJsonPath('data.0.id', $building->id)
                    ->assertJsonPath('data.0.available_count', $building->is($empty) ? 0 : 2)
                    ->assertJsonPath('data.0.reserved_count', $building->is($empty) ? 0 : 1)
                    ->assertJsonPath('data.0.min_total_price', $building->is($empty) ? null : '500000.25')
                    ->assertJsonPath('data.0.max_total_price', $building->is($empty) ? null : '700000.75');
            }
        }
        foreach ([1, 2, 3] as $page) {
            $this->getJson('/api/new-buildings?'.http_build_query(['district' => 'QA selected', 'rooms' => ['2'], 'price_max' => 600000, 'per_page' => 1, 'page' => $page]))
                ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('meta.total_available_units', 2)
                ->assertJsonCount($page > 2 ? 0 : 1, 'data');
        }
    }

    public function test_ordinary_new_building_listings_remain_separate_from_complexes_and_unique_lots(): void
    {
        \Tests\Support\ResidentialOrdinaryListings::createSchema();
        $creator = $this->actor('agent');
        $location = DB::table('locations')->insertGetId(['city' => 'QA city', 'district' => 'QA district']);
        \Tests\Support\ResidentialOrdinaryListings::seed($creator->id, $location);
        $building = $this->building(['location_id' => $location]);
        $unit = $this->unit($building);
        $this->assertSame(1, $building->id);
        $this->assertSame(1, $unit->id);

        $ordinary = $this->getJson('/api/properties?type_id=2&offer_type=sale')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('data.0.type.name', 'Новостройки')
            ->assertJsonPath('data.0.title', 'QA обычное объявление — Новостройки');
        $this->assertEquals(735000, $ordinary->json('data.0.price'));
        $this->getJson('/api/properties?type_id=3&offer_type=sale')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', 2)
            ->assertJsonPath('data.0.type.name', 'Вторичка');
        $this->getJson('/api/properties/count?type_id=2&offer_type=sale')->assertOk()->assertJsonPath('count', 1);
        $this->getJson('/api/properties/1')->assertOk()
            ->assertJsonPath('title', 'QA обычное объявление — Новостройки')
            ->assertJsonPath('type.name', 'Новостройки');
        $this->getJson('/api/new-buildings')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.title', 'ЖК Aura')->assertJsonPath('data.0.available_count', 1)
            ->assertJsonPath('data.0.min_total_price', '600000.00');
        $this->getJson('/api/new-buildings/1')->assertOk()->assertJsonPath('data.title', 'ЖК Aura');
        $this->getJson('/api/new-buildings/1/units/1')->assertOk()
            ->assertJsonPath('id', 1)->assertJsonPath('total_price', '600000.00');
        $this->getJson('/api/new-buildings/1/units/2')->assertNotFound();
        $this->assertDatabaseCount('properties', 2);
        $this->assertDatabaseCount('new_buildings', 1);
        $this->assertDatabaseCount('developer_units', 1);
    }

    public function test_exact_room_links_studio_aliases_and_four_plus_keep_the_same_catalog_and_unit_scope(): void
    {
        $building = $this->building();
        $ids = [];
        foreach ([0, 2, 4, 5, 20] as $rooms) {
            $ids[$rooms] = $this->unit($building, ['rooms' => $rooms, 'bedrooms' => $rooms])->id;
        }
        $this->unit($building, ['rooms' => 5, 'availability_status' => 'reserved']);
        $this->unit($building, ['rooms' => 5, 'publication_status' => 'draft']);

        foreach ([
            [['0'], [0]], [['studio'], [0]], [['4'], [4]], [['5'], [5]], [['20'], [20]],
            [['4+'], [4, 5, 20]], [['5', '2'], [2, 5]], [['0', 'studio'], [0]],
        ] as [$selected, $expectedRooms]) {
            $query = '?'.http_build_query(['rooms' => $selected]);
            $catalog = $this->getJson('/api/new-buildings'.$query)->assertOk()
                ->assertJsonPath('meta.total_complexes', 1)
                ->assertJsonPath('meta.total_available_units', count($expectedRooms))
                ->assertJsonPath('data.0.available_count', count($expectedRooms));
            $this->assertEqualsCanonicalizing($expectedRooms, array_column($catalog->json('data.0.rooms_summary'), 'rooms'));
            $units = $this->getJson('/api/new-buildings/'.$building->id.'/units'.$query)->assertOk()
                ->assertJsonPath('total', count($expectedRooms));
            $this->assertEqualsCanonicalizing(array_map(fn ($rooms) => $ids[$rooms], $expectedRooms), array_column($units->json('data'), 'id'));
        }
    }

    public function test_map_preserves_catalog_filters_same_lot_prices_and_missing_coordinate_coverage(): void
    {
        $mapped = $this->building(['latitude' => '38.57', 'longitude' => '68.78']);
        $this->unit($mapped, ['rooms' => 2, 'total_price' => '900000.00']);
        $this->unit($mapped, ['rooms' => 1, 'total_price' => '100000.00']);
        $missing = $this->building();
        $this->unit($missing, ['rooms' => 2, 'total_price' => '900000.00']);
        $hidden = $this->building(['latitude' => '38.57', 'longitude' => '68.78', 'publication_status' => 'draft']);
        $this->unit($hidden);
        $sold = $this->building(['latitude' => '38.58', 'longitude' => '68.79']);
        $this->unit($sold, ['availability_status' => 'sold', 'rooms' => 2, 'total_price' => '900000.00']);

        $filter = '?rooms[]=2&price_min=900000&price_max=900000';
        $this->getJson('/api/new-buildings'.$filter)->assertOk()->assertJsonPath('meta.total_complexes', 2)->assertJsonPath('meta.total_available_units', 2);
        $this->getJson('/api/new-buildings/map'.$filter)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mapped->id)
            ->assertJsonPath('data.0.available_count', 1)->assertJsonPath('data.0.min_total_price', '900000.00')
            ->assertJsonPath('meta.without_coordinates', 1)->assertJsonPath('meta.total_complexes', 2)
            ->assertJsonPath('meta.truncated', false)->assertJsonStructure(['meta' => ['as_of']]);
        $this->getJson('/api/new-buildings/map?rooms[]=2&price_max=200000')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total_complexes', 0);
    }

    public function test_map_bbox_uses_longitude_latitude_order_and_matches_the_list(): void
    {
        $inside = $this->building(['latitude' => '38.57', 'longitude' => '68.78']);
        $this->building(['latitude' => '39.10', 'longitude' => '69.10']);
        $this->building();
        $bbox = '?'.http_build_query(['bbox' => [68.70, 38.50, 68.90, 38.60]]);
        foreach (['/api/new-buildings', '/api/new-buildings/map'] as $path) {
            $this->getJson($path.$bbox)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inside->id)->assertJsonPath('meta.total_complexes', 1);
            $this->getJson($path.'?'.http_build_query(['bbox' => [68.90, 38.60, 68.70, 38.50]]))->assertUnprocessable();
            $this->getJson($path.'?'.http_build_query(['bbox' => [68.70, -100, 68.90, 38.60]]))->assertUnprocessable();
        }
    }

    public function test_map_caps_markers_without_silently_changing_the_total(): void
    {
        $rows = array_fill(0, 2001, ['title' => 'QA map marker', 'latitude' => '38.57', 'longitude' => '68.78', 'publication_status' => 'published', 'moderation_status' => 'approved']);
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('new_buildings')->insert($chunk);
        }
        $this->getJson('/api/new-buildings/map')->assertOk()->assertJsonCount(2000, 'data')
            ->assertJsonPath('data.0.id', 1)->assertJsonPath('data.1999.id', 2000)
            ->assertJsonPath('meta.total_complexes', 2001)->assertJsonPath('meta.truncated', true)
            ->assertJsonPath('meta.without_coordinates', 0);
    }

    public function test_new_building_characteristics_preserve_unknown_and_explicit_no_separately(): void
    {
        Sanctum::actingAs($this->actor('admin'));
        $id = $this->postJson('/api/new-buildings', ['title' => 'Новый ЖК', 'publication_status' => 'draft'])->assertCreated()->assertJsonPath('heating', null)->assertJsonPath('has_terrace', null)->json('id');
        $this->putJson('/api/new-buildings/'.$id, ['version' => 1, 'heating' => false, 'has_terrace' => true])->assertOk()->assertJsonPath('heating', false)->assertJsonPath('has_terrace', true);
        $this->putJson('/api/new-buildings/'.$id, ['version' => 2, 'heating' => null])->assertOk()->assertJsonPath('heating', null)->assertJsonPath('has_terrace', true);
    }

    public function test_explicit_unknown_completion_clears_stale_dates_for_buildings_and_blocks(): void
    {
        Sanctum::actingAs($this->actor('admin'));
        $dates = ['completion_precision' => 'date', 'completion_at' => '2028-08-27', 'completion_year' => 2028, 'completion_quarter' => 3];
        $building = $this->building(['publication_status' => 'draft', 'moderation_status' => 'draft', ...$dates]);
        $block = $building->blocks()->create(['name' => 'A', ...$dates]);

        foreach (['/api/new-buildings/'.$building->id, '/api/new-buildings/'.$building->id.'/blocks/'.$block->id] as $path) {
            $this->putJson($path, ['name' => 'A', 'version' => 1, 'completion_precision' => 'unknown'])
                ->assertOk()->assertJsonPath('completion_precision', 'unknown')
                ->assertJsonPath('completion_at', null)->assertJsonPath('completion_year', null)->assertJsonPath('completion_quarter', null);
        }
    }

    public function test_completion_precision_keeps_only_relevant_values_and_rejects_incomplete_changes(): void
    {
        Sanctum::actingAs($this->actor('admin'));
        $building = $this->building(['publication_status' => 'draft', 'moderation_status' => 'draft', 'completion_precision' => 'date', 'completion_at' => '2028-08-27']);
        $block = $building->blocks()->create(['name' => 'A', 'completion_precision' => 'date', 'completion_at' => '2028-08-27']);
        foreach (['/api/new-buildings/'.$building->id, '/api/new-buildings/'.$building->id.'/blocks/'.$block->id] as $path) {
            $this->putJson($path, ['name' => 'A', 'version' => 1, 'completion_precision' => 'quarter', 'completion_year' => 2029, 'completion_quarter' => 2])
                ->assertOk()->assertJsonPath('completion_at', null)->assertJsonPath('completion_year', 2029)->assertJsonPath('completion_quarter', 2);
            $this->putJson($path, ['name' => 'A', 'version' => 2, 'completion_precision' => 'date'])
                ->assertUnprocessable()->assertJsonStructure(['details' => ['errors' => ['completion_at']]]);
            // The failed update did not advance version or partially clear the last confirmed quarter.
            $this->putJson($path, ['name' => 'A', 'version' => 2, 'completion_precision' => 'year', 'completion_year' => 2030])
                ->assertOk()->assertJsonPath('completion_at', null)->assertJsonPath('completion_year', 2030)->assertJsonPath('completion_quarter', null);
            $this->putJson($path, ['name' => 'A', 'version' => 3, 'completion_precision' => 'date', 'completion_at' => '2031-08-27'])
                ->assertOk()->assertJsonPath('completion_year', null)->assertJsonPath('completion_quarter', null);
        }
        $this->assertSame('2031-08-27', $building->refresh()->completion_at->toDateString());
        $this->assertSame('2031-08-27', $block->refresh()->completion_at->toDateString());
    }

    public function test_legacy_partial_updates_preserve_completion_when_precision_is_omitted(): void
    {
        Sanctum::actingAs($this->actor('admin'));
        $building = $this->building(['publication_status' => 'draft', 'moderation_status' => 'draft', 'completion_at' => '2028-08-27']);
        $block = $building->blocks()->create(['name' => 'A', 'completion_at' => '2028-08-27']);
        $this->putJson('/api/new-buildings/'.$building->id, ['version' => 1, 'title' => 'Новое название'])->assertOk();
        $this->putJson('/api/new-buildings/'.$building->id.'/blocks/'.$block->id, ['version' => 1, 'name' => 'B'])->assertOk();
        $this->assertSame('2028-08-27', $building->refresh()->completion_at->toDateString());
        $this->assertSame('2028-08-27', $block->refresh()->completion_at->toDateString());
    }

    public function test_admin_catalog_filters_preserve_the_authors_visibility_scope(): void
    {
        $developer = DB::table('developers')->insertGetId(['name' => 'QA developer']);
        $otherDeveloper = DB::table('developers')->insertGetId(['name' => 'Other developer']);
        $stage = DB::table('construction_stages')->insertGetId(['name' => 'QA stage', 'slug' => 'qa']);
        $otherStage = DB::table('construction_stages')->insertGetId(['name' => 'Other stage', 'slug' => 'other']);
        $author = $this->actor('mop');
        $match = $this->building(['created_by' => $author->id, 'developer_id' => $developer, 'construction_stage_id' => $stage]);
        $this->building(['created_by' => $author->id, 'developer_id' => $developer, 'construction_stage_id' => $otherStage]);
        $this->building(['created_by' => $author->id, 'developer_id' => $otherDeveloper, 'construction_stage_id' => $stage]);
        $this->building(['developer_id' => $developer, 'construction_stage_id' => $stage]);
        Sanctum::actingAs($author);
        $this->getJson('/api/admin/new-buildings?developer_id='.$developer.'&stage_id='.$stage)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $match->id);
        $this->getJson('/api/admin/new-buildings?developer_id='.$developer)->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/admin/new-buildings?stage_id='.$stage)->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/admin/new-buildings?stage_id=invalid')->assertUnprocessable()->assertJsonStructure(['details' => ['errors' => ['stage_id']]]);
        Sanctum::actingAs($this->actor('admin'));
        $this->getJson('/api/admin/new-buildings?developer_id='.$developer.'&stage_id='.$stage)->assertOk()->assertJsonPath('total', 2);
    }

    public function test_conflicting_legacy_and_canonical_status_payloads_are_rejected_without_partial_write(): void
    {
        Sanctum::actingAs($this->actor('admin'));
        $building = $this->building();
        $unit = $this->unit($building);
        $path = '/api/new-buildings/'.$building->id.'/units/'.$unit->id;
        $this->patchJson($path, ['version' => 1, 'publication_status' => 'draft', 'moderation_status' => 'available'])->assertUnprocessable();
        $this->patchJson($path, ['version' => 1, 'availability_status' => 'sold', 'moderation_status' => 'reserved'])->assertUnprocessable();
        $this->patchJson($path, ['version' => 1, 'availability_status' => 'sold', 'is_available' => true])->assertUnprocessable();
        $this->putJson('/api/new-buildings/'.$building->id, ['version' => 1, 'publication_status' => 'draft', 'moderation_status' => 'approved'])->assertUnprocessable();
        $this->assertDatabaseHas('developer_units', ['id' => $unit->id, 'version' => 1, 'availability_status' => 'available']);
        $this->patchJson($path, ['version' => 1, 'publication_status' => 'draft', 'availability_status' => 'available', 'moderation_status' => 'pending', 'is_available' => false])->assertOk();
    }

    public function test_completion_sort_respects_quarters_years_dates_and_unknowns_without_changing_calendar_days(): void
    {
        $unknown = $this->building();
        $year = $this->building(['completion_precision' => 'year', 'completion_year' => 2028]);
        $quarter = $this->building(['completion_precision' => 'quarter', 'completion_year' => 2028, 'completion_quarter' => 2]);
        $date = $this->building(['completion_precision' => 'date', 'completion_at' => '2028-08-27']);
        $early = $this->building(['completion_precision' => 'date', 'completion_at' => '2028-01-20']);
        $response = $this->getJson('/api/new-buildings?sort=completion')->assertOk();
        $this->assertSame([$early->id, $quarter->id, $date->id, $year->id, $unknown->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.2.completion_at', '2028-08-27');
        Sanctum::actingAs($this->actor('admin'));
        $this->getJson('/api/admin/new-buildings/'.$date->id)->assertOk()->assertJsonPath('data.completion_at', '2028-08-27');
        $this->putJson('/api/new-buildings/'.$date->id, ['version' => 1, 'publication_status' => 'draft', 'housing_class' => 'Комфорт', 'parking' => 'Подземная', 'advantages' => ['Двор', 'Парк']])->assertOk()->assertJsonPath('housing_class', 'Комфорт')->assertJsonPath('advantages.1', 'Парк');
    }

    public function test_similar_lots_are_free_same_complex_first_then_same_city_with_explicit_expansion(): void
    {
        $city = DB::table('locations')->insertGetId(['city' => 'Душанбе']);
        $district = DB::table('locations')->insertGetId(['city' => 'Душанбе', 'district' => 'Другой район']);
        $foreignCity = DB::table('locations')->insertGetId(['city' => 'Худжанд']);
        $building = $this->building(['location_id' => $city]);
        $other = $this->building(['location_id' => $district]);
        $foreign = $this->building(['location_id' => $foreignCity]);
        $source = $this->unit($building, ['availability_status' => 'sold']);
        $near = $this->unit($building, ['area' => 62]);
        $expanded = $this->unit($building, ['area' => 82]);
        $sameCity = $this->unit($other, ['area' => 60]);
        $this->unit($foreign, ['area' => 60]);
        $this->unit($building, ['availability_status' => 'reserved']);
        $this->unit($building, ['publication_status' => 'draft']);
        $this->unit($building, ['rooms' => 1]);
        $this->unit($building, ['total_price' => 2000000]);
        $path = '/api/new-buildings/'.$building->id.'/units/'.$source->id.'/similar';
        $response = $this->getJson($path)->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.id', $near->id)->assertJsonPath('data.1.id', $expanded->id)->assertJsonPath('data.1.similarity_rule', 'expanded_complex')->assertJsonPath('data.2.id', $sameCity->id);
        $this->assertSame(['available'], array_values(array_unique(array_column($response->json('data'), 'availability_status'))));
        $this->getJson('/api/new-buildings/'.$foreign->id.'/units/'.$source->id.'/similar')->assertNotFound();
        $this->getJson('/api/new-buildings/'.$building->id.'/similar')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other->id);
        $source->update(['publication_status' => 'draft']);
        $this->getJson($path)->assertNotFound();
    }

    public function test_canonical_form_requires_explicit_price_and_preserves_unknown_values(): void
    {
        Sanctum::actingAs($this->actor('admin'));
        $building = $this->building();
        $path = '/api/new-buildings/'.$building->id.'/units';
        $payload = ['name' => 'A1', 'area' => '63.37', 'publication_status' => 'draft', 'availability_status' => 'available', 'rooms' => null, 'bathrooms' => null];
        $this->postJson($path, $payload)->assertUnprocessable();
        $this->postJson($path, $payload + ['price_on_request' => false])->assertUnprocessable();
        $id = $this->postJson($path, $payload + ['price_on_request' => true])->assertCreated()->assertJsonPath('rooms', null)->assertJsonPath('bathrooms', null)->assertJsonPath('total_price', null)->json('id');
        $this->patchJson($path.'/'.$id, ['version' => 1, 'rooms' => 0, 'price_on_request' => false, 'pricing_basis' => 'per_sqm', 'price_per_sqm' => '8199.99'])->assertOk()->assertJsonPath('total_price', '519633.37');
        $this->getJson('/api/admin/new-buildings/'.$building->id.'/units/'.$id)->assertOk()->assertJsonPath('rooms', 0)->assertJsonPath('version', 2);
        $this->patchJson($path.'/'.$id, ['version' => 2, 'rooms' => 3])->assertOk();
        $this->patchJson($path.'/'.$id, ['version' => 3, 'rooms' => null, 'bathrooms' => null])->assertOk()->assertJsonPath('rooms', null)->assertJsonPath('bedrooms', 0);
        $this->getJson('/api/admin/new-buildings/'.$building->id.'/units/'.$id)->assertOk()->assertJsonPath('rooms', null);
        $this->patchJson($path.'/'.$id, ['version' => 4, 'rooms' => 2, 'bedrooms' => 1])->assertUnprocessable();
        $this->patchJson($path.'/'.$id, ['version' => 3, 'rooms' => 2])->assertStatus(409);
        $this->assertDatabaseHas('developer_units', ['id' => $id, 'version' => 4, 'rooms' => null, 'bedrooms' => 0, 'bathrooms' => null]);
    }

    public function test_counts_prices_reserved_and_drafts_use_the_same_public_scope(): void
    {
        $building = $this->building();
        foreach (range(1, 10) as $_) {
            $this->unit($building);
        }
        foreach (range(1, 2) as $_) {
            $this->unit($building, ['availability_status' => 'reserved', 'total_price' => '1.00']);
        }
        foreach (range(1, 3) as $_) {
            $this->unit($building, ['availability_status' => 'sold']);
        }
        foreach (range(1, 4) as $_) {
            $this->unit($building, ['publication_status' => 'draft']);
        }
        $this->getJson('/api/new-buildings/'.$building->id.'/units')->assertOk()->assertJsonPath('total', 10)->assertJsonPath('meta.available_count', 10);
        $this->getJson('/api/new-buildings/'.$building->id.'/units?include_reserved=1')->assertOk()->assertJsonPath('total', 12)->assertJsonPath('meta.available_count', 10)->assertJsonPath('meta.reserved_count', 2);
        $this->getJson('/api/new-buildings/'.$building->id)->assertOk()->assertJsonPath('data.min_total_price', '600000.00')->assertJsonPath('data.available_count', 10);
    }

    public function test_public_endpoints_do_not_leak_drafts_or_cross_building_units_and_photos(): void
    {
        $building = $this->building();
        $other = $this->building();
        $draft = $this->building(['publication_status' => 'draft']);
        $unit = $this->unit($draft);
        $hidden = $this->unit($building, ['publication_status' => 'draft']);
        foreach (['', '/units', '/photos', '/blocks', '/unit-facets'] as $suffix) {
            $this->getJson('/api/new-buildings/'.$draft->id.$suffix)->assertNotFound();
        }
        foreach ([$unit, $hidden] as $record) {
            $this->getJson('/api/new-buildings/'.$record->new_building_id.'/units/'.$record->id)->assertNotFound();
            $this->getJson('/api/new-buildings/'.$record->new_building_id.'/units/'.$record->id.'/photos')->assertNotFound();
        }
        $public = $this->unit($building);
        $this->getJson('/api/new-buildings/'.$other->id.'/units/'.$public->id)->assertNotFound();
        $this->getJson('/api/new-buildings/'.$other->id.'/units/'.$public->id.'/photos')->assertNotFound();
    }

    public function test_legacy_adapter_preserves_clear_cases_but_not_contradictions_or_default_zero_rooms(): void
    {
        $building = $this->building(['publication_status' => null]);
        $clear = $this->unit($building, ['publication_status' => null, 'availability_status' => null, 'rooms' => null, 'bedrooms' => 0]);
        $this->unit($building, ['publication_status' => null, 'availability_status' => null, 'moderation_status' => 'reserved', 'is_available' => true]);
        $this->getJson('/api/new-buildings/'.$building->id.'/units?include_reserved=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.rooms', null);
        $this->getJson('/api/new-buildings/'.$building->id.'/units?rooms[]=studio')->assertOk()->assertJsonPath('total', 0);
        $this->assertSame(['published', 'available'], InventoryStatus::unit($clear->getAttributes()));
        $this->assertSame(['draft', 'withdrawn'], InventoryStatus::unit(['moderation_status' => 'reserved', 'is_available' => true]));
    }

    public function test_last_floor_is_defined_by_each_entrance_and_grid_preserves_geometry(): void
    {
        $building = $this->building();
        $block = $building->blocks()->create(['name' => 'A']);
        $low = NewBuildingEntrance::create(['new_building_id' => $building->id, 'block_id' => $block->id, 'name' => '1', 'residential_floor_from' => 1, 'residential_floor_to' => 5]);
        $high = NewBuildingEntrance::create(['new_building_id' => $building->id, 'block_id' => $block->id, 'name' => '2', 'residential_floor_from' => 1, 'residential_floor_to' => 10]);
        $this->unit($building, ['block_id' => $block->id, 'entrance_id' => $low->id, 'floor' => 5, 'position_on_floor' => 1]);
        $match = $this->unit($building, ['block_id' => $block->id, 'entrance_id' => $high->id, 'floor' => 5, 'position_on_floor' => 1]);
        $this->unit($building, ['block_id' => $block->id, 'entrance_id' => $high->id, 'floor' => 10, 'position_on_floor' => 1]);
        $this->getJson('/api/new-buildings/'.$building->id.'/units?exclude_last_floor=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $match->id);
        $this->getJson('/api/new-buildings/'.$building->id.'/availability-grid?block_id='.$block->id.'&entrance_id='.$high->id.'&exclude_last_floor=1')->assertOk()->assertJsonCount(2, 'data.cells')->assertJsonPath('meta.matched_count', 1)->assertJsonPath('data.cells.0.matches_filter', false)->assertJsonPath('data.cells.1.matches_filter', true);
    }

    public function test_last_floor_filters_use_each_entrance_across_different_height_blocks(): void
    {
        $building = $this->building();
        $short = $building->blocks()->create(['name' => 'Five floors', 'floors_from' => 1, 'floors_to' => 5]);
        $tall = $building->blocks()->create(['name' => 'Ten floors', 'floors_from' => 1, 'floors_to' => 10]);
        $cases = [];
        foreach ([[$short, 4, 5], [$tall, 5, 10], [$tall, 6, 8]] as [$block, $lowerFloor, $lastFloor]) {
            $entrance = $building->entrances()->create(['block_id' => $block->id, 'name' => 'Up to '.$lastFloor, 'residential_floor_from' => 1, 'residential_floor_to' => $lastFloor]);
            $lower = $this->unit($building, ['block_id' => $block->id, 'entrance_id' => $entrance->id, 'floor' => $lowerFloor, 'position_on_floor' => 1]);
            $last = $this->unit($building, ['block_id' => $block->id, 'entrance_id' => $entrance->id, 'floor' => $lastFloor, 'position_on_floor' => 1]);
            $cases[] = ['block' => $block->id, 'entrance' => $entrance->id, 'lower' => $lower->id, 'last' => $last->id];
        }
        foreach (['exclude_last_floor' => 'lower', 'only_last_floor' => 'last'] as $filter => $matching) {
            $units = $this->getJson('/api/new-buildings/'.$building->id.'/units?'.$filter.'=1')->assertOk()->assertJsonPath('total', 3);
            $this->assertEqualsCanonicalizing(array_column($cases, $matching), array_column($units->json('data'), 'id'));
            $this->getJson('/api/new-buildings?'.$filter.'=1')->assertOk()->assertJsonPath('data.0.available_count', 3)->assertJsonPath('meta.total_available_units', 3);
            foreach ($cases as $case) {
                $grid = $this->getJson('/api/new-buildings/'.$building->id.'/availability-grid?'.http_build_query([
                    'block_id' => $case['block'], 'entrance_id' => $case['entrance'], $filter => 1,
                ]))->assertOk()->assertJsonCount(2, 'data.cells')->assertJsonPath('meta.matched_count', 1);
                $cells = array_values(array_filter($grid->json('data.cells'), fn ($cell) => $cell['matches_filter']));
                $this->assertSame($case[$matching], $cells[0]['id']);
            }
        }
    }

    public function test_ranges_and_filter_conflicts_are_validated_and_decimal_commas_work(): void
    {
        $building = $this->building();
        $this->unit($building, ['area' => '60.50']);
        foreach (['price_min=5&price_max=1', 'only_last_floor=1&exclude_last_floor=1', 'per_page=101', 'floor_min=10&floor_max=2', 'rooms_from=3&rooms_to=1'] as $query) {
            $this->getJson('/api/new-buildings/'.$building->id.'/units?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/new-buildings/'.$building->id.'/units?area_min=60,50&area_max=60,50')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_empty_complex_has_no_invented_price_or_contact_and_aggregate_queries_are_bounded(): void
    {
        $building = $this->building();
        $this->getJson('/api/new-buildings/'.$building->id)->assertOk()->assertJsonPath('data.min_total_price', null)->assertJsonPath('data.consultant', null)->assertJsonPath('data.available_count', 0);
        foreach (range(1, 15) as $_) {
            $this->unit($this->building());
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/new-buildings?per_page=1')->assertOk();
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->getJson('/api/new-buildings?per_page=15')->assertOk();
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($one + 2, $many, 'No per-card queries');
    }

    public function test_agent_and_mop_create_drafts_but_cannot_publish_or_read_another_authors_admin_data(): void
    {
        foreach (['agent', 'mop'] as $role) {
            $actor = $this->actor($role);
            Sanctum::actingAs($actor);
            $created = $this->postJson('/api/new-buildings', ['title' => 'Черновик'])->assertCreated()->assertJsonPath('publication_status', 'draft');
            $id = $created->json('id');
            $this->getJson('/api/admin/new-buildings/'.$id)->assertOk()->assertJsonPath('capabilities.publish', false);
            $this->patchJson('/api/new-buildings/'.$id, ['version' => 1, 'publication_status' => 'published'])->assertForbidden();
            $this->patchJson('/api/new-buildings/'.$id, ['version' => 1, 'publication_status' => 'pending'])->assertOk()->assertJsonPath('version', 2);
            $other = $this->building(['created_by' => $this->actor('agent')->id]);
            $this->getJson('/api/admin/new-buildings/'.$other->id)->assertForbidden();
            $this->patchJson('/api/new-buildings/'.$other->id, ['version' => 1, 'title' => 'Нельзя'])->assertForbidden();
        }
    }

    public function test_clients_hr_and_accountants_have_no_mutation_or_admin_read_access(): void
    {
        $building = $this->building();
        foreach (['client', 'hr', 'accountant'] as $role) {
            Sanctum::actingAs($this->actor($role));
            $this->postJson('/api/new-buildings', ['title' => 'Нет'])->assertForbidden();
            $this->getJson('/api/admin/new-buildings/'.$building->id)->assertForbidden();
            $this->postJson('/api/new-buildings/'.$building->id.'/units', ['area' => 20])->assertForbidden();
            $this->postJson('/api/new-buildings/'.$building->id.'/blocks', ['name' => 'Нет'])->assertForbidden();
        }
    }

    public function test_managers_moderate_only_their_branch_and_contact_must_match_branch(): void
    {
        $branch = DB::table('branches')->insertGetId(['name' => 'A']);
        $otherBranch = DB::table('branches')->insertGetId(['name' => 'B']);
        $location = DB::table('locations')->insertGetId(['city' => 'Душанбе', 'district' => 'Центр']);
        foreach (['rop', 'branch_director'] as $role) {
            $actor = $this->actor($role, $branch);
            Sanctum::actingAs($actor);
            $building = $this->building(['publication_status' => 'pending', 'branch_id' => $branch, 'responsible_agent_id' => $actor->id, 'location_id' => $location, 'address' => 'Адрес']);
            $this->patchJson('/api/new-buildings/'.$building->id, ['version' => 1, 'publication_status' => 'published'])->assertUnprocessable();
            $this->patchJson('/api/new-buildings/'.$building->id, ['version' => 1, 'publication_status' => 'published', 'data_verified_at' => now()->toIso8601String()])->assertOk();
            $other = $this->building(['branch_id' => $otherBranch]);
            $this->patchJson('/api/new-buildings/'.$other->id, ['version' => 1, 'publication_status' => 'published'])->assertForbidden();
        }
    }

    public function test_optimistic_lock_rejects_old_write_and_audits_and_archives_without_deleting_lots(): void
    {
        $actor = $this->actor('admin');
        Sanctum::actingAs($actor);
        $building = $this->building();
        $unit = $this->unit($building);
        $this->patchJson('/api/new-buildings/'.$building->id.'/units/'.$unit->id, ['version' => 1, 'pricing_basis' => 'total', 'total_price' => '600000.01'])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('total_price', '600000.01');
        $this->patchJson('/api/new-buildings/'.$building->id.'/units/'.$unit->id, ['version' => 1, 'total_price' => '1.00'])->assertStatus(409);
        $this->assertDatabaseHas('developer_units', ['id' => $unit->id, 'total_price' => '600000.01', 'version' => 2]);
        $this->assertDatabaseCount('crm_audit_logs', 1);
        $this->deleteJson('/api/new-buildings/'.$building->id, ['version' => 1])->assertNoContent();
        $this->assertDatabaseHas('new_buildings', ['id' => $building->id, 'publication_status' => 'archived']);
        $this->assertDatabaseCount('developer_units', 1);
        $this->getJson('/api/new-buildings/'.$building->id.'/units/'.$unit->id)->assertNotFound();
    }

    public function test_unit_nested_links_and_positions_are_validated_and_no_mass_move_is_possible(): void
    {
        Sanctum::actingAs($this->actor('admin'));
        $building = $this->building();
        $other = $this->building();
        $foreign = $other->blocks()->create(['name' => 'Чужой']);
        $base = ['area' => '50.00', 'rooms' => 2, 'price_on_request' => true];
        $path = '/api/new-buildings/'.$building->id.'/units';
        $this->postJson($path, $base + ['new_building_id' => $other->id])->assertUnprocessable();
        $this->postJson($path, $base + ['block_id' => $foreign->id])->assertUnprocessable();
        $block = $building->blocks()->create(['name' => 'A']);
        $entrance = NewBuildingEntrance::create(['new_building_id' => $building->id, 'block_id' => $block->id, 'name' => '1', 'residential_floor_from' => 1, 'residential_floor_to' => 5]);
        $payload = $base + ['block_id' => $block->id, 'entrance_id' => $entrance->id, 'floor' => 3, 'position_on_floor' => 1, 'number' => '17'];
        $this->postJson($path, $payload)->assertCreated();
        $this->postJson($path, $payload)->assertUnprocessable();
        $this->postJson($path, array_replace($payload, ['floor' => 6, 'number' => '18']))->assertUnprocessable();
    }

    public function test_exact_decimal_price_and_snapshot_preserve_legacy_values(): void
    {
        $price = app(UnitPrice::class)->calculate(['area' => '63.37', 'pricing_basis' => 'per_sqm', 'price_per_sqm' => '8199.99']);
        $this->assertSame('519633.37', $price['total_price']);
        $actor = $this->actor('admin');
        $building = $this->building(['publication_status' => null]);
        app(InventoryWriter::class)->building($actor, ['version' => 1, 'publication_status' => 'draft'], $building);
        $snapshot = DB::table('residential_inventory_snapshots')->first();
        $this->assertSame('approved', json_decode($snapshot->original_values, true)['moderation_status']);
        $this->assertNull(json_decode($snapshot->original_values, true)['publication_status']);
    }

    public function test_expansion_migration_rolls_back_without_deleting_legacy_inventory(): void
    {
        $building = $this->building();
        $unit = $this->unit($building);
        (require database_path('migrations/2026_08_28_130000_expand_residential_complex_inventory.php'))->down();
        $this->assertDatabaseHas('new_buildings', ['id' => $building->id, 'title' => 'ЖК Aura']);
        $this->assertDatabaseHas('developer_units', ['id' => $unit->id, 'moderation_status' => 'available']);
    }

    public function test_structure_crud_scopes_entrances_and_never_overwrites_individual_layout_values(): void
    {
        Sanctum::actingAs($this->actor('admin'));
        $building = $this->building();
        $other = $this->building();
        $block = $building->blocks()->create(['name' => 'A', 'floors_from' => 1, 'floors_to' => 9]);
        $foreign = $other->blocks()->create(['name' => 'B']);
        $path = '/api/admin/new-buildings/'.$building->id;
        $payload = ['block_id' => $block->id, 'name' => '1', 'residential_floor_from' => 1, 'residential_floor_to' => 9];
        $this->postJson($path.'/entrances', array_replace($payload, ['block_id' => $foreign->id]))->assertUnprocessable();
        $entrance = $this->postJson($path.'/entrances', $payload)->assertCreated()->json('id');
        $layout = $this->postJson($path.'/layouts', ['code' => 'A2', 'rooms' => 2, 'typical_area' => '60.00'])->assertCreated()->json('id');
        $unit = $this->unit($building, ['block_id' => $block->id, 'entrance_id' => $entrance, 'layout_id' => $layout, 'floor' => 8, 'area' => '61.75']);
        $this->patchJson($path.'/layouts/'.$layout, ['version' => 1, 'code' => 'A2', 'typical_area' => '70.00'])->assertOk();
        $this->assertSame('61.75', $unit->fresh()->area);
        $this->assertSame('600000.00', $unit->fresh()->total_price);
        $this->patchJson($path.'/entrances/'.$entrance, array_replace($payload, ['version' => 1, 'residential_floor_to' => 7]))->assertUnprocessable();
        $this->deleteJson($path.'/layouts/'.$layout, ['version' => 2])->assertConflict();
        $this->deleteJson($path.'/entrances/'.$entrance, ['version' => 1])->assertConflict();
        $this->deleteJson('/api/new-buildings/'.$building->id.'/blocks/'.$block->id, ['version' => 1])->assertNoContent();
        $this->assertDatabaseHas('developer_units', ['id' => $unit->id]);
        $this->getJson('/api/new-buildings/'.$building->id.'/units')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_structure_edits_by_an_author_require_republication(): void
    {
        $actor = $this->actor('agent');
        Sanctum::actingAs($actor);
        $building = $this->building(['created_by' => $actor->id, 'responsible_agent_id' => $actor->id]);
        $this->postJson('/api/new-buildings/'.$building->id.'/blocks', ['name' => 'A', 'floors_from' => 1, 'floors_to' => 9])->assertCreated();
        $this->assertSame('pending', $building->fresh()->publication_status);
        $this->getJson('/api/new-buildings/'.$building->id.'/blocks')->assertNotFound();
        $this->getJson('/api/admin/new-buildings/'.$building->id.'/blocks')->assertOk()->assertJsonCount(1);
    }

    public function test_layout_replacement_requires_reviewed_usage_and_preserves_individual_lot_values(): void
    {
        $agent = $this->actor('agent');
        Sanctum::actingAs($agent);
        $building = $this->building(['created_by' => $agent->id]);
        $source = $building->layouts()->create(['code' => 'old', 'typical_area' => 40]);
        $target = $building->layouts()->create(['code' => 'new', 'typical_area' => 90]);
        $unit = $this->unit($building, ['layout_id' => $source->id]);
        $path = '/api/admin/new-buildings/'.$building->id.'/layouts/'.$source->id;
        $usage = $this->getJson($path.'/usage')->assertOk()->assertJsonPath('units', 1)->json();
        $this->deleteJson($path, ['version' => 1])->assertConflict();
        $payload = ['version' => 1, 'usage_token' => $usage['usage_token'], 'replacement_id' => $target->id, 'replacement_version' => 1];
        $unit->update(['version' => 2]);
        $this->deleteJson($path, $payload)->assertConflict();
        $payload['usage_token'] = $this->getJson($path.'/usage')->json('usage_token');
        $this->deleteJson($path, $payload)->assertNoContent();
        $this->assertDatabaseMissing('unit_layouts', ['id' => $source->id]);
        $this->assertDatabaseHas('developer_units', ['id' => $unit->id, 'layout_id' => $target->id, 'version' => 3, 'area' => 60, 'total_price' => 600000]);
        $this->assertDatabaseHas('new_buildings', ['id' => $building->id, 'publication_status' => 'pending']);
        $this->assertDatabaseHas('crm_audit_logs', ['event' => 'residential.reference_replaced']);
        $this->deleteJson($path, $payload)->assertNotFound();
    }

    public static function layoutReplacementEditors(): array
    {
        return ['admin' => ['admin'], 'agent' => ['agent'], 'mop' => ['mop']];
    }

    #[DataProvider('layoutReplacementEditors')]
    public function test_layout_replacement_rejects_a_changed_target_then_accepts_a_fresh_confirmation(string $role): void
    {
        $actor = $this->actor($role);
        Sanctum::actingAs($actor);
        $building = $this->building(['created_by' => $actor->id]);
        $source = $building->layouts()->create(['code' => 'source', 'rooms' => 2, 'typical_area' => '67.50']);
        $target = $building->layouts()->create(['code' => 'target', 'rooms' => 3, 'typical_area' => '99.90']);
        $unit = $this->unit($building, [
            'layout_id' => $source->id, 'rooms' => 1, 'area' => '41.50',
            'total_price' => '300000.55', 'publication_status' => 'draft',
        ]);
        $path = '/api/admin/new-buildings/'.$building->id.'/layouts/';
        $usage = $this->getJson($path.$source->id.'/usage')->assertOk()->assertJsonPath('units', 1)->json();
        $payload = [
            'version' => 1, 'usage_token' => $usage['usage_token'],
            'replacement_id' => $target->id, 'replacement_version' => 1,
        ];

        // A second editor changes the selected replacement after the dialog opens.
        $this->patchJson($path.$target->id, [
            'version' => 1, 'code' => 'target', 'rooms' => 3, 'typical_area' => '100.10',
        ])->assertOk()->assertJsonPath('version', 2);
        $beforeUnit = $unit->fresh()->getRawOriginal();
        $beforeBuilding = $building->fresh()->getRawOriginal();
        $beforeAudits = DB::table('crm_audit_logs')->count();
        $this->deleteJson($path.$source->id, $payload)->assertConflict();
        $this->assertSame($beforeUnit, $unit->fresh()->getRawOriginal());
        $this->assertSame($beforeBuilding, $building->fresh()->getRawOriginal());
        $this->assertDatabaseCount('crm_audit_logs', $beforeAudits);
        $this->assertDatabaseHas('unit_layouts', ['id' => $source->id, 'version' => 1]);

        $payload['usage_token'] = $this->getJson($path.$source->id.'/usage')->assertOk()->json('usage_token');
        $payload['replacement_version'] = 2;
        $this->deleteJson($path.$source->id, $payload)->assertNoContent();
        $afterUnit = $unit->fresh()->getRawOriginal();
        $this->assertSame($target->id, $afterUnit['layout_id']);
        $this->assertSame($beforeUnit['version'] + 1, $afterUnit['version']);
        foreach ($beforeUnit as $field => $value) {
            if (! in_array($field, ['layout_id', 'version', 'updated_at'], true)) {
                $this->assertSame($value, $afterUnit[$field], $field.' must not change during layout replacement');
            }
        }
        $this->assertDatabaseMissing('unit_layouts', ['id' => $source->id]);
        $this->assertDatabaseHas('unit_layouts', ['id' => $target->id, 'version' => 2]);
        $this->assertSame(1, DB::table('crm_audit_logs')->where('event', 'residential.reference_replaced')->where('auditable_id', $unit->id)->count());
        $this->assertSame($role === 'admin' ? 'published' : 'pending', $building->fresh()->publication_status);
        $this->deleteJson($path.$source->id, $payload)->assertNotFound();
        $this->assertSame($afterUnit, $unit->fresh()->getRawOriginal());
    }

    public function test_entrance_replacement_is_atomic_for_number_floor_and_plan_conflicts(): void
    {
        Sanctum::actingAs($this->actor('admin'));
        $building = $this->building();
        $block = $building->blocks()->create(['name' => 'A']);
        $source = $building->entrances()->create(['block_id' => $block->id, 'name' => '1', 'residential_floor_from' => 1, 'residential_floor_to' => 10]);
        $target = $building->entrances()->create(['block_id' => $block->id, 'name' => '2', 'residential_floor_from' => 1, 'residential_floor_to' => 10]);
        $lot = $this->unit($building, ['block_id' => $block->id, 'entrance_id' => $source->id, 'floor' => 5, 'number' => '10', 'position_on_floor' => 1]);
        $other = $this->unit($building, ['block_id' => $block->id, 'entrance_id' => $target->id, 'floor' => 5, 'number' => '10', 'position_on_floor' => 1]);
        $plan = $building->floorPlans()->create(['block_id' => $block->id, 'entrance_id' => $source->id, 'floor_from' => 5, 'floor_to' => 5]);
        $path = '/api/admin/new-buildings/'.$building->id.'/entrances/'.$source->id;
        $usage = $this->getJson($path.'/usage')->assertOk()->assertJsonPath('floor_plans', 1)->json();
        $payload = ['version' => 1, 'usage_token' => $usage['usage_token'], 'replacement_id' => $target->id, 'replacement_version' => 1];
        $this->deleteJson($path, $payload)->assertConflict();
        $this->assertDatabaseHas('developer_units', ['id' => $lot->id, 'entrance_id' => $source->id, 'version' => 1]);
        $other->update(['number' => '11', 'position_on_floor' => 2]);
        $target->update(['technical_floors' => [5]]);
        $this->deleteJson($path, $payload)->assertConflict();
        $target->update(['technical_floors' => []]);
        $conflictingPlan = $building->floorPlans()->create(['block_id' => $block->id, 'entrance_id' => $target->id, 'floor_from' => 4, 'floor_to' => 6]);
        $this->deleteJson($path, $payload)->assertConflict();
        $this->assertDatabaseHas('building_floor_plans', ['id' => $plan->id, 'entrance_id' => $source->id, 'version' => 1]);
        $conflictingPlan->delete();
        $this->deleteJson($path, $payload)->assertNoContent();
        $this->assertDatabaseHas('developer_units', ['id' => $lot->id, 'entrance_id' => $target->id, 'version' => 2]);
        $this->assertDatabaseHas('building_floor_plans', ['id' => $plan->id, 'entrance_id' => $target->id, 'version' => 2]);
    }

    public function test_reference_replacement_rejects_foreign_target_and_unauthorized_usage_read(): void
    {
        Sanctum::actingAs($this->actor('admin'));
        $building = $this->building();
        $source = $building->layouts()->create(['code' => 'A']);
        $foreign = $this->building()->layouts()->create(['code' => 'A']);
        $this->unit($building, ['layout_id' => $source->id]);
        $path = '/api/admin/new-buildings/'.$building->id.'/layouts/'.$source->id;
        $payload = ['version' => 1, 'usage_token' => $this->getJson($path.'/usage')->json('usage_token'), 'replacement_id' => $foreign->id, 'replacement_version' => 1];
        $this->deleteJson($path, $payload)->assertUnprocessable();
        $this->deleteJson($path, array_replace($payload, ['replacement_id' => $source->id]))->assertUnprocessable();
        Sanctum::actingAs($this->actor('client'));
        $this->getJson($path.'/usage')->assertForbidden();
        $this->deleteJson($path, $payload)->assertForbidden();
    }

    public function test_consultant_choices_exclude_clients_inactive_users_and_foreign_branches(): void
    {
        $branch = DB::table('branches')->insertGetId(['name' => 'A']);
        $foreign = DB::table('branches')->insertGetId(['name' => 'B']);
        $director = $this->actor('branch_director', $branch);
        $mop = $this->actor('mop', $branch);
        $client = $this->actor('client');
        $other = $this->actor('agent', $foreign);
        $inactive = $this->actor('agent', $branch);
        $inactive->update(['status' => 'inactive']);
        $building = $this->building(['branch_id' => $branch, 'responsible_agent_id' => $mop->id]);
        Sanctum::actingAs($director);
        $ids = $this->getJson('/api/admin/new-buildings/'.$building->id.'/consultants')->assertOk()->json('data');
        $ids = array_column($ids, 'id');
        $this->assertContains($mop->id, $ids);
        foreach ([$client, $other, $inactive] as $excluded) {
            $this->assertNotContains($excluded->id, $ids);
        }
        $this->getJson('/api/admin/new-buildings/'.$building->id.'/consultants?branch_id='.$foreign)->assertForbidden();
    }

    public function test_price_changes_invalidate_all_uncached_public_views_immediately(): void
    {
        Sanctum::actingAs($this->actor('admin'));
        $building = $this->building();
        $unit = $this->unit($building);
        $path = '/api/new-buildings/'.$building->id;
        $this->patchJson($path.'/units/'.$unit->id, ['version' => 1, 'total_price' => '123456.78', 'pricing_basis' => 'total'])->assertOk();
        $this->getJson('/api/new-buildings')->assertOk()->assertJsonPath('data.0.min_total_price', '123456.78');
        $this->getJson($path)->assertOk()->assertJsonPath('data.min_total_price', '123456.78');
        $this->getJson($path.'/units')->assertOk()->assertJsonPath('data.0.total_price', '123456.78');
        $this->patchJson($path.'/units/'.$unit->id, ['version' => 2, 'availability_status' => 'reserved'])->assertOk();
        $this->getJson('/api/new-buildings')->assertOk()->assertJsonPath('data.0.min_total_price', null)->assertJsonPath('data.0.available_count', 0);
        $this->getJson($path.'/units/'.$unit->id)->assertOk()->assertJsonPath('availability_status', 'reserved');
        $this->getJson('/api/new-buildings/plans')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_inventory_audit_is_read_only_and_snapshot_does_not_publish_conflicts(): void
    {
        $building = $this->building(['publication_status' => null]);
        $unit = $this->unit($building, ['publication_status' => null, 'availability_status' => null, 'moderation_status' => 'reserved', 'is_available' => true, 'bedrooms' => 0, 'rooms' => null]);
        $this->artisan('residential:inventory-audit', ['--details' => true])->expectsOutputToContain('legacy_status_conflict')->assertExitCode(0);
        $this->assertDatabaseCount('residential_inventory_snapshots', 0);
        $this->artisan('residential:inventory-audit', ['--snapshot' => true])->assertExitCode(0);
        $this->assertDatabaseCount('residential_inventory_snapshots', 2);
        $this->assertNull($unit->fresh()->publication_status);
        $this->assertNull($unit->fresh()->rooms);
        $this->assertTrue($unit->fresh()->is_available);
        $this->assertSame('reserved', $unit->fresh()->moderation_status);
    }

    public function test_large_detail_has_bounded_legacy_preview_and_private_fields_are_not_public(): void
    {
        $agent = $this->actor('agent');
        $building = $this->building(['responsible_agent_id' => $agent->id, 'created_by' => $agent->id]);
        foreach (range(1, 45) as $_) {
            $this->unit($building);
        }
        $result = $this->getJson('/api/new-buildings/'.$building->id.'?inventory=paginated')->assertOk()->assertJsonCount(20, 'data.units')->assertJsonPath('data.units_has_more', true)->assertJsonPath('data.available_count', 45)->assertJsonPath('data.consultant.name', $agent->name);
        $this->getJson('/api/new-buildings/'.$building->id)->assertOk()->assertJsonCount(45, 'data.units')->assertJsonPath('data.units_has_more', false);
        $this->assertArrayNotHasKey('created_by', $result->json('data'));
        $this->assertArrayNotHasKey('branch_id', $result->json('data.consultant'));
        $this->assertArrayNotHasKey('role_id', $result->json('data.consultant'));
        $this->assertLessThan(200 * 1024, strlen($result->getContent()));
    }
}
