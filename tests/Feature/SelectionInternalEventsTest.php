<?php

namespace Tests\Feature;

use App\Models\Selection;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Http;
use Tests\Support\ResidentialSchema;
use Tests\TestCase;

class SelectionInternalEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ResidentialSchema::create();
        (require database_path('migrations/2025_10_07_051637_create_selections.php'))->up();
        $this->withoutMiddleware([\App\Http\Middleware\LogApiRequest::class, \Illuminate\Routing\Middleware\ThrottleRequests::class]);
        Http::preventStrayRequests();
        $this->mock(NotificationService::class)->shouldReceive('handleSelectionEvent')->byDefault();
    }

    private function selection(array $overrides = []): Selection
    {
        return Selection::create(['property_ids' => [1, 2], 'selection_hash' => 'qa-hash', 'selection_url' => 'https://aura.tj/s/'.($overrides['selection_hash'] ?? 'qa-hash'), 'title' => 'QA selection', ...$overrides]);
    }

    public function test_internal_read_grants_override_job_labels_and_preserve_ownership(): void
    {
        \Illuminate\Support\Facades\Schema::table('roles', fn ($t) => $t->json('permissions')->nullable());
        foreach (['client', 'external_agent', 'accountant', 'custom_selection', 'admin'] as $index => $slug) {
            $role = \App\Models\Role::create(['name' => $slug, 'slug' => $slug]);
            $role->forceFill(['permissions' => []])->save();
            $actor = \App\Models\User::create(['name' => $slug, 'phone' => '90000000'.$index, 'role_id' => $role->id]);
            $own = $this->selection(['created_by' => $actor->id, 'selection_hash' => $slug.'-own']);
            $other = \App\Models\User::create(['name' => 'Other', 'phone' => '91000000'.$index, 'role_id' => $role->id]);
            $foreign = $this->selection(['created_by' => $other->id, 'selection_hash' => $slug.'-foreign']);
            \Laravel\Sanctum\Sanctum::actingAs($actor->fresh());
            $this->getJson('/api/selections')->assertForbidden();
            $this->getJson('/api/selections/'.$own->id)->assertForbidden();
            $role->forceFill(['permissions' => ['selections.view']])->save();
            \Laravel\Sanctum\Sanctum::actingAs($actor->fresh());
            $this->getJson('/api/selections')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
            $this->getJson('/api/selections/'.$own->id)->assertOk();
            $this->getJson('/api/selections/'.$foreign->id)->assertForbidden();
            $this->postJson('/api/selections', [])->assertForbidden();
            $role->forceFill(['permissions' => ['selections.view', 'selections.scope.all']])->save();
            \Laravel\Sanctum\Sanctum::actingAs($actor->fresh());
            $this->getJson('/api/selections/'.$foreign->id)->assertOk();
            $role->forceFill(['permissions' => ['selections.scope.all']])->save();
            \Laravel\Sanctum\Sanctum::actingAs($actor->fresh());
            $this->getJson('/api/selections/'.$own->id)->assertForbidden();
            // Public links remain bearer links, independent of internal role permissions.
            $this->getJson('/api/selections/public/'.$own->selection_hash)->assertOk()->assertJsonMissingPath('created_by');
        }
    }

    public function test_creation_is_independent_and_revocation_prevents_persistence(): void
    {
        \Illuminate\Support\Facades\Schema::table('roles', fn ($t) => $t->json('permissions')->nullable());
        \Illuminate\Support\Facades\Schema::table('selections', fn ($t) => $t->unsignedBigInteger('branch_group_id')->nullable());
        \Illuminate\Support\Facades\Schema::create('properties', function ($t) {
            $t->id(); $t->unsignedBigInteger('agent_id')->nullable(); $t->unsignedBigInteger('branch_group_id')->nullable();
        });
        \Illuminate\Support\Facades\DB::table('properties')->insert(['id' => 1]);
        $role = \App\Models\Role::create(['name' => 'Client', 'slug' => 'client']);
        $role->forceFill(['permissions' => ['selections.create']])->save();
        $actor = \App\Models\User::create(['name' => 'Creator', 'phone' => '900000099', 'role_id' => $role->id]);
        \Laravel\Sanctum\Sanctum::actingAs($actor->fresh());
        $created = $this->postJson('/api/selections', ['property_ids' => [1], 'title' => 'My selection'])->assertCreated();
        $this->assertSame($actor->id, $created->json('selection.created_by'));
        $this->getJson('/api/selections/'.$created->json('selection.id'))->assertForbidden();
        $role->forceFill(['permissions' => []])->save();
        \Laravel\Sanctum\Sanctum::actingAs($actor->fresh());
        $this->postJson('/api/selections', ['property_ids' => [1]])->assertForbidden();
        $this->assertDatabaseCount('selections', 1);
    }

    public function test_events_remain_internal_and_bounded_and_do_not_expose_private_metadata(): void
    {
        $selection = $this->selection(['contact_id' => 99, 'deal_id' => 88, 'meta' => ['internal' => 'private', 'events' => array_fill(0, 100, ['type' => 'opened'])]]);
        $path = '/api/selections/public/qa-hash';
        $this->postJson($path.'/events', ['type' => 'viewed'])->assertOk();
        $this->postJson($path.'/events', ['type' => 'requested_showing', 'payload' => ['property_id' => 1]])->assertOk();
        $this->assertSame('viewed', $selection->fresh()->status);
        $this->assertNotNull($selection->fresh()->viewed_at);
        $this->assertCount(100, $selection->fresh()->meta['events']);
        $this->assertSame('requested_showing', $selection->fresh()->meta['events'][99]['type']);
        $this->getJson($path)->assertOk()->assertJsonMissingPath('contact_id')->assertJsonMissingPath('deal_id')->assertJsonMissingPath('meta');
        Http::assertNothingSent();
    }

    public function test_unrelated_properties_unknown_payload_and_expired_hash_do_not_mutate_selection(): void
    {
        $selection = $this->selection();
        $path = '/api/selections/public/qa-hash';
        $this->postJson($path.'/events', ['type' => 'opened', 'payload' => ['property_id' => 999]])->assertUnprocessable();
        $this->postJson($path.'/events', ['type' => 'requested_showing'])->assertUnprocessable();
        $this->postJson($path.'/events', ['type' => 'viewed', 'payload' => ['phone' => 'private']])->assertUnprocessable();
        $this->postJson('/api/selections/public/unknown/events', ['type' => 'viewed'])->assertNotFound();
        $this->assertNull($selection->fresh()->meta);
        $selection->update(['expires_at' => now()->subSecond()]);
        $this->postJson($path.'/events', ['type' => 'viewed'])->assertStatus(410);
        $this->getJson($path)->assertStatus(410);
        $this->assertNull($selection->fresh()->viewed_at);
        Http::assertNothingSent();
    }
}
