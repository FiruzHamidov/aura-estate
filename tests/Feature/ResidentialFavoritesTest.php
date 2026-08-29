<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Http\Middleware\LogApiRequest;
use App\Models\Favorite;
use App\Models\NewBuilding;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ResidentialSchema;
use Tests\TestCase;

class ResidentialFavoritesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ResidentialSchema::create();
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('moderation_status')->default('approved');
            $table->timestamps();
        });
        (require database_path('migrations/2025_06_24_151549_create_favorites_table.php'))->up();
        (require database_path('migrations/2026_08_28_150000_expand_favorites_for_residential_objects.php'))->up();
        $this->withoutMiddleware([EnsureDailyReportSubmitted::class, LogApiRequest::class]);
        Http::preventStrayRequests();
    }

    private function user(): User
    {
        $role = Role::firstOrCreate(['slug' => 'client'], ['name' => 'Клиент']);

        return User::create(['name' => 'Client', 'role_id' => $role->id, 'phone' => '+992'.random_int(100000000, 999999999)]);
    }

    private function building(): NewBuilding
    {
        return NewBuilding::create(['title' => 'Public fixture', 'publication_status' => 'published']);
    }

    public function test_same_id_for_three_types_does_not_collide_and_legacy_property_row_is_reused(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $building = $this->building();
        $unit = $building->units()->create(['name' => 'A1', 'area' => 50, 'rooms' => 2, 'publication_status' => 'published', 'availability_status' => 'available', 'total_price' => 500000]);
        DB::table('properties')->insert(['id' => $unit->id, 'moderation_status' => \App\Models\Property::PUBLIC_MODERATION_STATUS]);
        $legacy = Favorite::create(['user_id' => $user->id, 'property_id' => $unit->id]);
        foreach (['new_building' => $building->id, 'developer_unit' => $unit->id, 'property' => $unit->id] as $type => $id) {
            $this->putJson('/api/favorites/items/'.$type.'/'.$id)->assertCreated();
            $this->putJson('/api/favorites/items/'.$type.'/'.$id)->assertCreated();
        }
        $this->assertDatabaseCount('favorites', 3);
        $this->assertDatabaseHas('favorites', ['id' => $legacy->id, 'property_id' => $unit->id]);
        $this->getJson('/api/favorites/keys')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/favorites/items?type=new_building')->assertOk()->assertJsonPath('data.0.building.available_count', 1)->assertJsonPath('data.0.building.min_total_price', '500000.00');
        $this->deleteJson('/api/favorites/items/developer_unit/'.$unit->id)->assertNoContent();
        $this->deleteJson('/api/favorites/items/developer_unit/'.$unit->id)->assertNoContent();
        $this->assertDatabaseCount('favorites', 2);
    }

    public function test_guest_resolution_never_exposes_drafts_and_merge_is_idempotent_with_skip_receipt(): void
    {
        $building = $this->building();
        $draft = NewBuilding::create(['title' => 'Secret fixture', 'publication_status' => 'draft', 'responsible_agent_id' => $this->user()->id]);
        $items = [['type' => 'new_building', 'id' => $building->id], ['type' => 'new_building', 'id' => $building->id], ['type' => 'new_building', 'id' => $draft->id]];
        $this->postJson('/api/favorites/resolve', ['items' => $items])->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.available', false)->assertJsonMissing(['title' => 'Secret fixture']);
        $this->postJson('/api/favorites/resolve', ['items' => [['type' => 'App\\Models\\User', 'id' => 1]]])->assertUnprocessable();
        $this->postJson('/api/favorites/resolve', ['items' => array_fill(0, 201, $items[0])])->assertUnprocessable();
        Sanctum::actingAs($this->user());
        $this->postJson('/api/favorites/merge', ['items' => $items])->assertOk()->assertJsonCount(1, 'merged')->assertJsonCount(1, 'skipped');
        $this->postJson('/api/favorites/merge', ['items' => $items])->assertOk();
        $this->assertDatabaseCount('favorites', 1);
        $this->putJson('/api/favorites/items/new_building/'.$draft->id)->assertNotFound();
        $building->update(['publication_status' => 'draft']);
        $this->getJson('/api/favorites/items')->assertOk()->assertJsonPath('data.0.available', false)->assertJsonMissingPath('data.0.building');
        $this->getJson('/api/favorites')->assertOk()->assertExactJson([]);
        $this->assertDatabaseCount('favorites', 1);
    }

    public function test_lists_and_removal_are_owned_by_current_user_and_private_media_are_not_resolved(): void
    {
        $one = $this->user();
        $two = $this->user();
        $building = $this->building();
        Sanctum::actingAs($one);
        $this->putJson('/api/favorites/items/new_building/'.$building->id)->assertCreated();
        Sanctum::actingAs($two);
        $this->getJson('/api/favorites/items?user_id='.$one->id)->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/favorites/keys')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson('/api/favorites/items/new_building/'.$building->id)->assertNoContent();
        $this->assertDatabaseHas('favorites', ['user_id' => $one->id, 'entity_type' => 'new_building', 'entity_id' => $building->id]);
        $unit = $building->units()->create(['name' => 'Private', 'area' => 50, 'publication_status' => 'draft', 'availability_status' => 'available']);
        $this->postJson('/api/favorites/resolve', ['items' => [['type' => 'developer_unit', 'id' => $unit->id]]])->assertOk()->assertJsonMissingPath('data.0.unit')->assertJsonPath('data.0.available', false);
    }

    public function test_comparison_resolves_reserved_and_sold_cards_but_never_private_or_withdrawn_inventory(): void
    {
        $building = $this->building();
        $items = [];
        foreach (['available', 'reserved', 'sold', 'withdrawn'] as $status) {
            $unit = $building->units()->create(['name' => 'QA '.$status, 'area' => 50, 'rooms' => 2,
                'publication_status' => 'published', 'availability_status' => $status, 'total_price' => 500000]);
            $items[] = ['type' => 'developer_unit', 'id' => $unit->id];
        }
        $draft = $building->units()->create(['name' => 'PRIVATE DRAFT', 'area' => 99, 'rooms' => 2,
            'publication_status' => 'draft', 'availability_status' => 'available', 'total_price' => 999999]);
        $items[] = ['type' => 'developer_unit', 'id' => $draft->id];
        $response = $this->postJson('/api/favorites/resolve', ['items' => $items])->assertOk()->assertJsonCount(5, 'data');
        foreach (['available', 'reserved', 'sold'] as $index => $status) {
            $response->assertJsonPath('data.'.$index.'.available', true)
                ->assertJsonPath('data.'.$index.'.unit.availability_status', $status)
                ->assertJsonPath('data.'.$index.'.unit.total_price', '500000.00');
        }
        foreach ([3, 4] as $index) {
            $this->assertSame($items[$index] + ['available' => false], $response->json('data.'.$index));
        }
        $response->assertJsonMissing(['name' => 'PRIVATE DRAFT']);
        $building->update(['publication_status' => 'draft']);
        $this->postJson('/api/favorites/resolve', ['items' => $items])->assertOk()
            ->assertExactJson(['data' => array_map(fn ($item) => $item + ['available' => false], $items)]);
        $this->assertDatabaseCount('favorites', 0);
        Http::assertNothingSent();
    }

    public function test_failed_merge_rolls_back_the_entire_batch_and_retry_preserves_existing_favorites(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $existing = $this->building();
        $first = $this->building();
        $second = $this->building();
        $this->putJson('/api/favorites/items/new_building/'.$existing->id)->assertCreated();
        $original = DB::table('favorites')->first();
        $items = array_map(fn ($building) => ['type' => 'new_building', 'id' => $building->id], [$existing, $first, $second]);

        // Fail the last insert, after the first new favorite has been written.
        // The isolated SQLite fixture makes this a real transaction failure.
        DB::unprepared('CREATE TRIGGER fail_favorite_merge BEFORE INSERT ON favorites WHEN NEW.entity_id = '.(int) $second->id." BEGIN SELECT RAISE(ABORT, 'Synthetic favorite storage failure'); END");
        try {
            $this->postJson('/api/favorites/merge', ['items' => $items])
                ->assertStatus(500)
                ->assertJsonMissingPath('merged')
                ->assertJsonMissingPath('skipped');
            $this->assertDatabaseCount('favorites', 1);
            $this->assertEquals($original, DB::table('favorites')->first());
        } finally {
            DB::unprepared('DROP TRIGGER fail_favorite_merge');
        }

        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/favorites/merge', ['items' => $items])
                ->assertOk()->assertJsonCount(3, 'merged')->assertJsonCount(0, 'skipped');
            $this->assertDatabaseCount('favorites', 3);
            $this->assertEquals($original, DB::table('favorites')->where('id', $original->id)->first());
        }
    }
}
