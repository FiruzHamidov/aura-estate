<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientType;
use App\Models\Property;
use App\Models\PropertyStatus;
use App\Models\PropertyType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PropertyShowAuthContactsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('branch_groups', function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('branch_id'); $table->string('name'); $table->timestamps(); });
        \Illuminate\Support\Facades\DB::table('branch_groups')->insert(['id' => 100, 'branch_id' => 10, 'name' => 'Group 100']);
        \Illuminate\Support\Facades\DB::table('branch_groups')->insert(['id' => 200, 'branch_id' => 20, 'name' => 'Group 200']);
        \Illuminate\Support\Facades\DB::table('branch_groups')->insert(['id' => 300, 'branch_id' => 30, 'name' => 'Group 300']);

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('status')->default('active');
            $table->string('auth_method')->default('password');
            $table->rememberToken()->nullable();
            $table->timestamps();
        });

        Schema::create('client_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_business')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
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

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('repair_type_id')->nullable();
            $table->decimal('price', 15, 2);
            $table->string('currency')->default('TJS');
            $table->string('offer_type')->default('sale');
            $table->tinyInteger('rooms')->nullable();
            $table->string('youtube_link')->nullable();
            $table->float('total_area')->nullable();
            $table->decimal('land_size', 10, 2)->nullable();
            $table->float('living_area')->nullable();
            $table->integer('floor')->nullable();
            $table->integer('total_floors')->nullable();
            $table->integer('year_built')->nullable();
            $table->string('condition')->nullable();
            $table->string('construction_status')->nullable();
            $table->string('renovation_permission_status')->nullable();
            $table->string('apartment_type')->nullable();
            $table->boolean('has_garden')->default(false);
            $table->boolean('has_parking')->default(false);
            $table->boolean('is_mortgage_available')->default(false);
            $table->boolean('is_from_developer')->default(false);
            $table->string('moderation_status')->default('approved');
            $table->string('landmark')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('district')->nullable();
            $table->string('address')->nullable();
            $table->string('owner_phone')->nullable();
            $table->string('listing_type')->default('regular');
            $table->string('owner_name')->nullable();
            $table->unsignedBigInteger('owner_client_id')->nullable();
            $table->string('object_key')->nullable();
            $table->boolean('is_business_owner')->default(false);
            $table->unsignedBigInteger('developer_id')->nullable();
            $table->boolean('is_full_apartment')->default(false);
            $table->boolean('is_for_aura')->default(false);
            $table->unsignedBigInteger('parking_type_id')->nullable();
            $table->unsignedBigInteger('heating_type_id')->nullable();
            $table->text('rejection_comment')->nullable();
            $table->text('status_comment')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->decimal('actual_sale_price', 15, 2)->nullable();
            $table->string('actual_sale_currency')->nullable();
            $table->decimal('company_commission_amount', 15, 2)->nullable();
            $table->string('company_commission_currency')->nullable();
            $table->string('money_holder')->nullable();
            $table->timestamp('money_received_at')->nullable();
            $table->timestamp('contract_signed_at')->nullable();
            $table->decimal('deposit_amount', 15, 2)->nullable();
            $table->string('deposit_currency')->nullable();
            $table->timestamp('deposit_received_at')->nullable();
            $table->timestamp('deposit_taken_at')->nullable();
            $table->string('buyer_full_name')->nullable();
            $table->string('buyer_phone')->nullable();
            $table->unsignedBigInteger('buyer_client_id')->nullable();
            $table->decimal('company_expected_income', 15, 2)->nullable();
            $table->string('company_expected_income_currency')->nullable();
            $table->timestamp('planned_contract_signed_at')->nullable();
            $table->unsignedBigInteger('contract_type_id')->nullable();
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
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function publicRopFixture(): array
    {
        Schema::create('branches', function (Blueprint $table) { $table->id(); $table->string('name'); $table->timestamps(); });
        \Illuminate\Support\Facades\DB::table('branches')->insert([['id' => 10, 'name' => 'Own'], ['id' => 20, 'name' => 'Other']]);
        (require database_path('migrations/2026_09_08_120000_create_rop_group_access.php'))->up();
        (require database_path('migrations/2026_09_04_100000_add_property_moderation_workflow.php'))->up();
        $role = Role::create(['name' => 'ROP', 'slug' => 'rop']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $rop = User::create(['name' => 'ROP', 'phone' => '900111111', 'role_id' => $role->id, 'branch_id' => 10, 'status' => 'active']);
        $rop->supervisedGroups()->attach(100);
        $agent = User::create(['name' => 'Public agent', 'phone' => '900222222', 'role_id' => $agentRole->id, 'branch_id' => 20, 'branch_group_id' => 200]);
        $property = Property::create(['title' => 'Public listing', 'type_id' => PropertyType::create(['name' => 'Apartment'])->id,
            'status_id' => PropertyStatus::create(['name' => 'Available'])->id, 'price' => 100000, 'currency' => 'TJS',
            'created_by' => $agent->id, 'agent_id' => $agent->id, 'branch_id' => 20, 'branch_group_id' => 200,
            'moderation_status' => 'approved', 'publication_status' => 'published', 'deal_status' => 'available',
            'owner_phone' => '900333333', 'owner_name' => 'Private owner', 'buyer_phone' => '900444444',
            'buyer_full_name' => 'Private buyer', 'status_comment' => 'Internal note', 'company_commission_amount' => 200]);

        return [$rop, $property, $agent];
    }

    public function test_rop_reads_public_foreign_listing_without_private_fields_or_actions(): void
    {
        [$rop, $property, $agent] = $this->publicRopFixture();
        $guest = $this->getJson('/api/properties/'.$property->id)->assertOk()->json();
        $this->withToken($rop->createToken('test')->plainTextToken);
        $response = $this->getJson('/api/properties/'.$property->id)->assertOk()
            ->assertJsonPath('public_view_only', true)->assertJsonPath('title', $guest['title'])
            ->assertJsonPath('price', $guest['price'])->assertJsonPath('creator.name', $agent->name)
            ->assertJsonPath('creator.phone', $agent->phone);
        foreach (['owner_phone', 'owner_name', 'owner_client_id', 'owner_client', 'buyer_phone', 'buyer_full_name',
            'buyer_client', 'company_commission_amount', 'status_comment', 'moderation_cases', 'moderation_version', 'external_source'] as $field) {
            $this->assertArrayNotHasKey($field, $response->json());
        }
        foreach ($response->json('capabilities') as $value) $this->assertFalse($value);
        $response->assertHeader('X-Access-Scope-Version', '0');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson('/api/properties/'.$property->id.'/logs')->assertNotFound();
        $this->getJson('/api/properties/'.$property->id.'/duplicate-candidates')->assertNotFound();
        $this->putJson('/api/properties/'.$property->id, ['title' => 'Changed'])->assertNotFound();
        $this->deleteJson('/api/properties/'.$property->id)->assertNotFound();
        $this->postJson('/api/properties/'.$property->id.'/moderation/approve-all', ['version' => 0])->assertNotFound();
        $this->assertSame('Public listing', $property->fresh()->title);
    }

    public function test_rop_cannot_read_unpublished_foreign_listing(): void
    {
        [$rop, $property] = $this->publicRopFixture();
        $this->withToken($rop->createToken('test')->plainTextToken);
        foreach (['pending', 'draft', 'archived'] as $state) {
            $property->forceFill(['publication_status' => $state, 'moderation_status' => 'pending'])->saveQuietly();
            $this->getJson('/api/properties/'.$property->id)->assertNotFound();
        }
    }

    public function test_group_revocation_downgrades_published_card_to_public_view_and_hides_pending_card(): void
    {
        [$rop, $property] = $this->publicRopFixture();
        $property->forceFill(['branch_id' => 10, 'branch_group_id' => 100])->saveQuietly();
        $this->withToken($rop->createToken('test')->plainTextToken);
        $this->getJson('/api/properties/'.$property->id)->assertOk()->assertJsonPath('capabilities.can_edit', true);
        $rop->supervisedGroups()->detach();
        $this->getJson('/api/properties/'.$property->id)->assertOk()->assertJsonPath('public_view_only', true)->assertJsonPath('capabilities.can_edit', false);
        $property->forceFill(['publication_status' => 'pending', 'moderation_status' => 'pending'])->saveQuietly();
        $this->getJson('/api/properties/'.$property->id)->assertNotFound();
    }

    public function test_security_can_read_closed_report_cards_and_history_but_cannot_edit(): void
    {
        $role = Role::create(['name' => 'Security', 'slug' => 'security']);
        $user = User::create(['name' => 'Security reader', 'phone' => '930000559', 'role_id' => $role->id, 'status' => 'active']);
        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Closed']);
        $token = $user->createToken('test')->plainTextToken;
        foreach (['deposit', 'sold', 'sold_by_owner', 'rented', 'deleted', 'pending', 'denied'] as $state) {
            $property = Property::create(['title' => 'Access test', 'type_id' => $type->id, 'status_id' => $status->id,
                'price' => 100, 'moderation_status' => $state, 'created_by' => $user->id]);
            $allowed = in_array($state, ['deposit', 'sold', 'sold_by_owner', 'rented', 'deleted'], true);
            $this->withToken($token)->getJson('/api/properties/'.$property->id)->assertStatus($allowed ? 200 : 404);
            $this->withToken($token)->getJson('/api/properties/'.$property->id.'/logs')->assertStatus($allowed ? 200 : 403);
            $access = app(\App\Services\PropertyModeration\PropertyModerationAccess::class);
            $this->assertFalse($access->canEdit($user, $property));
            $this->assertFalse($access->canModerate($user, $property));
            $this->withToken($token)->deleteJson('/api/properties/'.$property->id)->assertForbidden();
            $this->app['auth']->forgetGuards();
            $this->withHeader('Authorization', '')->getJson('/api/properties/'.$property->id)->assertNotFound();
        }
    }

    public function test_public_property_show_with_bearer_token_includes_owner_and_buyer_contacts(): void
    {
        $agentRole = Role::create([
            'name' => 'Agent',
            'slug' => 'agent',
        ]);

        $user = User::create([
            'name' => 'Agent User',
            'phone' => '930000101',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        $individualType = ClientType::create([
            'name' => 'Individual',
            'slug' => ClientType::SLUG_INDIVIDUAL,
            'is_business' => false,
        ]);

        $businessType = ClientType::create([
            'name' => 'Business',
            'slug' => ClientType::SLUG_BUSINESS_OWNER,
            'is_business' => true,
        ]);

        $ownerClient = Client::create([
            'full_name' => 'Owner Client',
            'phone' => '930000201',
            'client_type_id' => $individualType->id,
            'contact_kind' => Client::CONTACT_KIND_SELLER,
        ]);

        $buyerClient = Client::create([
            'full_name' => 'Buyer Client',
            'phone' => '930000202',
            'client_type_id' => $businessType->id,
            'contact_kind' => Client::CONTACT_KIND_BUYER,
        ]);

        $propertyType = PropertyType::create(['name' => 'Apartment']);
        $propertyStatus = PropertyStatus::create(['name' => 'Available']);

        $property = Property::create([
            'title' => 'Test property',
            'type_id' => $propertyType->id,
            'status_id' => $propertyStatus->id,
            'price' => 250000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $user->id,
            'owner_client_id' => $ownerClient->id,
            'owner_name' => 'Owner Snapshot',
            'owner_phone' => '930000201',
            'buyer_client_id' => $buyerClient->id,
            'buyer_full_name' => 'Buyer Snapshot',
            'buyer_phone' => '930000202',
        ]);

        $token = $user->createToken(
            'api-token',
            ['*'],
            now()->addHours(24)
        )->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/properties/'.$property->id);

        $response->assertOk();
        $response->assertJsonPath('owner_client_id', $ownerClient->id);
        $response->assertJsonPath('owner_name', 'Owner Snapshot');
        $response->assertJsonPath('owner_phone', '930000201');
        $response->assertJsonPath('owner_client.id', $ownerClient->id);
        $response->assertJsonPath('owner_client.full_name', 'Owner Client');
        $response->assertJsonPath('ownerClient.id', $ownerClient->id);
        $response->assertJsonPath('buyer_client_id', $buyerClient->id);
        $response->assertJsonPath('buyer_full_name', 'Buyer Snapshot');
        $response->assertJsonPath('buyer_phone', '930000202');
        $response->assertJsonPath('buyer_client.id', $buyerClient->id);
        $response->assertJsonPath('buyer_client.full_name', 'Buyer Client');
        $response->assertJsonPath('buyerClient.id', $buyerClient->id);
    }

    public function test_property_show_returns_stable_branch_ids_for_property_and_creator(): void
    {
        $agentRole = Role::create([
            'name' => 'Agent',
            'slug' => 'agent',
        ]);

        $creator = User::create([
            'name' => 'Creator User',
            'phone' => '930000301',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => 10,
            'branch_group_id' => 100,
            'status' => 'active',
        ]);

        $agent = User::create([
            'name' => 'Responsible Agent',
            'phone' => '930000302',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => 20,
            'branch_group_id' => 200,
            'status' => 'active',
        ]);

        $propertyType = PropertyType::create(['name' => 'Apartment']);
        $propertyStatus = PropertyStatus::create(['name' => 'Available']);

        $propertyWithOwnBranch = Property::create([
            'title' => 'Property with own branch',
            'type_id' => $propertyType->id,
            'status_id' => $propertyStatus->id,
            'price' => 250000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $creator->id,
            'agent_id' => $agent->id,
            'branch_id' => 30,
            'branch_group_id' => 300,
        ]);

        $propertyWithAgentBranch = Property::create([
            'title' => 'Property with agent branch',
            'type_id' => $propertyType->id,
            'status_id' => $propertyStatus->id,
            'price' => 260000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $creator->id,
            'agent_id' => $agent->id,
            'branch_id' => null,
            'branch_group_id' => null,
        ]);

        $this->getJson('/api/properties/'.$propertyWithOwnBranch->id)
            ->assertOk()
            ->assertJsonPath('created_by', $creator->id)
            ->assertJsonPath('agent_id', $agent->id)
            ->assertJsonPath('branch_id', 30)
            ->assertJsonPath('branch_group_id', 300)
            ->assertJsonPath('creator.id', $creator->id)
            ->assertJsonPath('creator.branch_id', 10)
            ->assertJsonPath('creator.branch_group_id', 100);

        $this->getJson('/api/properties/'.$propertyWithAgentBranch->id)
            ->assertOk()
            ->assertJsonPath('created_by', $creator->id)
            ->assertJsonPath('agent_id', $agent->id)
            ->assertJsonPath('branch_id', 20)
            ->assertJsonPath('branch_group_id', 200)
            ->assertJsonPath('creator.id', $creator->id)
            ->assertJsonPath('creator.branch_id', 10)
            ->assertJsonPath('creator.branch_group_id', 100);
    }
}
