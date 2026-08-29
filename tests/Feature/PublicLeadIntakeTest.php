<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Services\Crm\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicLeadIntakeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('sqlite', config('database.default'));
        config(['audit.api_requests.enabled' => false, 'cache.default' => 'array']);
        Schema::dropAllTables();
        foreach (['branches', 'clients', 'roles'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
            });
        }
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default('active');
            $table->unsignedBigInteger('branch_id')->nullable();
        });
        (require database_path('migrations/2026_03_07_120000_create_leads_table.php'))->up();
        (require database_path('migrations/2026_03_07_120100_create_crm_audit_logs_table.php'))->up();
        (require database_path('migrations/2026_08_28_120000_create_lead_intakes_table.php'))->up();
        Schema::create('new_buildings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('moderation_status');
            $table->string('publication_status')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
        });
        Schema::create('developer_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('new_building_id');
            $table->unsignedBigInteger('block_id')->nullable();
            $table->string('name');
            $table->string('moderation_status');
            $table->string('publication_status')->nullable();
            $table->string('availability_status')->nullable();
            $table->boolean('is_available')->default(true);
            $table->decimal('total_price', 15, 2)->nullable();
            $table->unsignedInteger('version')->default(1);
        });
        Schema::create('new_building_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
        (require database_path('migrations/2026_08_28_170000_create_payment_programs.php'))->up();
        Http::fake();
        $this->mock(NotificationService::class)->shouldReceive('handlePublicLeadCreated')->byDefault();
    }

    private function prepareChat(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        foreach (['2025_10_01_000001_create_chat_sessions_table.php', '2025_10_01_000002_create_chat_messages_table.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
    }

    private function chatResponse(array $args): array
    {
        return ['output' => [['type' => 'function_call', 'name' => 'create_lead_request', 'call_id' => 'qa-stable-tool-id', 'arguments' => json_encode($args)]]];
    }

    public function test_chat_lead_is_internal_and_tool_replay_reuses_receipt_without_second_model_claim(): void
    {
        $this->prepareChat();
        $this->mock(NotificationService::class)->shouldReceive('handlePublicLeadCreated')->once();
        Http::fake(['*' => Http::response($this->chatResponse(['name' => 'QA chat', 'phone' => '+992000000099']))]);
        $service = app(\App\Services\Chat\ChatService::class);
        $first = $service->reply('QA отправить заявку', null, null);
        $again = $service->reply('QA отправить заявку', $first['session_id'], null);
        $this->assertSame('Заявка принята в Aura.', $first['answer']);
        $this->assertSame($first['answer'], $again['answer']);
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('lead_intakes', 1);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseHas('leads', ['source' => 'aura-chat-assistant']);
        $items = \App\Models\ChatMessage::where('role', 'tool')->get()->pluck('items');
        $this->assertTrue($items[0]['ok']);
        $this->assertSame($items[0]['request_id'], $items[1]['request_id']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => ! str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/v1/responses'));
    }

    public function test_chat_validation_or_database_failure_never_reports_accepted_or_falls_back_externally(): void
    {
        $this->prepareChat();
        Http::fake(['*' => Http::response($this->chatResponse(['name' => 'QA', 'phone' => 'invalid']))]);
        $reply = app(\App\Services\Chat\ChatService::class)->reply('QA request', null, null);
        $this->assertStringContainsString('Заявка не принята', $reply['answer']);
        $this->assertDatabaseCount('leads', 0);
        $this->assertFalse(\App\Models\ChatMessage::where('role', 'tool')->first()->items['ok']);
        $this->mock(\App\Services\Crm\PublicLeadIntake::class)->shouldReceive('accept')->once()->andThrow(new \RuntimeException('QA database unavailable'));
        $reply = app(\App\Services\Chat\ChatService::class)->reply('QA retry', null, null);
        $this->assertStringContainsString('Заявка не принята', $reply['answer']);
        $this->assertDatabaseCount('lead_intakes', 0);
        Http::assertSentCount(2);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Тестовый клиент',
            'phone' => '+992 (900) 123-456',
            'service_type' => 'Жилые комплексы',
            'source' => 'web-new-building-offers',
            'source_url' => 'https://aura.tj/new-buildings/1?phone=private&utm_source=test#units',
            'consent' => true,
            'consent_version' => '2026-08-28',
            'idempotency_key' => (string) Str::uuid(),
        ], $overrides);
    }

    public function test_public_form_creates_only_an_internal_lead_with_consent_and_no_external_request(): void
    {
        $response = $this->postJson('/api/lead-requests', $this->payload())->assertCreated();
        $lead = Lead::findOrFail($response->json('lead_id'));
        $this->assertSame('992900123456', $lead->phone_normalized);
        $this->assertSame('new', $lead->status);
        $this->assertNull($lead->client_id);
        $this->assertTrue($lead->meta['consent']['accepted']);
        $this->assertSame('https://aura.tj/new-buildings/1', $lead->meta['source_url']);
        $this->assertSame($response->json('request_id'), $lead->meta['intake_id']);
        $this->assertDatabaseCount('crm_audit_logs', 1);
        $this->assertDatabaseCount('clients', 0);
        Http::assertNothingSent();
    }

    public function test_optional_comment_is_stored_as_the_internal_lead_note_and_replay_does_not_duplicate_it(): void
    {
        $comment = "Удобно после 18:00.\nИнтересует планировка и вид из окна.";
        $payload = $this->payload(['comment' => $comment]);
        $first = $this->postJson('/api/lead-requests', $payload)->assertCreated();
        $this->assertSame($comment, Lead::findOrFail($first->json('lead_id'))->note);
        $this->postJson('/api/lead-requests', $payload)->assertOk()
            ->assertJsonPath('request_id', $first->json('request_id'))
            ->assertJsonPath('replayed', true);
        $this->postJson('/api/lead-requests', [...$payload, 'comment' => 'Другой вопрос'])->assertConflict();
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('lead_intakes', 1);
        $this->assertSame($comment, Lead::findOrFail($first->json('lead_id'))->note);
        $this->assertStringNotContainsString($comment, json_encode($first->json(), JSON_UNESCAPED_UNICODE));
        Http::assertNothingSent();
    }

    public function test_comment_limit_is_enforced_before_any_write_and_an_omitted_comment_is_optional(): void
    {
        $this->postJson('/api/lead-requests', $this->payload(['comment' => str_repeat('я', 5001)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['comment'], 'details.errors');
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('lead_intakes', 0);
        $boundary = str_repeat('я', 5000);
        $accepted = $this->postJson('/api/lead-requests', $this->payload(['comment' => $boundary]))->assertCreated();
        $this->assertSame($boundary, Lead::findOrFail($accepted->json('lead_id'))->note);
        $optional = $this->postJson('/api/lead-requests', $this->payload())->assertCreated();
        $this->assertNull(Lead::findOrFail($optional->json('lead_id'))->note);
        Http::assertNothingSent();
    }

    public function test_retry_reuses_receipt_and_does_not_notify_twice(): void
    {
        $this->mock(NotificationService::class)->shouldReceive('handlePublicLeadCreated')->once();
        $payload = $this->payload();
        $first = $this->postJson('/api/lead-requests', $payload)->assertCreated();
        $this->postJson('/api/lead-requests', $payload)->assertOk()
            ->assertJsonPath('lead_id', $first->json('lead_id'))
            ->assertJsonPath('request_id', $first->json('request_id'))
            ->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('lead_intakes', 1);
    }

    public function test_key_cannot_be_reused_for_changed_content(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/lead-requests', $payload)->assertCreated();
        $payload['phone'] = '+992900999999';
        $this->postJson('/api/lead-requests', $payload)->assertConflict();
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_new_intent_for_same_phone_creates_a_new_lead(): void
    {
        $this->postJson('/api/lead-requests', $this->payload())->assertCreated();
        $this->postJson('/api/lead-requests', $this->payload())->assertCreated();
        $this->assertDatabaseCount('leads', 2);
    }

    public function test_invalid_phone_or_missing_consent_version_is_rejected_without_receipt(): void
    {
        $this->postJson('/api/lead-requests', $this->payload(['phone' => 'call me 123']))->assertUnprocessable();
        $this->postJson('/api/lead-requests', $this->payload(['consent_version' => null]))->assertUnprocessable();
        $this->postJson('/api/lead-requests', $this->payload(['consent' => false]))->assertUnprocessable();
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('lead_intakes', 0);
        Http::assertNothingSent();
    }

    public function test_legacy_forms_without_key_or_consent_remain_usable_without_fabricating_consent(): void
    {
        $payload = $this->payload();
        unset($payload['idempotency_key'], $payload['consent'], $payload['consent_version']);
        $response = $this->postJson('/api/lead-requests', $payload)->assertCreated();
        $this->assertFalse(Lead::find($response->json('lead_id'))->meta['consent']['accepted']);
    }

    public function test_audit_failure_rolls_back_lead_and_receipt(): void
    {
        $this->mock(AuditLogger::class)->shouldReceive('log')->andThrow(new \RuntimeException('Test failure'));
        $this->postJson('/api/lead-requests', $this->payload())->assertServerError();
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('lead_intakes', 0);
    }

    public function test_notification_failure_does_not_lose_accepted_lead(): void
    {
        $this->mock(NotificationService::class)->shouldReceive('handlePublicLeadCreated')->andThrow(new \RuntimeException('Test notification failure'));
        $this->postJson('/api/lead-requests', $this->payload())->assertCreated();
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_building_consultant_and_unit_snapshot_are_resolved_on_the_server(): void
    {
        DB::table('branches')->insert(['id' => 3]);
        DB::table('users')->insert(['id' => 7, 'name' => 'Консультант', 'branch_id' => 3]);
        DB::table('new_buildings')->insert(['id' => 1, 'title' => 'Настоящий ЖК', 'moderation_status' => 'approved', 'responsible_agent_id' => 7]);
        DB::table('developer_units')->insert(['id' => 10, 'new_building_id' => 1, 'name' => '78', 'moderation_status' => 'reserved', 'is_available' => false, 'total_price' => 548000]);
        $response = $this->postJson('/api/lead-requests', $this->payload(['context' => [
            'building_id' => 1, 'unit_id' => 10, 'building_name' => 'Подмена',
            'responsible_agent_id' => 999, 'branch_id' => 999, 'total_price' => 1,
        ]]))->assertCreated();
        $lead = Lead::findOrFail($response->json('lead_id'));
        $this->assertSame(7, $lead->responsible_agent_id);
        $this->assertSame(3, $lead->branch_id);
        $this->assertSame('Настоящий ЖК', $lead->meta['context']['building_name']);
        $this->assertSame('548000.00', $lead->meta['context']['total_price']);
        $this->assertSame('reserved', DB::table('developer_units')->value('moderation_status'));
    }

    public function test_empty_published_complex_accepts_consultation_without_inventing_a_consultant_or_lot(): void
    {
        $this->mock(NotificationService::class)->shouldReceive('handlePublicLeadCreated')->twice();
        foreach ([null, 'published'] as $index => $publicationStatus) {
            $buildingId = $index + 1;
            DB::table('new_buildings')->insert([
                'id' => $buildingId, 'title' => 'QA ЖК без квартир',
                'moderation_status' => 'approved', 'publication_status' => $publicationStatus,
            ]);
            $before = (array) DB::table('new_buildings')->find($buildingId);
            $payload = $this->payload([
                'intent' => 'consultation', 'comment' => 'Когда появятся квартиры?',
                'context' => ['building_id' => $buildingId, 'responsible_agent_id' => 999, 'total_price' => 1],
            ]);
            $first = $this->postJson('/api/lead-requests', $payload)->assertCreated();
            $lead = Lead::findOrFail($first->json('lead_id'));
            $this->assertSame('new', $lead->status);
            $this->assertNull($lead->responsible_agent_id);
            $this->assertNull($lead->branch_id);
            $this->assertNull($lead->client_id);
            $this->assertSame('Когда появятся квартиры?', $lead->note);
            $this->assertSame('consultation', $lead->meta['intent']);
            $this->assertSame([
                'building_id' => $buildingId, 'building_name' => 'QA ЖК без квартир',
            ], $lead->meta['context']);
            $this->postJson('/api/lead-requests', $payload)->assertOk()
                ->assertJsonPath('lead_id', $lead->id)
                ->assertJsonPath('request_id', $first->json('request_id'))
                ->assertJsonPath('replayed', true);
            $this->assertSame($before, (array) DB::table('new_buildings')->find($buildingId));
        }
        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseCount('lead_intakes', 2);
        $this->assertDatabaseCount('crm_audit_logs', 2);
        $this->assertDatabaseCount('developer_units', 0);
        $this->assertDatabaseCount('clients', 0);
        Http::assertNothingSent();
    }

    public function test_all_residential_intents_preserve_filter_context_and_receipt_replay_without_changing_inventory(): void
    {
        DB::table('new_buildings')->insert(['id' => 1, 'title' => 'QA ЖК', 'moderation_status' => 'approved']);
        DB::table('developer_units')->insert(['id' => 10, 'new_building_id' => 1, 'name' => '42', 'moderation_status' => 'available', 'version' => 3, 'total_price' => 510000]);
        $before = (array) DB::table('developer_units')->find(10);
        $filters = ['rooms' => ['studio', '5'], 'price_max' => '560000', 'area_min' => '40,5', 'include_reserved' => '1', 'window_view' => ['park', 'courtyard']];
        $savedFilters = [...$filters, 'area_min' => '40.5'];
        foreach (['consultation', 'viewing', 'availability', 'availability_notification', 'similar_selection', 'payment_consultation'] as $intent) {
            $payload = $this->payload(['intent' => $intent, 'source_url' => 'https://aura.tj/new-buildings/1/units/10?phone=private', 'context' => [
                'building_id' => 1, 'unit_id' => 10, 'expected_version' => 3, 'filters' => $filters, 'total_price' => 1,
            ]]);
            $first = $this->postJson('/api/lead-requests', $payload)->assertCreated();
            $lead = Lead::findOrFail($first->json('lead_id'));
            $this->assertEquals($savedFilters, $lead->meta['context']['filters']);
            $this->assertSame($intent, $lead->meta['intent']);
            $this->assertSame('510000.00', $lead->meta['context']['total_price']);
            $this->assertSame('https://aura.tj/new-buildings/1/units/10', $lead->meta['source_url']);
            $this->postJson('/api/lead-requests', $payload)->assertOk()->assertJsonPath('request_id', $first->json('request_id'));
            $changed = $payload;
            $changed['context']['filters']['price_max'] = '600000';
            $this->postJson('/api/lead-requests', $changed)->assertConflict();
            $this->assertEquals($savedFilters, $lead->fresh()->meta['context']['filters']);
        }
        $this->assertSame($before, (array) DB::table('developer_units')->find(10));
        $this->assertDatabaseCount('leads', 6);
        $this->assertDatabaseCount('lead_intakes', 6);
        $this->assertDatabaseCount('clients', 0);
        Http::assertNothingSent();
    }

    public function test_reserved_unit_notifications_and_similar_requests_replay_without_changing_reservations(): void
    {
        DB::table('new_buildings')->insert(['id' => 1, 'title' => 'QA reserved complex', 'moderation_status' => 'approved']);
        $this->mock(NotificationService::class)->shouldReceive('handlePublicLeadCreated')->times(4);
        foreach ([1 => ['publication_status' => 'published', 'availability_status' => 'reserved'], 2 => []] as $unitId => $canonical) {
            DB::table('developer_units')->insert([
                'id' => $unitId, 'new_building_id' => 1, 'name' => 'QA reserved unit',
                'moderation_status' => 'reserved', 'is_available' => false,
                'version' => 5, 'total_price' => '610000.00', ...$canonical,
            ]);
            $before = (array) DB::table('developer_units')->find($unitId);
            foreach (['availability_notification', 'similar_selection'] as $intent) {
                $payload = $this->payload(['intent' => $intent, 'context' => [
                    'building_id' => 1, 'unit_id' => $unitId, 'expected_version' => 5,
                    'status' => 'available', 'total_price' => 1,
                ]]);
                $accepted = $this->postJson('/api/lead-requests', $payload)->assertCreated();
                $lead = Lead::findOrFail($accepted->json('lead_id'));
                $this->assertSame('reserved', $lead->meta['context']['status']);
                $this->assertSame('610000.00', $lead->meta['context']['total_price']);
                $this->assertSame($intent, $lead->meta['intent']);
                $this->postJson('/api/lead-requests', $payload)->assertOk()->assertExactJson([
                    'message' => 'Заявка принята в Aura.', 'request_id' => $accepted->json('request_id'),
                    'lead_id' => $lead->id, 'replayed' => true,
                ]);
                $this->assertSame($before, (array) DB::table('developer_units')->find($unitId));
            }
        }
        $this->assertDatabaseCount('leads', 4);
        $this->assertDatabaseCount('lead_intakes', 4);
        $this->assertDatabaseCount('clients', 0);
        Http::assertNothingSent();
    }

    public function test_filter_validation_uses_inventory_rules_before_writing_and_drops_unrelated_keys(): void
    {
        foreach ([
            ['filters' => 'invalid', 'field' => 'context.filters'],
            ['filters' => ['rooms' => ['21']], 'field' => 'context.filters.rooms.0'],
            ['filters' => ['area_min' => '70', 'area_max' => '40'], 'field' => 'context.filters.area_max'],
            ['filters' => ['only_last_floor' => '1', 'exclude_last_floor' => '1'], 'field' => 'context.filters.only_last_floor'],
        ] as $case) {
            $this->postJson('/api/lead-requests', $this->payload(['context' => ['filters' => $case['filters']]]))
                ->assertUnprocessable()->assertJsonValidationErrors([$case['field']], 'details.errors');
        }
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('lead_intakes', 0);
        $accepted = $this->postJson('/api/lead-requests', $this->payload(['context' => ['filters' => [
            'price_max' => '560000', 'responsible_agent_id' => 999, 'phone' => 'private',
            'total_price' => 1, 'sort' => 'newest', 'page' => 3,
        ]]]))->assertCreated();
        $lead = Lead::findOrFail($accepted->json('lead_id'));
        $this->assertSame(['price_max' => '560000'], $lead->meta['context']['filters']);
        $this->assertNull($lead->responsible_agent_id);
        Http::assertNothingSent();
    }

    public function test_draft_cross_building_and_stale_unit_context_cannot_be_submitted(): void
    {
        DB::table('new_buildings')->insert([
            ['id' => 1, 'title' => 'ЖК', 'moderation_status' => 'approved'],
            ['id' => 2, 'title' => 'Черновик', 'moderation_status' => 'draft'],
        ]);
        DB::table('developer_units')->insert(['id' => 10, 'new_building_id' => 1, 'name' => '78', 'moderation_status' => 'available', 'version' => 3]);
        $this->postJson('/api/lead-requests', $this->payload(['context' => ['building_id' => 2]]))->assertNotFound();
        $this->postJson('/api/lead-requests', $this->payload(['context' => ['building_id' => 1, 'unit_id' => 999]]))->assertNotFound();
        $this->postJson('/api/lead-requests', $this->payload(['context' => ['building_id' => 1, 'unit_id' => 10, 'expected_version' => 2]]))->assertConflict();
        $this->assertDatabaseCount('lead_intakes', 0);
    }

    public function test_retired_external_authentication_endpoint_is_gone(): void
    {
        $this->postJson('/api/b24/token', ['domain' => 'example.test'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_payment_program_snapshot_is_authoritative_and_retry_retains_accepted_terms(): void
    {
        DB::table('users')->insert(['id' => 7, 'name' => 'Модератор']);
        $program = \App\Models\PaymentProgram::create([
            'title' => 'Тестовая ипотека', 'type' => 'mortgage', 'scope' => 'all', 'annual_rate' => '12.500',
            'publication_status' => 'published', 'verified_by' => 7, 'data_verified_at' => now(),
            'valid_from' => now()->subDay()->toDateString(), 'valid_until' => now()->addDay()->toDateString(),
        ]);
        $payload = $this->payload(['intent' => 'payment_consultation', 'context' => ['payment_program_id' => $program->id, 'expected_program_version' => 1, 'payment_program' => ['title' => 'Подмена', 'annual_rate' => 0]]]);
        $first = $this->postJson('/api/lead-requests', $payload)->assertCreated();
        $snapshot = Lead::findOrFail($first->json('lead_id'))->meta['context']['payment_program'];
        $this->assertSame('Тестовая ипотека', $snapshot['title']);
        $this->assertSame('12.500', $snapshot['annual_rate']);
        $this->assertSame(1, $snapshot['version']);
        $program->update(['version' => 2, 'annual_rate' => 15]);
        $this->postJson('/api/lead-requests', $payload)->assertOk()->assertJsonPath('lead_id', $first->json('lead_id'));
        $payload['idempotency_key'] = (string) Str::uuid();
        $this->postJson('/api/lead-requests', $payload)->assertConflict();
        $payload['context']['expected_program_version'] = 2;
        $program->update(['publication_status' => 'draft']);
        $this->postJson('/api/lead-requests', $payload)->assertNotFound();
        unset($payload['context']['expected_program_version']);
        $this->postJson('/api/lead-requests', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('lead_intakes', 1);
        Http::assertNothingSent();
    }
}
