<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePropertyModerationIdempotency;
use App\Models\Property;
use App\Models\PropertyDuplicateCandidate;
use App\Models\PropertyModerationCase;
use App\Models\PropertyPromotion;
use App\Models\Role;
use App\Models\User;
use App\Services\PropertyDuplicateService;
use App\Services\PropertyModeration\PropertyModerationAccess;
use App\Services\PropertyModeration\PropertyModerationService;
use App\Services\PropertyModeration\PropertyPromotionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PropertyModerationWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Property::unsetEventDispatcher();
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id');
            $table->foreignId('branch_id')->nullable();
            $table->foreignId('branch_group_id')->nullable();
            $table->string('status')->default('active');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('type_id')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->decimal('price', 15, 2);
            $table->decimal('discount_price', 15, 2)->nullable();
            $table->string('currency', 3)->default('TJS');
            $table->string('offer_type')->default('sale');
            $table->integer('rooms')->nullable();
            $table->decimal('total_area', 10, 2)->nullable();
            $table->integer('floor')->nullable();
            $table->integer('total_floors')->nullable();
            $table->string('district')->nullable();
            $table->string('address')->nullable();
            $table->string('owner_phone')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('moderation_status')->default('pending');
            $table->string('listing_type')->default('regular');
            $table->timestamps();
        });
        Schema::create('property_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id');
            $table->string('file_path');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        (require database_path('migrations/2026_09_04_100000_add_property_moderation_workflow.php'))->up();
        (require database_path('migrations/2025_06_23_004510_create_notifications_table.php'))->up();
        (require database_path('migrations/2026_04_04_180000_expand_notifications_table.php'))->up();
    }

    public function test_migration_backfills_published_state_and_price_baseline(): void
    {
        [$agent] = $this->users();
        $migration = require database_path('migrations/2026_09_04_100000_add_property_moderation_workflow.php');
        $migration->down();
        $id = DB::table('properties')->insertGetId([
            'price' => 100000, 'currency' => 'TJS', 'created_by' => $agent->id,
            'moderation_status' => 'approved', 'listing_type' => 'vip',
        ]);
        $migration->up();
        $property = Property::findOrFail($id);

        $this->assertSame('published', $property->publication_status);
        $this->assertSame('100000.00', $property->approved_effective_price);
        $this->assertSame('regular', $property->listing_type);
        $this->assertDatabaseHas('property_promotions', [
            'property_id' => $id, 'type' => 'vip', 'status' => 'requested', 'source' => 'system_backfill',
        ]);
    }

    public function test_protected_publication_and_promotion_fields_are_rejected(): void
    {
        [$agent] = $this->users();
        $service = $this->moderation();

        try {
            $service->assertNoProtectedFields(Request::create('/', 'PUT', [
                'moderation_status' => 'approved',
                'listing_type' => 'vip',
            ]), [], $agent);
            $this->fail('Protected fields must be rejected.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(422, $exception->getResponse()->getStatusCode());
            $this->assertSame('PROMOTION_PROTECTED_FIELD', json_decode($exception->getResponse()->getContent(), true)['code']);
            $this->assertDatabaseHas('property_moderation_events', [
                'property_id' => null,
                'event_type' => 'protected_field_attempt',
                'actor_id' => $agent->id,
            ]);
        }
    }

    public function test_price_increase_is_unpublished_until_an_independent_moderator_approves(): void
    {
        [$agent, $rop] = $this->users();
        $service = $this->moderation();
        $property = Property::create($this->propertyPayload($agent, [
            'moderation_status' => 'approved',
            'publication_status' => 'published',
            'approved_price' => 100_000,
            'approved_effective_price' => 100_000,
            'approved_currency' => 'TJS',
            'approved_content_snapshot' => ['price' => 100_000, 'currency' => 'TJS'],
            'moderation_version' => 1,
        ]));
        $promotion = PropertyPromotion::create([
            'property_id' => $property->id,
            'type' => 'vip',
            'status' => PropertyPromotion::STATUS_ACTIVE,
            'requested_by' => $agent->id,
            'requested_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(6),
            'version' => 1,
        ]);

        $property->price = 130_000;
        $outcome = $service->evaluateUpdate($property, $agent);
        $property->save();
        $service->recordUpdateOutcome($property, $agent, $outcome);

        $this->assertSame('pending', $property->fresh()->publication_status);
        $this->assertSame(PropertyPromotion::STATUS_SUSPENDED, $promotion->fresh()->status);
        $case = PropertyModerationCase::where('property_id', $property->id)->where('type', 'price_increase')->firstOrFail();
        $approved = $service->approveCase($case, $rop, 'Цена подтверждена владельцем');
        $this->assertSame('published', $approved->publication_status);
        $this->assertSame('130000.00', $approved->approved_effective_price);
        $this->assertSame(PropertyPromotion::STATUS_SUSPENDED, $promotion->fresh()->status);
        $this->assertSame('regular', $approved->fresh()->listing_type);
    }

    public function test_price_decrease_stays_published_and_becomes_the_new_baseline(): void
    {
        [$agent] = $this->users();
        $service = $this->moderation();
        $property = Property::create($this->propertyPayload($agent, [
            'moderation_status' => 'approved',
            'publication_status' => 'published',
            'approved_price' => 100_000,
            'approved_effective_price' => 100_000,
            'approved_currency' => 'TJS',
            'moderation_version' => 1,
        ]));

        $property->price = 90_000;
        $outcome = $service->evaluateUpdate($property, $agent);
        $property->save();
        $service->recordUpdateOutcome($property, $agent, $outcome);

        $this->assertSame('published', $property->fresh()->publication_status);
        $this->assertSame('90000.00', $property->fresh()->approved_effective_price);
        $this->assertDatabaseCount('property_moderation_cases', 0);
    }

    public function test_repeated_price_increases_keep_one_case_and_the_original_approved_baseline(): void
    {
        [$agent] = $this->users();
        $service = $this->moderation();
        $property = Property::create($this->propertyPayload($agent, [
            'publication_status' => 'published',
            'moderation_status' => 'approved',
            'approved_price' => 100_000,
            'approved_effective_price' => 100_000,
            'approved_currency' => 'TJS',
            'approved_content_snapshot' => ['price' => 100_000, 'currency' => 'TJS'],
            'moderation_version' => 1,
        ]));

        foreach ([100_001, 102_000, 103_000] as $price) {
            $property = $property->fresh();
            $property->price = $price;
            $outcome = $service->evaluateUpdate($property, $agent);
            $property->save();
            $service->recordUpdateOutcome($property, $agent, $outcome);
        }

        $case = PropertyModerationCase::query()->where('property_id', $property->id)->where('type', PropertyModerationCase::TYPE_PRICE_INCREASE)->firstOrFail();
        $this->assertSame('pending', $property->fresh()->publication_status);
        $this->assertSame(1, PropertyModerationCase::query()->where('property_id', $property->id)->where('type', PropertyModerationCase::TYPE_PRICE_INCREASE)->count());
        $this->assertSame(100000.0, (float) $case->baseline_snapshot['effective_price']);
        $this->assertSame(103000.0, (float) $case->proposed_snapshot['effective_price']);
    }

    public function test_currency_change_discount_removal_and_marketing_anchor_increase_require_review(): void
    {
        [$agent] = $this->users();
        $service = $this->moderation();

        $currency = Property::create($this->propertyPayload($agent, [
            'publication_status' => 'published',
            'moderation_status' => 'approved',
            'approved_price' => 100_000,
            'approved_effective_price' => 100_000,
            'approved_currency' => 'TJS',
        ]));
        $currency->currency = 'USD';
        $this->assertNotEmpty($service->evaluateUpdate($currency, $agent)['cases']);

        $discount = Property::create($this->propertyPayload($agent, [
            'price' => 120_000,
            'discount_price' => 90_000,
            'publication_status' => 'published',
            'moderation_status' => 'approved',
            'approved_price' => 120_000,
            'approved_discount_price' => 90_000,
            'approved_effective_price' => 90_000,
            'approved_currency' => 'TJS',
        ]));
        $discount->discount_price = null;
        $this->assertNotEmpty($service->evaluateUpdate($discount, $agent)['cases']);

        $anchor = Property::create($this->propertyPayload($agent, [
            'price' => 100_000,
            'publication_status' => 'published',
            'moderation_status' => 'approved',
            'approved_price' => 100_000,
            'approved_effective_price' => 100_000,
            'approved_currency' => 'TJS',
        ]));
        $anchor->price = 130_000;
        $anchor->discount_price = 90_000;
        $caseData = $service->evaluateUpdate($anchor, $agent)['cases'][0];
        $this->assertContains('display_price_increased', $caseData['reason_codes']);
    }

    public function test_returning_a_pending_price_to_the_approved_baseline_withdraws_the_case(): void
    {
        [$agent] = $this->users();
        $service = $this->moderation();
        $property = Property::create($this->propertyPayload($agent, [
            'moderation_status' => 'approved',
            'publication_status' => 'published',
            'approved_price' => 100_000,
            'approved_effective_price' => 100_000,
            'approved_currency' => 'TJS',
            'approved_content_snapshot' => ['price' => 100_000, 'currency' => 'TJS'],
            'moderation_version' => 1,
        ]));

        $property->price = 130_000;
        $outcome = $service->evaluateUpdate($property, $agent);
        $property->save();
        $service->recordUpdateOutcome($property, $agent, $outcome);

        $property = $property->fresh();
        $property->price = 100_000;
        $outcome = $service->evaluateUpdate($property, $agent);
        $property->save();
        $service->recordUpdateOutcome($property, $agent, $outcome);

        $this->assertSame('published', $property->fresh()->publication_status);
        $this->assertSame('100000.00', $property->fresh()->approved_effective_price);
        $this->assertDatabaseHas('property_moderation_cases', [
            'property_id' => $property->id,
            'type' => PropertyModerationCase::TYPE_PRICE_INCREASE,
            'status' => PropertyModerationCase::STATUS_WITHDRAWN,
        ]);
    }

    public function test_rejected_price_can_restore_the_approved_snapshot(): void
    {
        [$agent, $rop] = $this->users();
        $service = $this->moderation();
        $property = Property::create($this->propertyPayload($agent, [
            'price' => 130_000,
            'moderation_status' => 'pending',
            'publication_status' => 'pending',
            'approved_price' => 100_000,
            'approved_effective_price' => 100_000,
            'approved_currency' => 'TJS',
            'approved_content_snapshot' => ['price' => 100_000, 'currency' => 'TJS'],
            'moderation_version' => 2,
        ]));
        $case = PropertyModerationCase::create([
            'property_id' => $property->id,
            'type' => PropertyModerationCase::TYPE_PRICE_INCREASE,
            'status' => PropertyModerationCase::STATUS_OPEN,
            'blocking' => true,
            'submitted_by' => $agent->id,
            'submitted_at' => now(),
            'version' => 1,
        ]);

        $restored = $service->rejectCase($case, $rop, 'Повышение не подтверждено', 1, 'restore_and_publish');

        $this->assertSame('published', $restored->publication_status);
        $this->assertSame(100000.0, (float) $restored->price);
        $this->assertSame(PropertyModerationCase::STATUS_REJECTED, $case->fresh()->status);
    }

    public function test_vip_requires_a_request_and_cannot_be_self_approved(): void
    {
        [$agent, $rop] = $this->users();
        $moderation = $this->moderation();
        $promotions = new PropertyPromotionService(new PropertyModerationAccess, $moderation);
        $property = Property::create($this->propertyPayload($agent, [
            'moderation_status' => 'approved',
            'publication_status' => 'published',
        ]));

        $request = $promotions->request($property, $agent, 'vip', 'Нужна рекламная позиция', 7, (int) $property->moderation_version);
        $this->assertSame('regular', $property->fresh()->listing_type);
        $approved = $promotions->approve($request, $rop, 7, 'Одобрено', 1);

        $this->assertSame(PropertyPromotion::STATUS_ACTIVE, $approved->status);
        $this->assertSame('vip', $property->fresh()->listing_type);
    }

    public function test_expired_promotion_is_removed_from_authoritative_listing_type(): void
    {
        [$agent, $rop] = $this->users();
        $moderation = $this->moderation();
        $promotions = new PropertyPromotionService(new PropertyModerationAccess, $moderation);
        $property = Property::create($this->propertyPayload($agent, [
            'publication_status' => 'published',
            'moderation_status' => 'approved',
            'listing_type' => 'vip',
        ]));
        $promotion = PropertyPromotion::create([
            'property_id' => $property->id,
            'type' => 'vip',
            'status' => PropertyPromotion::STATUS_ACTIVE,
            'requested_by' => $agent->id,
            'requested_at' => now()->subDays(8),
            'decided_by' => $rop->id,
            'decided_at' => now()->subDays(8),
            'starts_at' => now()->subDays(8),
            'ends_at' => now()->subMinute(),
            'version' => 1,
        ]);

        $this->assertSame('regular', $property->fresh()->listing_type);
        $this->assertSame(1, $promotions->expireDue());
        $this->assertSame(PropertyPromotion::STATUS_EXPIRED, $promotion->fresh()->status);
        $this->assertSame('regular', $property->fresh()->listing_type);
    }

    public function test_confirmed_duplicate_blocks_withdraw_and_publication(): void
    {
        [$agent, $rop] = $this->users();
        $service = $this->moderation();
        $original = Property::create($this->propertyPayload($rop, ['publication_status' => 'published', 'moderation_status' => 'approved']));
        $duplicate = Property::create($this->propertyPayload($agent, ['publication_status' => 'pending']));
        $case = PropertyModerationCase::create([
            'property_id' => $duplicate->id,
            'type' => PropertyModerationCase::TYPE_DUPLICATE,
            'status' => PropertyModerationCase::STATUS_OPEN,
            'blocking' => true,
            'submitted_by' => $agent->id,
            'submitted_at' => now(),
            'version' => 1,
        ]);
        $candidate = PropertyDuplicateCandidate::create([
            'moderation_case_id' => $case->id,
            'candidate_property_id' => $original->id,
            'score' => 99,
        ]);

        $service->decideDuplicate($candidate, $rop, PropertyDuplicateCandidate::DECISION_CONFIRMED, 'Один владелец и адрес');
        $this->assertSame('rejected', $duplicate->fresh()->publication_status);
        $this->assertSame($original->id, $duplicate->fresh()->duplicate_of_property_id);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->withdrawChanges($duplicate->fresh(), $agent, (int) $duplicate->fresh()->moderation_version);
    }

    public function test_independent_positive_appeal_reverses_duplicate_block_and_trust_penalty(): void
    {
        [$agent, $rop, $director] = $this->users();
        $service = $this->moderation();
        $original = Property::create($this->propertyPayload($director, ['publication_status' => 'published', 'moderation_status' => 'approved']));
        $duplicate = Property::create($this->propertyPayload($agent, [
            'publication_status' => 'pending',
            'approved_price' => 100_000,
            'approved_effective_price' => 100_000,
            'approved_currency' => 'TJS',
            'approved_content_snapshot' => ['price' => 100_000, 'currency' => 'TJS'],
        ]));
        $case = PropertyModerationCase::create([
            'property_id' => $duplicate->id,
            'type' => PropertyModerationCase::TYPE_DUPLICATE,
            'status' => PropertyModerationCase::STATUS_OPEN,
            'blocking' => true,
            'submitted_by' => $agent->id,
            'submitted_at' => now(),
            'version' => 1,
        ]);
        $candidate = PropertyDuplicateCandidate::create([
            'moderation_case_id' => $case->id,
            'candidate_property_id' => $original->id,
            'score' => 99,
        ]);
        $service->decideDuplicate($candidate, $rop, PropertyDuplicateCandidate::DECISION_CONFIRMED, 'Совпадение', 1);
        $appeal = $service->appeal($case->fresh(), $agent, 'Это разные квартиры', (int) $case->fresh()->version);

        $resolved = $service->approveCase($appeal, $director, 'Апелляция подтверждена', 1);

        $this->assertSame('published', $resolved->publication_status);
        $this->assertNull($resolved->duplicate_of_property_id);
        $this->assertNotNull($candidate->fresh()->reversed_at);
        $this->assertDatabaseHas('employee_trust_events', [
            'moderation_case_id' => $appeal->id,
            'type' => 'duplicate_confirmation_reversed',
            'points_delta' => 15,
        ]);
    }

    public function test_case_withdraw_restores_snapshot_without_removing_other_history(): void
    {
        [$agent] = $this->users();
        $service = $this->moderation();
        $property = Property::create($this->propertyPayload($agent, [
            'price' => 120_000,
            'publication_status' => 'pending',
            'approved_price' => 100_000,
            'approved_effective_price' => 100_000,
            'approved_currency' => 'TJS',
            'approved_content_snapshot' => ['price' => 100_000, 'currency' => 'TJS'],
            'moderation_version' => 3,
        ]));
        $case = PropertyModerationCase::create([
            'property_id' => $property->id,
            'type' => PropertyModerationCase::TYPE_PRICE_INCREASE,
            'status' => PropertyModerationCase::STATUS_OPEN,
            'blocking' => true,
            'submitted_by' => $agent->id,
            'submitted_at' => now(),
            'version' => 2,
        ]);

        $restored = $service->withdrawCase($case, $agent, 2);

        $this->assertSame('published', $restored->publication_status);
        $this->assertSame(100000.0, (float) $restored->price);
        $this->assertSame(PropertyModerationCase::STATUS_WITHDRAWN, $case->fresh()->status);
    }

    public function test_transfer_requires_independent_moderator_and_preserves_moderation_history(): void
    {
        [$agent, $rop, $director] = $this->users();
        $service = $this->moderation();
        $property = Property::create($this->propertyPayload($agent, [
            'publication_status' => 'pending',
            'moderation_version' => 4,
        ]));
        $case = PropertyModerationCase::create([
            'property_id' => $property->id,
            'type' => PropertyModerationCase::TYPE_CONTENT,
            'status' => PropertyModerationCase::STATUS_OPEN,
            'blocking' => true,
            'submitted_by' => $agent->id,
            'submitted_at' => now(),
            'version' => 1,
        ]);

        $transferred = $service->transfer($property, $rop, ['agent_id' => $director->id], 'Смена ответственного', 4);

        $this->assertSame($director->id, $transferred->agent_id);
        $this->assertSame($agent->id, $case->fresh()->submitted_by);
        $this->assertSame(PropertyModerationCase::STATUS_OPEN, $case->fresh()->status);
        $this->assertDatabaseHas('property_moderation_events', [
            'property_id' => $property->id,
            'event_type' => 'property_transferred',
            'actor_id' => $rop->id,
        ]);
    }

    public function test_merge_moves_safe_user_references_to_the_canonical_property(): void
    {
        Schema::create('favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('property_id')->nullable();
            $table->string('entity_type')->default('property');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'property_id']);
        });
        Schema::create('selections', function (Blueprint $table): void {
            $table->id();
            $table->json('property_ids');
            $table->timestamps();
        });

        [$agent, $rop, $director] = $this->users();
        $canonical = Property::create($this->propertyPayload($director, ['publication_status' => 'published', 'moderation_status' => 'approved']));
        $duplicate = Property::create($this->propertyPayload($agent, ['publication_status' => 'pending']));
        $case = PropertyModerationCase::create([
            'property_id' => $duplicate->id,
            'type' => PropertyModerationCase::TYPE_DUPLICATE,
            'status' => PropertyModerationCase::STATUS_OPEN,
            'blocking' => true,
            'submitted_by' => $agent->id,
            'submitted_at' => now(),
            'version' => 1,
        ]);
        $candidate = PropertyDuplicateCandidate::create([
            'moderation_case_id' => $case->id,
            'candidate_property_id' => $canonical->id,
            'score' => 99,
        ]);
        DB::table('favorites')->insert([
            ['user_id' => $agent->id, 'property_id' => $duplicate->id, 'entity_type' => 'property', 'entity_id' => $duplicate->id, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $rop->id, 'property_id' => $duplicate->id, 'entity_type' => 'property', 'entity_id' => $duplicate->id, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $rop->id, 'property_id' => $canonical->id, 'entity_type' => 'property', 'entity_id' => $canonical->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $selectionId = DB::table('selections')->insertGetId([
            'property_ids' => json_encode([$duplicate->id, $canonical->id]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = $this->moderation();
        $service->decideDuplicate($candidate, $rop, PropertyDuplicateCandidate::DECISION_CONFIRMED, 'Подтверждённый дубль', 1);
        $merged = $service->mergeDuplicate(
            $candidate->fresh(),
            $rop,
            'Объединить с каноническим объектом',
            (int) $case->fresh()->version,
        );

        $this->assertSame('archived', $merged->publication_status);
        $this->assertDatabaseMissing('favorites', ['property_id' => $duplicate->id]);
        $this->assertSame(2, DB::table('favorites')->where('property_id', $canonical->id)->count());
        $this->assertSame([$canonical->id], json_decode((string) DB::table('selections')->where('id', $selectionId)->value('property_ids'), true));
        $this->assertSame(1, DB::table('employee_trust_events')->where('moderation_case_id', $case->id)->where('type', 'confirmed_duplicate')->count());
    }

    public function test_clean_new_draft_submit_is_server_published_but_withdrawn_listing_requires_review(): void
    {
        [$agent] = $this->users();
        $service = $this->moderation();
        $draft = Property::create($this->propertyPayload($agent, [
            'publication_status' => 'draft',
            'moderation_status' => 'draft',
            'moderation_version' => 1,
        ]));

        $published = $service->submit($draft, $agent, [], 1);
        $this->assertSame('published', $published->publication_status);
        $this->assertNotNull($published->approved_content_snapshot);

        $published->forceFill(['publication_status' => 'draft', 'moderation_status' => 'draft'])->save();
        $submitted = $service->submit($published->fresh(), $agent, [], (int) $published->fresh()->moderation_version);
        $this->assertSame('pending', $submitted->publication_status);
        $this->assertDatabaseHas('property_moderation_cases', [
            'property_id' => $published->id,
            'type' => PropertyModerationCase::TYPE_INITIAL,
            'status' => PropertyModerationCase::STATUS_OPEN,
        ]);
    }

    public function test_idempotency_key_replays_the_original_response_without_repeating_the_action(): void
    {
        [$agent] = $this->users();
        $middleware = new EnsurePropertyModerationIdempotency;
        $calls = 0;
        $makeRequest = function () use ($agent): Request {
            $request = Request::create('/api/properties/99/withdraw', 'POST', [
                'target' => 'draft',
                'version' => 3,
            ]);
            $request->headers->set('Idempotency-Key', 'moderation-test-key-0001');
            $request->setUserResolver(fn () => $agent);

            return $request;
        };
        $action = function () use (&$calls) {
            $calls++;

            return response()->json(['data' => ['version' => 4]], 200);
        };

        $first = $middleware->handle($makeRequest(), $action);
        $replayed = $middleware->handle($makeRequest(), $action);

        $this->assertSame(1, $calls);
        $this->assertSame($first->getContent(), $replayed->getContent());
        $this->assertSame('true', $replayed->headers->get('Idempotent-Replayed'));
    }

    public function test_moderation_notifications_use_a_numeric_priority_and_are_deduplicated(): void
    {
        [$agent, $rop, $director] = $this->users();
        $property = Property::create($this->propertyPayload($agent));
        $notifier = app(\App\Services\PropertyModeration\PropertyModerationNotifier::class);
        $notifier->moderationEvent($property, 'duplicate_review_opened', $agent);
        $notifier->moderationEvent($property, 'duplicate_review_opened', $agent);

        $notifications = DB::table('notifications')->get();
        $this->assertEqualsCanonicalizing([$agent->id, $rop->id, $director->id], $notifications->pluck('user_id')->all());
        foreach ($notifications as $notification) {
            $this->assertTrue(is_numeric($notification->priority), 'Notification priority must match the integer database column.');
            $this->assertContains((int) $notification->priority, \App\Support\Notifications\NotificationPriority::all());
        }
    }

    public function test_rendered_server_error_rolls_back_writes_and_allows_retry_with_the_same_key(): void
    {
        [$agent] = $this->users();
        $request = Request::create('/api/properties', 'POST');
        $request->headers->set('Idempotency-Key', 'failed-property-create-0001');
        $request->setUserResolver(fn () => $agent);
        $middleware = new EnsurePropertyModerationIdempotency;
        $failed = $middleware->handle($request, function () use ($agent) {
            Property::create($this->propertyPayload($agent));
            // Laravel's routing pipeline can render an exception before it reaches this middleware.
            return response()->json(['message' => 'Server Error.'], 500);
        });
        $this->assertSame(500, $failed->getStatusCode());
        $this->assertDatabaseCount('properties', 0);
        $this->assertDatabaseCount('property_moderation_idempotency_keys', 0);

        $calls = 0;
        $action = function () use ($agent, &$calls) {
            $calls++;
            return response()->json(['id' => Property::create($this->propertyPayload($agent))->id], 201);
        };
        $retried = $middleware->handle($request, $action);
        $replayed = $middleware->handle($request, $action);
        $this->assertSame(201, $retried->getStatusCode());
        $this->assertSame($retried->getContent(), $replayed->getContent());
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('properties', 1);
    }

    public function test_vip_and_urgent_creation_wait_for_listing_and_promotion_approval(): void
    {
        [$agent, $rop] = $this->users();
        $service = $this->moderation();
        $promotions = app(\App\Services\PropertyModeration\PropertyPromotionService::class);
        foreach (['vip', 'urgent'] as $type) {
            $property = Property::create($service->creationState($this->propertyPayload($agent), collect(), [], true));
            $service->recordCreation($property, $agent, collect(), [], true);
            $promotion = $promotions->request($property, $agent, $type, 'Выбрано при добавлении', 7, $property->moderation_version);
            $this->assertSame('pending', $property->fresh()->publication_status);
            $this->assertSame('regular', $property->fresh()->listing_type);
            $this->assertSame('requested', $promotion->status);
            $case = $property->moderationCases()->where('type', 'initial_review')->firstOrFail();
            try {
                $promotions->approve($promotion, $rop, 7, null, $promotion->version);
                $this->fail('Promotion must not activate before listing approval.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
            $service->approveCase($case, $rop, 'Объявление проверено');
            $this->assertSame('published', $property->fresh()->publication_status);
            $promotions->approve($promotion, $rop, 7, 'Продвижение подтверждено', $promotion->version);
            $this->assertSame($type, $property->fresh()->listing_type);
            $this->assertSame('active', $promotion->fresh()->status);
        }
    }

    public function test_owner_can_cancel_pending_promotion_but_cannot_revoke_active_promotion(): void
    {
        [$agent, $rop] = $this->users();
        $property = $this->publishedProperty($agent);
        $service = app(\App\Services\PropertyModeration\PropertyPromotionService::class);
        $promotion = $service->request($property, $agent, 'vip', 'Заявка на VIP', 7, $property->moderation_version);
        $service->revoke($promotion, $agent, 'Выбран обычный тип', $promotion->version);
        $this->assertSame('revoked', $promotion->fresh()->status);
        $promotion = $service->request($property->fresh(), $agent, 'urgent', 'Заявка на срочное', 7, $property->fresh()->moderation_version);
        $service->approve($promotion, $rop, 7, null, $promotion->version);
        try {
            $service->revoke($promotion->fresh(), $agent, 'Отмена', $promotion->fresh()->version);
            $this->fail('Owner cannot revoke approved promotion.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $service->revoke($promotion->fresh(), $rop, 'Выбран обычный тип', $promotion->fresh()->version);
        $this->assertSame('regular', $property->fresh()->listing_type);
    }

    private function moderation(): PropertyModerationService
    {
        return new PropertyModerationService(new PropertyDuplicateService, new PropertyModerationAccess);
    }

    public function test_content_change_while_price_is_pending_cannot_be_published_by_returning_price(): void
    {
        [$agent] = $this->users();
        $service = $this->moderation();
        $property = $this->publishedProperty($agent);
        $this->saveChanges($service, $property, $agent, ['price' => 110_000]);
        $this->saveChanges($service, $property, $agent, ['address' => 'Другой объект']);
        $this->saveChanges($service, $property, $agent, ['price' => 100_000]);

        $this->assertSame('pending', $property->fresh()->publication_status);
        $this->assertDatabaseHas('property_moderation_cases', [
            'property_id' => $property->id, 'type' => 'content_review', 'status' => 'open',
        ]);
    }

    public function test_small_description_edits_are_compared_to_approved_content_even_with_price_decreases(): void
    {
        [$agent] = $this->users();
        $service = $this->moderation();
        $property = $this->publishedProperty($agent, ['description' => str_repeat('a', 100)]);
        foreach ([10, 20, 30, 40] as $changed) {
            $this->saveChanges($service, $property, $agent, [
                'description' => str_repeat('b', $changed).str_repeat('a', 100 - $changed),
                'price' => 100_000 - $changed,
            ]);
        }

        $this->assertSame('pending', $property->fresh()->publication_status);
        $this->assertSame(str_repeat('a', 100), $property->fresh()->approved_content_snapshot['description']);
    }

    public function test_duplicate_evidence_is_immutable_on_repeated_search(): void
    {
        [$agent] = $this->users();
        $canonical = Property::create($this->propertyPayload($agent));
        $service = $this->moderation();
        $property = $this->publishedProperty($agent);
        foreach ([['score' => 80, 'signals' => ['original']], ['score' => 99, 'signals' => ['changed']]] as $evidence) {
            $service->recordUpdateOutcome($property, $agent, [
                'cases' => [['type' => 'duplicate_review', 'reason_codes' => ['duplicate_candidates']]],
                'duplicates' => collect([array_merge(['id' => $canonical->id], $evidence)]),
                'withdraw_case_types' => [], 'event' => null,
            ]);
        }

        $candidate = PropertyDuplicateCandidate::firstOrFail();
        $this->assertSame(['original'], $candidate->signals);
        $this->assertSame(80, $candidate->candidate_snapshot['score']);
        $this->assertSame(1, PropertyDuplicateCandidate::count());
    }

    public function test_withdraw_changes_keeps_unresolved_duplicate_case_blocking(): void
    {
        [$agent] = $this->users();
        $service = $this->moderation();
        $property = $this->publishedProperty($agent);
        $case = PropertyModerationCase::create([
            'property_id' => $property->id, 'type' => 'duplicate_review', 'status' => 'open',
            'blocking' => true, 'submitted_by' => $agent->id, 'submitted_at' => now(), 'version' => 1,
        ]);
        $property->forceFill(['publication_status' => 'pending', 'approved_content_snapshot' => ['price' => 100_000, 'currency' => 'TJS']])->save();
        $result = $service->withdrawChanges($property, $agent, $property->moderation_version);

        $this->assertSame('pending', $result->publication_status);
        $this->assertTrue($case->fresh()->blocking);
        $this->assertSame('open', $case->fresh()->status);
    }

    public function test_moderator_cannot_approve_a_case_they_subsequently_edited(): void
    {
        [$agent, $rop] = $this->users();
        $service = $this->moderation();
        $property = $this->publishedProperty($agent);
        $this->saveChanges($service, $property, $agent, ['price' => 110_000]);
        $this->saveChanges($service, $property, $rop, ['price' => 120_000]);
        $case = $property->moderationCases()->firstOrFail();

        $this->assertFalse(app(PropertyModerationAccess::class)->canDecideCase($rop, $case));
        $this->assertSame($agent->id, $case->submitted_by);
    }

    public function test_rejection_cannot_be_overridden_by_approving_another_case(): void
    {
        [$agent, $rop] = $this->users();
        $service = $this->moderation();
        $property = $this->publishedProperty($agent);
        $this->saveChanges($service, $property, $agent, ['price' => 110000, 'address' => 'Другой адрес']);
        $price = $property->moderationCases()->where('type', 'price_increase')->firstOrFail();
        $content = $property->moderationCases()->where('type', 'content_review')->firstOrFail();
        $service->rejectCase($price, $rop, 'Цена не подтверждена', $price->version);
        $service->approveCase($content, $rop, 'Адрес подтверждён', $content->version);

        $this->assertFalse($service->isPublic($property->fresh()));
        $this->assertSame('100000.00', $property->fresh()->approved_price);
    }

    public function test_withdraw_restores_approved_owner_details(): void
    {
        [$agent] = $this->users();
        $service = $this->moderation();
        $property = $this->publishedProperty($agent, ['owner_phone' => '900111111']);
        $this->saveChanges($service, $property, $agent, ['owner_phone' => '900222222']);
        $restored = $service->withdrawChanges($property, $agent, $property->fresh()->moderation_version);

        $this->assertSame('900111111', $restored->owner_phone);
        $this->assertSame('published', $restored->publication_status);
    }

    public function test_queue_scope_does_not_follow_creator_into_another_branch(): void
    {
        [$agent, $rop] = $this->users();
        $foreignProperty = Property::create($this->propertyPayload($agent, ['branch_id' => 2]));
        $access = app(PropertyModerationAccess::class);

        $this->assertFalse($access->canModerate($rop, $foreignProperty));
        $this->assertFalse($access->scopeModeratable(Property::query(), $rop)->whereKey($foreignProperty->id)->exists());
        $rop->forceFill(['branch_id' => null])->save();
        $this->assertFalse($access->scopeModeratable(Property::query(), $rop)->exists());
    }

    public function test_deleted_approved_photos_can_be_restored_after_multiple_pending_mutations(): void
    {
        [$agent] = $this->users();
        $this->actingAs($agent);
        \Storage::fake('public');
        $service = $this->moderation();
        $property = $this->publishedProperty($agent);
        foreach ([0, 1] as $position) {
            $path = "properties/approved-{$position}.jpg";
            \Storage::disk('public')->put($path, "approved-content-{$position}");
            $property->photos()->create(['file_path' => $path, 'position' => $position]);
        }
        $service->recordCreation($property, $agent, collect());
        $this->saveChanges($service, $property, $agent, ['price' => 110000]);
        $photos = $property->photos()->get();
        $controller = app(\App\Http\Controllers\PropertyPhotoController::class);
        foreach ($photos as $photo) {
            $request = Request::create('/', 'DELETE', ['version' => $property->fresh()->moderation_version]);
            $controller->destroy($request, $property->fresh(), $photo);
            \Storage::disk('public')->assertExists($photo->file_path);
        }
        $restored = $service->withdrawChanges($property->fresh(), $agent, $property->fresh()->moderation_version);

        $this->assertSame('published', $restored->publication_status);
        $this->assertSame(2, $restored->photos()->count());
        $snapshot = $service->photoSnapshot($restored);
        $this->assertSame(hash('sha256', 'approved-content-0'), $snapshot[0]['hash']);
    }

    public function test_withdrawing_price_case_restores_price_even_when_content_case_stays_open(): void
    {
        [$agent, $rop] = $this->users();
        $service = $this->moderation();
        $property = $this->publishedProperty($agent);
        $this->saveChanges($service, $property, $agent, ['price' => 110000, 'address' => 'Другой адрес']);
        $price = $property->moderationCases()->where('type', 'price_increase')->firstOrFail();
        $service->withdrawCase($price, $agent, $price->version);
        $content = $property->moderationCases()->where('type', 'content_review')->firstOrFail();
        $service->approveCase($content, $rop, 'Проверено', $content->version);

        $this->assertEquals(100000, $property->fresh()->price);
        $this->assertSame('100000.00', $property->fresh()->approved_price);
    }

    private function publishedProperty(User $agent, array $overrides = []): Property
    {
        $service = $this->moderation();
        $property = Property::create($service->creationState($this->propertyPayload($agent, $overrides), collect()));
        $service->recordCreation($property, $agent, collect());

        return $property->fresh();
    }

    private function saveChanges(PropertyModerationService $service, Property $property, User $actor, array $changes): void
    {
        $property->refresh()->fill($changes);
        $outcome = $service->evaluateUpdate($property, $actor);
        $property->save();
        $service->recordUpdateOutcome($property, $actor, $outcome);
    }

    public function test_director_can_view_pending_property_in_own_branch_but_guests_and_other_branches_cannot(): void
    {
        [$agent, , $director] = $this->users();
        $property = Property::create($this->propertyPayload($agent));
        $this->moderation()->publicOrFail($property, $director);
        $this->assertTrue(app(PropertyModerationAccess::class)->canModerate($director, $property));

        foreach ([null, tap(clone $director, fn ($user) => $user->branch_id = 2)] as $viewer) {
            try {
                $this->moderation()->publicOrFail($property, $viewer);
                $this->fail('Unpublished listing must not be exposed outside its authorized scope.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        }
    }

    private function users(): array
    {
        $agentRole = Role::firstOrCreate(['slug' => 'agent'], ['name' => 'Agent']);
        $ropRole = Role::firstOrCreate(['slug' => 'rop'], ['name' => 'ROP']);
        $directorRole = Role::firstOrCreate(['slug' => 'branch_director'], ['name' => 'Director']);
        $agent = User::forceCreate(['name' => 'Agent', 'phone' => '900000001', 'role_id' => $agentRole->id, 'branch_id' => 1, 'status' => 'active']);
        $rop = User::forceCreate(['name' => 'ROP', 'phone' => '900000002', 'role_id' => $ropRole->id, 'branch_id' => 1, 'status' => 'active']);
        $director = User::forceCreate(['name' => 'Director', 'phone' => '900000003', 'role_id' => $directorRole->id, 'branch_id' => 1, 'status' => 'active']);

        return [$agent, $rop, $director];
    }

    private function propertyPayload(User $creator, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Квартира',
            'price' => 100_000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'created_by' => $creator->id,
            'agent_id' => $creator->id,
            'branch_id' => 1,
            'moderation_status' => 'pending',
            'publication_status' => 'pending',
            'deal_status' => 'available',
            'listing_type' => 'regular',
        ], $overrides);
    }
}
