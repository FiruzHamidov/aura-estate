<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ExternalPropertyRequest;
use App\Models\Property;
use App\Models\PropertyStatus;
use App\Models\PropertyType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExternalPropertyRequestFeatureTest extends TestCase
{
    protected Role $externalRole;
    protected Role $agentRole;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('branch_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('status')->default('active');
            $table->string('auth_method')->default('password');
            $table->rememberToken()->nullable();
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable();
            $table->string('email')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->unsignedBigInteger('client_type_id')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_comment')->nullable();
            $table->string('contact_kind', 16)->default(Client::CONTACT_KIND_BUYER);
            $table->string('status')->default('active');
            $table->unsignedBigInteger('bitrix_contact_id')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('property_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('building_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('city')->nullable();
            $table->timestamps();
        });

        Schema::create('repair_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->decimal('price', 15, 2);
            $table->string('currency')->default('TJS');
            $table->string('offer_type')->default('sale');
            $table->tinyInteger('rooms')->nullable();
            $table->decimal('total_area', 10, 2)->nullable();
            $table->decimal('living_area', 10, 2)->nullable();
            $table->decimal('land_size', 10, 2)->nullable();
            $table->integer('floor')->nullable();
            $table->integer('total_floors')->nullable();
            $table->string('condition')->nullable();
            $table->string('moderation_status')->default('pending');
            $table->string('landmark')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('external_agent_id')->nullable();
            $table->unsignedBigInteger('external_property_request_id')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('district')->nullable();
            $table->string('address')->nullable();
            $table->string('owner_phone')->nullable();
            $table->string('listing_type')->default('regular');
            $table->string('owner_name')->nullable();
            $table->unsignedBigInteger('owner_client_id')->nullable();
            $table->timestamps();
        });

        Schema::create('property_photos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->string('file_path');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('property_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('external_property_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('external_agent_id');
            $table->unsignedBigInteger('assigned_agent_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->unsignedBigInteger('owner_client_id')->nullable();
            $table->string('status')->default(ExternalPropertyRequest::STATUS_SUBMITTED);
            $table->string('offer_type')->nullable();
            $table->unsignedBigInteger('type_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('district')->nullable();
            $table->string('address')->nullable();
            $table->string('landmark')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->string('currency')->nullable();
            $table->unsignedTinyInteger('rooms')->nullable();
            $table->decimal('total_area', 10, 2)->nullable();
            $table->decimal('living_area', 10, 2)->nullable();
            $table->decimal('land_size', 10, 2)->nullable();
            $table->integer('floor')->nullable();
            $table->integer('total_floors')->nullable();
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->string('condition')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('owner_phone')->nullable();
            $table->string('owner_phone_normalized')->nullable();
            $table->text('external_comment')->nullable();
            $table->text('internal_comment')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('needs_info_comment')->nullable();
            $table->unsignedBigInteger('duplicate_property_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('external_property_request_photos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('external_property_request_id');
            $table->string('file_path');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('external_property_request_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('external_property_request_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('comment')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->externalRole = Role::create(['name' => 'External', 'slug' => 'external_agent']);
        $this->agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        \Illuminate\Support\Facades\DB::table('branches')->insert([
            [
                'id' => 1,
                'name' => 'Main',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'Foreign',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        \Illuminate\Support\Facades\DB::table('branch_groups')->insert([
            [
                'id' => 1,
                'branch_id' => 1,
                'name' => 'Main Group',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'branch_id' => 2,
                'name' => 'Foreign Group',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_external_agent_creates_and_sees_only_own_requests(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000001');
        $otherExternalAgent = $this->user($this->externalRole, '930000002');
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);

        ExternalPropertyRequest::create([
            'external_agent_id' => $otherExternalAgent->id,
            'status' => ExternalPropertyRequest::STATUS_SUBMITTED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 100000,
            'currency' => 'USD',
            'owner_phone' => '900000000',
        ]);

        Sanctum::actingAs($externalAgent);

        $this->postJson('/api/external/property-requests', [
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'district' => 'Сино',
            'owner_name' => 'Соҳиб',
            'owner_phone' => '900000001',
        ])->assertCreated()
            ->assertJsonPath('status', ExternalPropertyRequest::STATUS_SUBMITTED);

        $response = $this->getJson('/api/external/property-requests')->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame(ExternalPropertyRequest::STATUS_SUBMITTED, $response->json('data.0.status'));
        $this->assertSame($externalAgent->id, ExternalPropertyRequest::query()->latest('id')->first()->external_agent_id);
    }

    public function test_external_agent_cannot_override_branch_scope(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000003', branchId: 1, branchGroupId: 1);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);

        Sanctum::actingAs($externalAgent);

        $this->postJson('/api/external/property-requests', [
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'branch_id' => 999,
            'branch_group_id' => 999,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => '900000003',
        ])->assertCreated();

        $request = ExternalPropertyRequest::query()->firstOrFail();

        $this->assertSame(1, $request->branch_id);
        $this->assertSame(1, $request->branch_group_id);
    }

    public function test_external_agent_response_hides_internal_comments(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000004');
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $request = ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'status' => ExternalPropertyRequest::STATUS_SUBMITTED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => '900000004',
            'internal_comment' => 'private internal note',
        ]);
        $request->logs()->create([
            'action' => 'status_changed',
            'from_status' => ExternalPropertyRequest::STATUS_SUBMITTED,
            'to_status' => ExternalPropertyRequest::STATUS_ASSIGNED,
            'comment' => 'private status note',
        ]);

        Sanctum::actingAs($externalAgent);

        $response = $this->getJson("/api/external/property-requests/{$request->id}")
            ->assertOk()
            ->assertJsonMissingPath('internal_comment');

        $this->assertStringNotContainsString('private internal note', $response->getContent());
        $this->assertStringNotContainsString('private status note', $response->getContent());
    }

    public function test_empty_draft_cannot_be_submitted_until_required_fields_exist(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000005');
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);

        Sanctum::actingAs($externalAgent);

        $draftId = $this->postJson('/api/external/property-requests?draft=1', [])
            ->assertCreated()
            ->assertJsonPath('status', ExternalPropertyRequest::STATUS_DRAFT)
            ->json('id');

        $this->postJson("/api/external/property-requests/{$draftId}/submit")
            ->assertStatus(422)
            ->assertJsonPath('details.errors.offer_type.0', 'The Тип предложения field is required.')
            ->assertJsonPath('details.errors.type_id.0', 'The Тип недвижимости field is required.')
            ->assertJsonPath('details.errors.price.0', 'The Цена field is required.')
            ->assertJsonPath('details.errors.currency.0', 'The Валюта field is required.')
            ->assertJsonPath('details.errors.owner_phone.0', 'The Телефон владельца field is required.');

        $this->patchJson("/api/external/property-requests/{$draftId}", [
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => '900000005',
        ])->assertOk();

        $this->postJson("/api/external/property-requests/{$draftId}/submit")
            ->assertOk()
            ->assertJsonPath('status', ExternalPropertyRequest::STATUS_SUBMITTED);
    }

    public function test_internal_agent_converts_external_request_to_property_with_source(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000011', branchId: 1, branchGroupId: 1);
        $internalAgent = $this->user($this->agentRole, '930000012', branchId: 1, branchGroupId: 1);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $status = PropertyStatus::create(['name' => 'Вторичка', 'slug' => 'secondary']);

        $request = ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'branch_id' => 1,
            'branch_group_id' => 1,
            'status' => ExternalPropertyRequest::STATUS_SUBMITTED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'rooms' => 2,
            'district' => 'Сино',
            'owner_name' => 'Соҳиб',
            'owner_phone' => '900000001',
            'owner_phone_normalized' => '992900000001',
            'external_comment' => 'Срочно продает',
        ]);

        Sanctum::actingAs($internalAgent);

        $this->postJson("/api/external-agent-requests/{$request->id}/convert", [
            'status_id' => $status->id,
        ])->assertCreated()
            ->assertJsonPath('data.external_agent_id', $externalAgent->id)
            ->assertJsonPath('data.external_property_request_id', $request->id)
            ->assertJsonPath('data.source_type', ExternalPropertyRequest::SOURCE_TYPE);

        $request->refresh();
        $property = Property::query()->firstOrFail();
        $client = Client::query()->firstOrFail();

        $this->assertSame(ExternalPropertyRequest::STATUS_CONVERTED, $request->status);
        $this->assertSame($property->id, $request->property_id);
        $this->assertSame($externalAgent->id, $property->external_agent_id);
        $this->assertSame($request->id, $property->external_property_request_id);
        $this->assertSame(ExternalPropertyRequest::SOURCE_TYPE, $property->source_type);
        $this->assertSame($client->id, $property->owner_client_id);
        $this->assertSame(Client::CONTACT_KIND_SELLER, $client->contact_kind);
    }

    public function test_duplicate_request_requires_force_before_conversion(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000021', branchId: 1, branchGroupId: 1);
        $internalAgent = $this->user($this->agentRole, '930000022', branchId: 1, branchGroupId: 1);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $status = PropertyStatus::create(['name' => 'Вторичка', 'slug' => 'secondary']);

        $existing = Property::create([
            'title' => 'Existing property',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 90000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'owner_phone' => '+992 900 000 777',
            'moderation_status' => 'approved',
            'created_by' => $internalAgent->id,
        ]);

        Sanctum::actingAs($externalAgent);

        $createdResponse = $this->postJson('/api/external/property-requests', [
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => '900000777',
        ])->assertCreated();

        $createdResponse->assertJsonPath('status', ExternalPropertyRequest::STATUS_DUPLICATE);
        $createdResponse->assertJsonPath('duplicate_property_id', $existing->id);

        $requestId = $createdResponse->json('id');
        Sanctum::actingAs($internalAgent);

        $this->postJson("/api/external-agent-requests/{$requestId}/convert", [
            'status_id' => $status->id,
        ])->assertStatus(409);

        $this->postJson("/api/external-agent-requests/{$requestId}/convert", [
            'status_id' => $status->id,
            'force' => true,
        ])->assertCreated();
    }

    public function test_internal_agent_cannot_assign_request_to_external_agent(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000031', branchId: 1, branchGroupId: 1);
        $internalAgent = $this->user($this->agentRole, '930000032', branchId: 1, branchGroupId: 1);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $request = ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'branch_id' => 1,
            'branch_group_id' => 1,
            'status' => ExternalPropertyRequest::STATUS_SUBMITTED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => '900000031',
        ]);

        Sanctum::actingAs($internalAgent);

        $this->patchJson("/api/external-agent-requests/{$request->id}/assign", [
            'assigned_agent_id' => $externalAgent->id,
        ])->assertStatus(422);
    }

    public function test_external_agent_cannot_create_property_directly(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000041', branchId: 1, branchGroupId: 1);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $status = PropertyStatus::create(['name' => 'Вторичка', 'slug' => 'secondary']);

        Sanctum::actingAs($externalAgent);

        $this->postJson('/api/properties', [
            'title' => 'Direct property',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 85000,
            'currency' => 'USD',
            'offer_type' => 'sale',
        ])->assertForbidden();
    }

    public function test_property_list_can_filter_external_agent_source(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000051', branchId: 1, branchGroupId: 1);
        $internalAgent = $this->user($this->agentRole, '930000052', branchId: 1, branchGroupId: 1);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $status = PropertyStatus::create(['name' => 'Вторичка', 'slug' => 'secondary']);

        $externalProperty = Property::create([
            'title' => 'External sourced',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 85000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $internalAgent->id,
            'agent_id' => $internalAgent->id,
            'external_agent_id' => $externalAgent->id,
            'source_type' => ExternalPropertyRequest::SOURCE_TYPE,
        ]);

        Property::create([
            'title' => 'Regular property',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 75000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $internalAgent->id,
            'agent_id' => $internalAgent->id,
        ]);

        Sanctum::actingAs($internalAgent);

        $response = $this->getJson('/api/properties?source_type=external_agent')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame($externalProperty->id, $response->json('data.0.id'));
    }

    public function test_property_show_exposes_external_source_only_for_authenticated_users(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000061', branchId: 1, branchGroupId: 1);
        $internalAgent = $this->user($this->agentRole, '930000062', branchId: 1, branchGroupId: 1);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $status = PropertyStatus::create(['name' => 'Вторичка', 'slug' => 'secondary']);
        $request = ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'branch_id' => 1,
            'branch_group_id' => 1,
            'status' => ExternalPropertyRequest::STATUS_CONVERTED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => '900000061',
            'submitted_at' => now(),
            'converted_at' => now(),
        ]);
        $property = Property::create([
            'title' => 'External sourced',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 85000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $internalAgent->id,
            'agent_id' => $internalAgent->id,
            'external_agent_id' => $externalAgent->id,
            'external_property_request_id' => $request->id,
            'source_type' => ExternalPropertyRequest::SOURCE_TYPE,
        ]);
        $request->update(['property_id' => $property->id]);

        $this->getJson("/api/properties/{$property->id}")
            ->assertOk()
            ->assertJsonMissingPath('external_source');

        Sanctum::actingAs($internalAgent);

        $this->getJson("/api/properties/{$property->id}")
            ->assertOk()
            ->assertJsonPath('external_source.source_type', ExternalPropertyRequest::SOURCE_TYPE)
            ->assertJsonPath('external_source.external_agent_id', $externalAgent->id)
            ->assertJsonPath('external_source.external_property_request_id', $request->id);
    }

    public function test_external_agent_stats_are_scoped_to_own_requests(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000071', branchId: 1, branchGroupId: 1);
        $otherExternalAgent = $this->user($this->externalRole, '930000072', branchId: 1, branchGroupId: 1);
        $internalAgent = $this->user($this->agentRole, '930000073', branchId: 1, branchGroupId: 1);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $status = PropertyStatus::create(['name' => 'Вторичка', 'slug' => 'secondary']);

        ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'status' => ExternalPropertyRequest::STATUS_SUBMITTED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => '900000071',
        ]);

        $converted = ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'status' => ExternalPropertyRequest::STATUS_CONVERTED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 95000,
            'currency' => 'USD',
            'owner_phone' => '900000074',
        ]);
        $property = Property::create([
            'title' => 'Published external',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 95000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $internalAgent->id,
            'external_agent_id' => $externalAgent->id,
            'external_property_request_id' => $converted->id,
            'source_type' => ExternalPropertyRequest::SOURCE_TYPE,
        ]);
        $converted->update(['property_id' => $property->id]);

        ExternalPropertyRequest::create([
            'external_agent_id' => $otherExternalAgent->id,
            'status' => ExternalPropertyRequest::STATUS_SUBMITTED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 75000,
            'currency' => 'USD',
            'owner_phone' => '900000075',
        ]);

        Sanctum::actingAs($externalAgent);

        $this->getJson('/api/external/property-requests/stats')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('by_status.submitted', 1)
            ->assertJsonPath('by_status.converted', 1)
            ->assertJsonPath('converted.total', 1)
            ->assertJsonPath('converted.published', 1);
    }

    public function test_internal_stats_respect_agent_scope_and_filters(): void
    {
        $externalAgent = $this->user($this->externalRole, '930000081', branchId: 1, branchGroupId: 1);
        $internalAgent = $this->user($this->agentRole, '930000082', branchId: 1, branchGroupId: 1);
        $foreignAgent = $this->user($this->agentRole, '930000083', branchId: 2, branchGroupId: 2);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);

        ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'assigned_agent_id' => $internalAgent->id,
            'branch_id' => 1,
            'branch_group_id' => 1,
            'status' => ExternalPropertyRequest::STATUS_ASSIGNED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => '900000081',
        ]);
        ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'assigned_agent_id' => $foreignAgent->id,
            'branch_id' => 2,
            'branch_group_id' => 2,
            'status' => ExternalPropertyRequest::STATUS_ASSIGNED,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 75000,
            'currency' => 'USD',
            'owner_phone' => '900000082',
        ]);

        Sanctum::actingAs($internalAgent);

        $this->getJson('/api/external-agent-requests/stats')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('by_status.assigned', 1);

        $this->getJson('/api/external-agent-requests/stats?status=submitted')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('by_status.assigned', 0);
    }

    public function test_internal_leaderboard_respects_scope_and_sorts_by_conversions(): void
    {
        $externalAgentA = $this->user($this->externalRole, '930000091', branchId: 1, branchGroupId: 1);
        $externalAgentB = $this->user($this->externalRole, '930000092', branchId: 1, branchGroupId: 1);
        $externalAgentForeign = $this->user($this->externalRole, '930000093', branchId: 2, branchGroupId: 2);
        $internalAgent = $this->user($this->agentRole, '930000094', branchId: 1, branchGroupId: 1);
        $foreignInternalAgent = $this->user($this->agentRole, '930000095', branchId: 2, branchGroupId: 2);
        $type = PropertyType::create(['name' => 'Квартира', 'slug' => 'apartment']);
        $status = PropertyStatus::create(['name' => 'Вторичка', 'slug' => 'secondary']);

        $convertedA = $this->externalRequestForLeaderboard($externalAgentA, $internalAgent, $type, ExternalPropertyRequest::STATUS_CONVERTED, '900000091');
        $this->propertyForConvertedRequest($convertedA, $externalAgentA, $internalAgent, $type, $status, 'approved');
        $this->externalRequestForLeaderboard($externalAgentA, $internalAgent, $type, ExternalPropertyRequest::STATUS_SUBMITTED, '900000092');

        $convertedB1 = $this->externalRequestForLeaderboard($externalAgentB, $internalAgent, $type, ExternalPropertyRequest::STATUS_CONVERTED, '900000093');
        $this->propertyForConvertedRequest($convertedB1, $externalAgentB, $internalAgent, $type, $status, 'approved');
        $convertedB2 = $this->externalRequestForLeaderboard($externalAgentB, $internalAgent, $type, ExternalPropertyRequest::STATUS_CONVERTED, '900000094');
        $this->propertyForConvertedRequest($convertedB2, $externalAgentB, $internalAgent, $type, $status, 'sold');

        $foreignConverted = $this->externalRequestForLeaderboard($externalAgentForeign, $foreignInternalAgent, $type, ExternalPropertyRequest::STATUS_CONVERTED, '900000095', branchId: 2, branchGroupId: 2);
        $this->propertyForConvertedRequest($foreignConverted, $externalAgentForeign, $foreignInternalAgent, $type, $status, 'approved');

        Sanctum::actingAs($internalAgent);

        $this->getJson('/api/external-agent-requests/leaderboard')
            ->assertOk()
            ->assertJsonPath('data.0.external_agent_id', $externalAgentB->id)
            ->assertJsonPath('data.0.total', 2)
            ->assertJsonPath('data.0.converted', 2)
            ->assertJsonPath('data.0.closed_deal', 1)
            ->assertJsonPath('data.1.external_agent_id', $externalAgentA->id)
            ->assertJsonPath('data.1.total', 2)
            ->assertJsonPath('data.1.converted', 1)
            ->assertJsonMissing(['external_agent_id' => $externalAgentForeign->id]);
    }

    private function user(Role $role, string $phone, ?int $branchId = null, ?int $branchGroupId = null): User
    {
        return User::create([
            'name' => 'User ' . $phone,
            'phone' => $phone,
            'role_id' => $role->id,
            'branch_id' => $branchId,
            'branch_group_id' => $branchGroupId,
            'status' => 'active',
        ]);
    }

    private function externalRequestForLeaderboard(
        User $externalAgent,
        User $assignedAgent,
        PropertyType $type,
        string $status,
        string $ownerPhone,
        int $branchId = 1,
        int $branchGroupId = 1
    ): ExternalPropertyRequest {
        return ExternalPropertyRequest::create([
            'external_agent_id' => $externalAgent->id,
            'assigned_agent_id' => $assignedAgent->id,
            'branch_id' => $branchId,
            'branch_group_id' => $branchGroupId,
            'status' => $status,
            'offer_type' => 'sale',
            'type_id' => $type->id,
            'price' => 85000,
            'currency' => 'USD',
            'owner_phone' => $ownerPhone,
        ]);
    }

    private function propertyForConvertedRequest(
        ExternalPropertyRequest $request,
        User $externalAgent,
        User $internalAgent,
        PropertyType $type,
        PropertyStatus $status,
        string $moderationStatus
    ): Property {
        $property = Property::create([
            'title' => 'Converted property ' . $request->id,
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 85000,
            'currency' => 'USD',
            'offer_type' => 'sale',
            'moderation_status' => $moderationStatus,
            'created_by' => $internalAgent->id,
            'agent_id' => $internalAgent->id,
            'external_agent_id' => $externalAgent->id,
            'external_property_request_id' => $request->id,
            'source_type' => ExternalPropertyRequest::SOURCE_TYPE,
            'branch_id' => $internalAgent->branch_id,
            'branch_group_id' => $internalAgent->branch_group_id,
        ]);

        $request->update(['property_id' => $property->id]);

        return $property;
    }
}
