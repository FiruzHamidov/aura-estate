<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Http\Middleware\LogApiRequest;
use App\Models\NewBuilding;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ResidentialSchema;
use Tests\TestCase;

class ResidentialMediaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ResidentialSchema::create();
        $this->withoutMiddleware([EnsureDailyReportSubmitted::class, LogApiRequest::class]);
        Storage::fake('residential');
        Storage::fake('public');
        Http::preventStrayRequests();
    }

    private function actor(string $slug = 'admin'): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);

        return User::create(['name' => $slug, 'phone' => '+992'.random_int(100000000, 999999999), 'role_id' => $role->id]);
    }

    private function building(User $actor): NewBuilding
    {
        return NewBuilding::create(['title' => 'Media fixture', 'created_by' => $actor->id, 'publication_status' => 'published', 'moderation_status' => 'approved']);
    }

    public function test_upload_is_private_reencoded_and_only_authorized_signed_preview_can_open_draft(): void
    {
        $agent = $this->actor('agent');
        $building = $this->building($agent);
        Sanctum::actingAs($agent);
        $response = $this->postJson('/api/new-buildings/'.$building->id.'/photos', ['file' => UploadedFile::fake()->image('plan.png', 1200, 800), 'kind' => 'masterplan', 'alt' => 'Генплан'])->assertCreated();
        $photo = $building->photos()->firstOrFail();
        $this->assertSame('residential', $photo->storage_disk);
        Storage::disk('residential')->assertExists([$photo->path, $photo->original_path]);
        Storage::disk('public')->assertMissing($photo->path);
        $this->assertSame('pending', $building->refresh()->publication_status);
        $this->assertSame(2, $building->version);
        $this->get($response->json('url'))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $publicUrl = route('residential.media', ['kind' => 'building-photos', 'record' => $photo->id, 'variant' => 'preview']);
        $this->get($publicUrl)->assertNotFound();
        $this->get(str_replace('viewer='.$agent->id, 'viewer=9999', $response->json('url')))->assertForbidden();
        $agent->update(['status' => 'inactive']);
        $this->get($response->json('url'))->assertForbidden();
        $this->assertDatabaseHas('crm_audit_logs', ['event' => 'residential.media.created']);
    }

    public function test_invalid_files_paths_and_oversized_images_are_rejected_without_writes(): void
    {
        $admin = $this->actor();
        $building = $this->building($admin);
        Sanctum::actingAs($admin);
        $url = '/api/new-buildings/'.$building->id.'/photos';
        $this->postJson($url, ['path' => '../private/file'])->assertUnprocessable();
        $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('bad.svg', '<svg onload="alert(1)"/>')])->assertUnprocessable();
        $this->postJson($url, ['file' => UploadedFile::fake()->create('big.jpg', 11000, 'image/jpeg')])->assertUnprocessable();
        $this->postJson($url, ['file' => UploadedFile::fake()->image('wide.png', 8001, 2)])->assertUnprocessable();
        $this->assertSame([], Storage::disk('residential')->allFiles());
        $this->assertSame(0, $building->photos()->count());
    }

    public function test_unit_cover_reorder_and_cross_parent_protection_use_unit_id_and_are_atomic(): void
    {
        $admin = $this->actor();
        $building = $this->building($admin);
        Sanctum::actingAs($admin);
        $unit = $building->units()->create(['name' => 'Unit', 'area' => 60, 'rooms' => 2, 'publication_status' => 'published', 'availability_status' => 'available']);
        $url = '/api/new-buildings/'.$building->id.'/units/'.$unit->id.'/photos';
        $this->postJson($url, ['photo' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.png')], 'kind' => 'plan'])->assertCreated()->assertJsonCount(2);
        [$one, $two] = $unit->photos()->orderBy('id')->get()->all();
        $this->postJson($url.'/'.$two->id.'/cover', ['version' => 1])->assertOk();
        $this->assertTrue($two->refresh()->is_cover);
        $this->assertFalse($one->refresh()->is_cover);
        $this->postJson($url.'/'.$two->id.'/cover', ['version' => 1])->assertConflict();
        $this->putJson($url.'/reorder', ['photo_order' => [$two->id, $one->id]])->assertOk();
        $this->assertSame(0, $two->refresh()->sort_order);
        $other = $building->units()->create(['name' => 'Other', 'area' => 70]);
        $foreign = $other->photos()->create(['path' => 'other.jpg']);
        $oldOrder = $one->refresh()->sort_order;
        $this->putJson($url.'/reorder', ['orders' => [['id' => $one->id, 'sort_order' => 19], ['id' => $foreign->id, 'sort_order' => 20]]])->assertNotFound();
        $this->assertSame($oldOrder, $one->refresh()->sort_order);
        $this->deleteJson($url.'/'.$foreign->id)->assertNotFound();
        $this->assertDatabaseHas('developer_unit_photos', ['id' => $foreign->id]);
        $public = $this->getJson('/api/new-buildings/'.$building->id.'/units/'.$unit->id)->assertOk()->json('photos.0.url');
        $this->get($public)->assertOk();
        $unit->update(['publication_status' => 'draft']);
        $this->get($public)->assertNotFound();
    }

    public function test_photo_delete_revokes_url_and_keeps_original_recoverable(): void
    {
        $admin = $this->actor();
        $building = $this->building($admin);
        Sanctum::actingAs($admin);
        $url = '/api/new-buildings/'.$building->id.'/photos';
        $response = $this->postJson($url, ['file' => UploadedFile::fake()->image('one.jpg')])->assertCreated();
        $photo = $building->photos()->firstOrFail();
        $this->deleteJson($url.'/'.$photo->id, ['version' => 1])->assertNoContent();
        $this->get($response->json('url'))->assertNotFound();
        Storage::disk('residential')->assertExists($photo->original_path);
        $this->assertDatabaseHas('crm_audit_logs', ['event' => 'residential.media.delete']);
    }

    public function test_floor_plans_validate_scope_overlap_and_only_expose_published_units_on_this_floor(): void
    {
        $admin = $this->actor();
        $building = $this->building($admin);
        Sanctum::actingAs($admin);
        $block = $building->blocks()->create(['name' => 'A']);
        $entrance = $building->entrances()->create(['block_id' => $block->id, 'name' => '1', 'residential_floor_from' => 1, 'residential_floor_to' => 10]);
        $values = ['block_id' => $block->id, 'entrance_id' => $entrance->id, 'name' => 'Unit', 'area' => 60, 'rooms' => 2, 'publication_status' => 'published', 'availability_status' => 'available', 'floor' => 2];
        $unit = $building->units()->create([...$values, 'number' => 'A-102']);
        $hidden = $building->units()->create([...$values, 'publication_status' => 'draft', 'name' => 'Private draft name']);
        $upper = $building->units()->create([...$values, 'floor' => 3]);
        $points = [[10, 10], [40, 10], [40, 40], [10, 40]];
        $data = ['block_id' => $block->id, 'entrance_id' => $entrance->id, 'floor_from' => 2, 'floor_to' => 3,
            'unit_regions' => array_map(fn ($id) => ['unit_id' => $id, 'points' => $points], [$unit->id, $hidden->id, $upper->id])];
        $url = '/api/admin/new-buildings/'.$building->id.'/floor-plans';
        $plan = $this->postJson($url, $data)->assertCreated()->json();
        $this->postJson($url, $data)->assertUnprocessable();
        $this->postJson($url, [...$data, 'floor_from' => 9, 'floor_to' => 11])->assertUnprocessable();
        $this->patchJson($url.'/'.$plan['id'], [...$data, 'version' => 1, 'unit_regions' => [['unit_id' => 9999, 'points' => $points]]])->assertUnprocessable();
        $this->postJson($url.'/'.$plan['id'].'/image', ['version' => 1, 'file' => UploadedFile::fake()->image('floor.png', 900, 600)])->assertOk()->assertJsonPath('version', 2);
        $public = '/api/new-buildings/'.$building->id.'/units/'.$unit->id.'/floor-plan';
        $response = $this->getJson($public)->assertOk()->assertJsonCount(1, 'data.unit_regions')->assertJsonPath('data.unit_regions.0.unit_id', $unit->id);
        $response->assertJsonPath('data.unit_regions.0.number', 'A-102')->assertJsonPath('data.unit_regions.0.name', 'Unit');
        $this->assertStringNotContainsString('Private draft name', $response->getContent());
        $this->get($response->json('data.image_url'))->assertOk();
        $this->getJson('/api/new-buildings/'.$building->id.'/units/'.$hidden->id.'/floor-plan')->assertNotFound();
        $other = $this->building($admin);
        $this->getJson('/api/new-buildings/'.$other->id.'/units/'.$unit->id.'/floor-plan')->assertNotFound();
        $this->postJson($url.'/'.$plan['id'].'/image', ['version' => 1, 'file' => UploadedFile::fake()->image('stale.png')])->assertConflict();
        $this->postJson($url.'/'.$plan['id'].'/image', ['version' => 2, 'file' => UploadedFile::fake()->image('new.png')])->assertOk()->assertJsonPath('unit_regions', []);
    }

    public function test_masterplan_regions_reject_foreign_blocks_and_svg_payloads_and_hide_archived_blocks(): void
    {
        $admin = $this->actor();
        $building = $this->building($admin);
        Sanctum::actingAs($admin);
        $block = $building->blocks()->create(['name' => 'A']);
        $photo = $this->postJson('/api/new-buildings/'.$building->id.'/photos', ['file' => UploadedFile::fake()->image('masterplan.png'), 'kind' => 'masterplan'])->assertCreated()->json();
        $url = '/api/admin/new-buildings/'.$building->id.'/masterplan/'.$photo['id'].'/regions';
        $region = ['block_id' => $block->id, 'points' => [[0, 0], [100, 0], [100, 100]]];
        $this->patchJson($url, ['version' => 1, 'block_regions' => [[...$region, 'block_id' => 99999]]])->assertUnprocessable();
        $this->patchJson($url, ['version' => 1, 'block_regions' => [[...$region, 'svg' => '<svg/>']]])->assertUnprocessable();
        $this->patchJson($url, ['version' => 1, 'block_regions' => [[...$region, 'points' => [[0, 0], [0, 0], [0, 0]]]]])->assertUnprocessable();
        $this->patchJson($url, ['version' => 1, 'block_regions' => [$region]])->assertOk()->assertJsonPath('version', 2);
        $this->getJson('/api/new-buildings/'.$building->id.'/masterplan')->assertOk()->assertJsonCount(1, 'data.0.block_regions');
        $block->update(['archived_at' => now()]);
        $this->getJson('/api/new-buildings/'.$building->id.'/masterplan')->assertOk()->assertJsonCount(0, 'data.0.block_regions');
    }

    public function test_client_cannot_upload_or_get_signed_admin_media_list(): void
    {
        $admin = $this->actor();
        $building = $this->building($admin);
        Sanctum::actingAs($this->actor('client'));
        $this->postJson('/api/new-buildings/'.$building->id.'/photos', ['file' => UploadedFile::fake()->image('one.jpg')])->assertForbidden();
        $this->getJson('/api/admin/new-buildings/'.$building->id.'/photos')->assertForbidden();
    }

    public function test_masterplan_exposes_block_completion_and_only_its_public_available_inventory(): void
    {
        $admin = $this->actor();
        $building = $this->building($admin);
        $block = $building->blocks()->create(['name' => 'A', 'completion_precision' => 'quarter', 'completion_year' => 2028, 'completion_quarter' => 3]);
        $empty = $building->blocks()->create(['name' => 'B', 'completion_precision' => 'date', 'completion_at' => '2029-02-14']);
        $unknown = $building->blocks()->create(['name' => 'C']);
        $archived = $building->blocks()->create(['name' => 'Hidden', 'archived_at' => now()]);
        foreach ([['published', 'available'], ['published', 'reserved'], ['published', 'sold'], ['published', 'withdrawn'], ['draft', 'available']] as [$publication, $availability]) {
            $building->units()->create(['block_id' => $block->id, 'name' => 'Lot', 'area' => 40, 'publication_status' => $publication, 'availability_status' => $availability]);
        }
        $building->units()->create(['block_id' => $block->id, 'name' => 'Legacy lot', 'area' => 40, 'moderation_status' => 'approved', 'is_available' => true]);
        $this->building($admin)->units()->create(['block_id' => $block->id, 'name' => 'Inconsistent foreign legacy lot', 'area' => 40, 'publication_status' => 'published', 'availability_status' => 'available']);
        $building->units()->create(['block_id' => $archived->id, 'name' => 'Archived block lot', 'area' => 40, 'publication_status' => 'published', 'availability_status' => 'available']);
        $response = $this->getJson('/api/new-buildings/'.$building->id.'/masterplan')->assertOk()->assertJsonCount(3, 'blocks');
        $response->assertJsonPath('blocks.0.id', $block->id)->assertJsonPath('blocks.0.available_count', 2)
            ->assertJsonPath('blocks.0.completion_precision', 'quarter')->assertJsonPath('blocks.0.completion_year', 2028)->assertJsonPath('blocks.0.completion_quarter', 3)
            ->assertJsonPath('blocks.1.id', $empty->id)->assertJsonPath('blocks.1.available_count', 0)->assertJsonPath('blocks.1.completion_at', '2029-02-14')
            ->assertJsonPath('blocks.2.id', $unknown->id)->assertJsonPath('blocks.2.available_count', 0)->assertJsonPath('blocks.2.completion_at', null);
        $this->assertArrayNotHasKey('units', $response->json('blocks.0'));
        $building->update(['publication_status' => 'draft']);
        $this->getJson('/api/new-buildings/'.$building->id.'/masterplan')->assertNotFound();
    }

    public function test_responsive_variants_use_actual_dimensions_and_keep_live_public_access_checks(): void
    {
        $admin = $this->actor();
        $building = $this->building($admin);
        Sanctum::actingAs($admin);
        $this->postJson('/api/new-buildings/'.$building->id.'/photos', ['file' => UploadedFile::fake()->image('portrait.jpg', 800, 1600)])->assertCreated();
        $photo = $building->photos()->firstOrFail();
        $sources = $this->getJson('/api/new-buildings/'.$building->id.'?inventory=paginated')->assertOk()->json('data.cover_sources');
        $this->assertSame([160, 320, 480, 800], array_column($sources, 'width'));
        foreach ($sources as $source) {
            $this->get($source['url'])->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'no-store, private');
            $this->assertArrayNotHasKey('path', $source);
        }
        $stored = $photo->variants;
        $pixels = getimagesize(Storage::disk('residential')->path($stored['w320']['path']));
        $this->assertSame([160, 320], [$pixels[0], $pixels[1]]);
        $building->update(['publication_status' => 'draft']);
        foreach ($sources as $source) {
            $this->get($source['url'])->assertNotFound();
        }
        app(\App\Services\Residential\MediaAssets::class)->discard(array_replace($photo->getAttributes(), ['variants' => $stored]));
        $this->assertSame([], Storage::disk('residential')->allFiles());
    }
}
