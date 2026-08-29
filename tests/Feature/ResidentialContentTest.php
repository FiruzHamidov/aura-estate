<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDailyReportSubmitted;
use App\Http\Middleware\LogApiRequest;
use App\Models\NewBuilding;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ResidentialSchema;
use Tests\TestCase;

class ResidentialContentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ResidentialSchema::create();
        (require database_path('migrations/2026_08_28_180000_create_residential_building_content.php'))->up();
        $this->withoutMiddleware([EnsureDailyReportSubmitted::class, LogApiRequest::class]);
        Http::preventStrayRequests();
    }

    private function actor(string $slug = 'admin'): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);

        return User::create(['name' => 'QA '.$slug, 'phone' => '+992'.random_int(100000000, 999999999), 'role_id' => $role->id]);
    }

    private function building(array $values = []): NewBuilding
    {
        return NewBuilding::create(['title' => 'QA ЖК', 'publication_status' => 'published', ...$values]);
    }

    private function poi(array $values = []): array
    {
        return ['name' => 'QA школа', 'type' => 'school', 'latitude' => '0.001', 'longitude' => '0', 'source_url' => 'https://example.com/qa-poi', 'data_verified_at' => now()->toIso8601String(), ...$values];
    }

    public function test_poi_distances_use_real_coordinates_not_client_distance_or_walking_time(): void
    {
        Sanctum::actingAs($this->actor());
        $building = $this->building(['latitude' => 0, 'longitude' => 0]);
        $path = '/api/admin/new-buildings/'.$building->id.'/content/nearby-places';
        $this->postJson($path, $this->poi(['distance_meters' => 999, 'distance_method' => 'walking_minutes']))->assertCreated()->assertJsonPath('distance_meters', 111)->assertJsonPath('distance_method', 'straight_line');
        $this->getJson('/api/new-buildings/'.$building->id.'/content')->assertOk()->assertJsonPath('nearby_places.0.distance_meters', 111)->assertJsonMissingPath('nearby_places.0.version');
        $building->update(['latitude' => null]);
        $this->getJson('/api/new-buildings/'.$building->id.'/content')->assertOk()->assertJsonPath('nearby_places.0.distance_meters', null);
        $this->postJson($path, $this->poi(['latitude' => 91]))->assertUnprocessable();
        $this->postJson($path, $this->poi(['source_url' => null]))->assertUnprocessable();
        $this->postJson($path, $this->poi(['data_verified_at' => now()->addDay()->toIso8601String()]))->assertUnprocessable();
    }

    public function test_video_urls_are_canonical_whitelisted_unique_and_never_fetched_on_server(): void
    {
        Sanctum::actingAs($this->actor());
        $building = $this->building();
        $path = '/api/admin/new-buildings/'.$building->id.'/content/videos';
        $payload = ['title' => 'QA video', 'data_verified_at' => now()->toIso8601String(), 'url' => 'https://youtu.be/abcdefghijk?tracking=discard'];
        $id = $this->postJson($path, $payload)->assertCreated()->assertJsonPath('url', 'https://www.youtube.com/watch?v=abcdefghijk')->assertJsonPath('embed_url', 'https://www.youtube-nocookie.com/embed/abcdefghijk')->json('id');
        $this->postJson($path, $payload)->assertUnprocessable();
        foreach (['javascript:alert(1)', 'https://youtube.com.evil.test/watch?v=abcdefghijk', 'https://youtube.com@evil.test/watch?v=abcdefghijk', 'https://127.0.0.1/video', 'https://vimeo.com/12345/privatekey', 'https://player.vimeo.com/video/12345?h=privatekey', 'https://youtube.com/watch?v[]=abcdefghijk'] as $url) {
            $this->postJson($path, [...$payload, 'url' => $url])->assertUnprocessable();
        }
        $this->patchJson($path.'/'.$id, ['version' => 1, 'url' => 'https://vimeo.com/12345'])->assertOk()->assertJsonPath('embed_url', 'https://player.vimeo.com/video/12345')->assertJsonPath('version', 2);
        Http::assertNothingSent();
    }

    public function test_editing_a_poi_does_not_move_its_verification_date(): void
    {
        Sanctum::actingAs($this->actor());
        $path = '/api/admin/new-buildings/'.$this->building()->id.'/content/nearby-places';
        $date = now()->subHour()->startOfSecond()->utc()->toISOString();
        $created = $this->postJson($path, $this->poi(['data_verified_at' => $date]))->assertCreated();
        $created->assertJsonPath('data_verified_at', $date);
        $this->patchJson($path.'/'.$created->json('id'), ['version' => 1, 'name' => 'Renamed', 'data_verified_at' => $created->json('data_verified_at')])
            ->assertOk()->assertJsonPath('data_verified_at', $date);
    }

    public function test_authors_change_only_assigned_buildings_and_public_content_requires_moderation(): void
    {
        $agent = $this->actor('agent');
        Sanctum::actingAs($agent);
        $building = $this->building(['created_by' => $agent->id]);
        $other = $this->building();
        $path = '/api/admin/new-buildings/'.$building->id.'/content';
        $id = $this->postJson($path.'/nearby-places', $this->poi())->assertCreated()->json('id');
        $this->assertDatabaseHas('new_buildings', ['id' => $building->id, 'publication_status' => 'pending']);
        $this->getJson('/api/new-buildings/'.$building->id.'/content')->assertNotFound();
        $this->getJson($path)->assertOk()->assertJsonPath('nearby_places.0.version', 1);
        $this->postJson('/api/admin/new-buildings/'.$other->id.'/content/nearby-places', $this->poi())->assertForbidden();
        foreach (['client', 'hr', 'accountant'] as $role) {
            Sanctum::actingAs($this->actor($role));
            $this->getJson($path)->assertForbidden();
            $this->deleteJson($path.'/nearby-places/'.$id, ['version' => 1])->assertForbidden();
        }
    }

    public static function poiEditors(): array
    {
        return ['administrator' => ['admin'], 'author agent' => ['agent'], 'author MOP' => ['mop']];
    }

    #[DataProvider('poiEditors')]
    public function test_update_delete_scope_and_versions_preserve_audit_without_partial_change(string $role): void
    {
        $actor = $this->actor($role);
        Sanctum::actingAs($actor);
        $building = $this->building(['created_by' => $actor->id]);
        $other = $this->building(['created_by' => $actor->id]);
        $path = '/api/admin/new-buildings/'.$building->id.'/content/nearby-places';
        $beforeCreate = $building->fresh()->getAttributes();
        $this->postJson($path, $this->poi(['latitude' => 91]))->assertUnprocessable();
        $this->assertSame($beforeCreate, $building->fresh()->getAttributes());
        $this->assertDatabaseCount('crm_audit_logs', 0);
        $this->assertDatabaseCount('new_building_nearby_places', 0);
        $id = $this->postJson($path, $this->poi())->assertCreated()->json('id');
        $date = DB::table('new_building_nearby_places')->where('id', $id)->value('data_verified_at');
        $this->patchJson($path.'/'.$id, ['version' => 1, 'name' => 'QA обновление'])->assertOk()->assertJsonPath('version', 2);
        $record = DB::table('new_building_nearby_places')->where('id', $id)->first();
        $parent = $building->fresh()->getAttributes();
        $auditCount = DB::table('crm_audit_logs')->count();
        $this->assertSame($date, $record->data_verified_at);
        $this->patchJson($path.'/'.$id, ['version' => 1, 'name' => 'Stale'])->assertConflict();
        $this->deleteJson('/api/admin/new-buildings/'.$other->id.'/content/nearby-places/'.$id, ['version' => 2])->assertNotFound();
        $this->deleteJson($path.'/'.$id, ['version' => 1])->assertConflict();
        $this->assertEquals($record, DB::table('new_building_nearby_places')->where('id', $id)->first());
        $this->assertSame($parent, $building->fresh()->getAttributes());
        $this->assertDatabaseCount('crm_audit_logs', $auditCount);
        $this->patchJson($path.'/'.$id, ['version' => 2, 'name' => 'QA fresh retry'])->assertOk()->assertJsonPath('version', 3);
        $this->assertSame($date, DB::table('new_building_nearby_places')->where('id', $id)->value('data_verified_at'));
        $this->deleteJson($path.'/'.$id, ['version' => 3])->assertNoContent();
        $this->assertDatabaseHas('crm_audit_logs', ['event' => 'residential.content.deleted']);
        $this->assertDatabaseCount('new_building_nearby_places', 0);
        $this->assertSame($parent['version'] + 2, $building->fresh()->version);
        Http::assertNothingSent();
    }

    public function test_video_author_moderation_date_and_versioned_deletion(): void
    {
        $author = $this->actor('mop');
        $building = $this->building(['created_by' => $author->id]);
        $other = $this->building();
        $path = '/api/admin/new-buildings/'.$building->id.'/content/videos';
        $date = now()->subHour()->startOfSecond()->utc()->toISOString();

        Sanctum::actingAs($author);
        $created = $this->postJson($path, [
            'title' => 'QA video',
            'url' => 'https://youtu.be/abcdefghijk',
            'data_verified_at' => $date,
        ])->assertCreated()->assertJsonPath('version', 1)->assertJsonPath('data_verified_at', $date);
        $id = $created->json('id');
        $this->assertDatabaseHas('new_buildings', ['id' => $building->id, 'publication_status' => 'pending']);
        $this->getJson('/api/new-buildings/'.$building->id.'/content')->assertNotFound();

        Sanctum::actingAs($this->actor());
        $this->patchJson($path.'/'.$id, ['version' => 1, 'title' => 'QA renamed', 'data_verified_at' => $date])
            ->assertOk()->assertJsonPath('version', 2)->assertJsonPath('data_verified_at', $date);
        $parentVersion = $building->fresh()->version;
        $auditCount = DB::table('crm_audit_logs')->count();
        $this->patchJson($path.'/'.$id, ['version' => 1, 'title' => 'Stale'])->assertConflict();
        $this->deleteJson('/api/admin/new-buildings/'.$other->id.'/content/videos/'.$id, ['version' => 2])->assertNotFound();
        $this->deleteJson($path.'/'.$id, ['version' => 1])->assertConflict();

        foreach (['client', 'hr', 'accountant'] as $role) {
            Sanctum::actingAs($this->actor($role));
            $this->deleteJson($path.'/'.$id, ['version' => 2])->assertForbidden();
        }
        $this->assertDatabaseHas('new_building_videos', ['id' => $id, 'title' => 'QA renamed', 'version' => 2]);
        $this->assertSame($parentVersion, $building->fresh()->version);
        $this->assertDatabaseCount('crm_audit_logs', $auditCount);

        Sanctum::actingAs($this->actor());
        $this->deleteJson($path.'/'.$id, ['version' => 2])->assertNoContent();
        $this->assertDatabaseCount('new_building_videos', 0);
        $this->assertDatabaseHas('crm_audit_logs', ['event' => 'residential.content.deleted']);
        $this->assertSame($parentVersion + 1, $building->fresh()->version);
        Http::assertNothingSent();
    }
}
