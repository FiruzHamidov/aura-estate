<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyStatus;
use App\Models\PropertyType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PropertyMyPropertiesBranchFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('branch_groups', function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('branch_id'); $table->string('name'); $table->timestamps(); });
        \Illuminate\Support\Facades\DB::table('branch_groups')->insert(['id' => 20, 'branch_id' => 2, 'name' => 'Group 20']);
        \Illuminate\Support\Facades\DB::table('branch_groups')->insert(['id' => 21, 'branch_id' => 2, 'name' => 'Group 21']);
        \Illuminate\Support\Facades\DB::table('branch_groups')->insert(['id' => 30, 'branch_id' => 3, 'name' => 'Group 30']);

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

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('status_id');
            $table->decimal('price', 15, 2);
            $table->string('currency')->default('TJS');
            $table->string('offer_type')->default('sale');
            $table->string('moderation_status')->default('approved');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
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

        Schema::create('property_agent_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id');
            $table->string('role')->nullable();
            $table->decimal('agent_commission_amount', 15, 2)->nullable();
            $table->string('agent_commission_currency')->nullable();
            $table->timestamp('agent_paid_at')->nullable();
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

    public function test_security_report_only_returns_control_stages_and_scoped_totals(): void
    {
        $role = Role::create(['name' => 'СБ', 'slug' => 'security']);
        $sb = User::create(['name' => 'SB', 'phone' => '900000099', 'role_id' => $role->id, 'status' => 'active']);
        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Available']);
        foreach (['approved', 'pending', 'draft', 'deposit', 'sold', 'sold_by_owner', 'rented', 'deleted'] as $stage) {
            Property::create(['title' => $stage, 'type_id' => $type->id, 'status_id' => $status->id,
                'price' => 100, 'currency' => 'TJS', 'created_by' => $sb->id, 'moderation_status' => $stage, 'branch_id' => 2]);
        }
        Sanctum::actingAs($sb);
        $response = $this->getJson('/api/my-properties?per_page=2')->assertOk()->assertJsonPath('total', 5)->assertJsonCount(2, 'data');
        $this->assertEquals(500, $response->json('security_summary.by_currency.0.sum_price'));
        $this->assertEquals(5, collect($response->json('security_summary.by_status'))->sum('total'));
        $this->getJson('/api/my-properties?moderation_status=approved')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/my-properties?moderation_status=approved,sold_by_owner')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.moderation_status', 'sold_by_owner');
        $this->getJson('/api/my-properties?moderation_status=sold_by_owner')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'security_summary.by_status');
        $this->getJson('/api/my-properties?branch_id=3')->assertOk()->assertJsonPath('total', 0);
        $ids = [];
        for ($page = 1; $page <= 3; $page++) {
            $rows = $this->getJson('/api/my-properties?per_page=2&page='.$page)->assertOk()->json('data');
            foreach ($rows as $row) {
                $this->assertContains($row['moderation_status'], ['deposit', 'sold', 'sold_by_owner', 'rented', 'deleted']);
                $ids[] = $row['id'];
            }
        }
        $this->assertCount(5, array_unique($ids));
    }

    public function test_my_properties_filters_by_branch_id_and_excludes_other_branches(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $admin = User::create([
            'name' => 'Admin User',
            'phone' => '900000001',
            'password' => bcrypt('password'),
            'role_id' => $adminRole->id,
            'status' => 'active',
        ]);

        $branch2User = User::create([
            'name' => 'Branch 2 Agent',
            'phone' => '900000002',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => 2,
            'branch_group_id' => 20,
            'status' => 'active',
        ]);

        $branch3User = User::create([
            'name' => 'Branch 3 Agent',
            'phone' => '900000003',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => 3,
            'branch_group_id' => 30,
            'status' => 'active',
        ]);

        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Available']);

        $inBranchByProperty = Property::create([
            'title' => 'Branch 2 direct',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 100000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $branch2User->id,
            'agent_id' => $branch2User->id,
            'branch_id' => 2,
            'branch_group_id' => 20,
        ]);

        $inBranchByAgentFallback = Property::create([
            'title' => 'Branch 2 fallback',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 110000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $branch2User->id,
            'agent_id' => $branch2User->id,
            'branch_id' => null,
            'branch_group_id' => 20,
        ]);

        Property::create([
            'title' => 'Branch 3 direct',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 120000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $branch3User->id,
            'agent_id' => $branch3User->id,
            'branch_id' => 3,
            'branch_group_id' => 30,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/my-properties?branch_id=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['id' => $inBranchByProperty->id]);
        $response->assertJsonFragment(['id' => $inBranchByAgentFallback->id]);

        $returnedBranchIds = collect($response->json('data'))
            ->pluck('branch_id')
            ->map(fn ($id) => $id === null ? null : (int) $id)
            ->all();

        $this->assertNotContains(3, $returnedBranchIds);
    }

    public function test_my_properties_applies_branch_id_and_branch_group_id_together(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $admin = User::create([
            'name' => 'Admin User',
            'phone' => '900000011',
            'password' => bcrypt('password'),
            'role_id' => $adminRole->id,
            'status' => 'active',
        ]);

        $agent = User::create([
            'name' => 'Scoped Agent',
            'phone' => '900000012',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => 2,
            'branch_group_id' => 20,
            'status' => 'active',
        ]);

        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Available']);

        $expected = Property::create([
            'title' => 'Branch 2 Group 20',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 130000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'branch_id' => 2,
            'branch_group_id' => 20,
        ]);

        Property::create([
            'title' => 'Branch 2 Group 21',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 131000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'branch_id' => 2,
            'branch_group_id' => 21,
        ]);

        Property::create([
            'title' => 'Branch 3 Group 30',
            'type_id' => $type->id,
            'status_id' => $status->id,
            'price' => 132000,
            'currency' => 'TJS',
            'offer_type' => 'sale',
            'moderation_status' => 'approved',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
            'branch_id' => 3,
            'branch_group_id' => 30,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/my-properties?branch_id=2&branch_group_id=20');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['id' => $expected->id]);
    }

    public function test_accounting_report_resolves_seller_and_leaders_without_cross_group_names(): void
    {
        Schema::table('properties', fn (Blueprint $table) => $table->unsignedBigInteger('sale_user_id')->nullable());
        $users = [];
        foreach (['accountant', 'superadmin', 'agent', 'rop', 'mop'] as $index => $slug) {
            $role = Role::create(['name' => $slug, 'slug' => $slug]);
            $users[$slug] = User::create(['name' => $slug, 'phone' => '91000000'.$index,
                'role_id' => $role->id, 'branch_id' => 2, 'branch_group_id' => 20]);
        }
        $other = User::create(['name' => 'Other group MOP', 'phone' => '910000009',
            'role_id' => $users['mop']->role_id, 'branch_id' => 2, 'branch_group_id' => 21]);
        $type = PropertyType::create(['name' => 'Apartment']);
        $status = PropertyStatus::create(['name' => 'Available']);
        $property = Property::create(['title' => 'Report test', 'type_id' => $type->id,
            'status_id' => $status->id, 'price' => 100, 'created_by' => $users['accountant']->id,
            'agent_id' => $users['agent']->id, 'sale_user_id' => $users['agent']->id,
            'moderation_status' => 'sold']);
        $property->saleAgents()->attach($users['rop']->id, ['role' => 'partner', 'agent_commission_amount' => 250, 'agent_commission_currency' => 'TJS']);
        foreach (['accountant', 'superadmin'] as $slug) {
            Sanctum::actingAs($users[$slug]);
            $this->getJson('/api/my-properties?moderation_status=sold')->assertOk()
                ->assertJsonPath('data.0.report_seller.name', 'agent')
                ->assertJsonPath('data.0.report_rop.0.name', 'rop')
                ->assertJsonPath('data.0.report_rop.0.amount', 250)
                ->assertJsonPath('data.0.report_mop.0.amount', null)
                ->assertJsonPath('data.0.report_mop.0.name', 'mop')
                ->assertJsonCount(1, 'data.0.report_mop');
        }
        Sanctum::actingAs($users['accountant']);
        $this->getJson('/api/user?report_agents=1')->assertOk()->assertJsonMissingPath('data.0.phone');
        $this->postJson('/api/properties', [])->assertForbidden();
        $this->getJson('/api/user')->assertForbidden();
        Sanctum::actingAs($users['agent']);
        $property->updateQuietly(['created_by' => $users['agent']->id]);
        $this->getJson('/api/my-properties?moderation_status=sold')->assertOk()
            ->assertJsonMissingPath('data.0.report_rop');
    }
}
