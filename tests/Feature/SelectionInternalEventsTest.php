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
        return Selection::create(['property_ids' => [1, 2], 'selection_hash' => 'qa-hash', 'selection_url' => 'https://aura.tj/s/qa-hash', 'title' => 'QA selection', ...$overrides]);
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
