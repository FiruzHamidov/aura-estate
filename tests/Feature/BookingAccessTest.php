<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingAccessTest extends TestCase
{
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
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('contact_visibility_mode')->default('branch');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('branch_group_id')->nullable()->constrained('branch_groups')->nullOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('auth_method', ['password', 'sms'])->default('password');
            $table->rememberToken()->nullable();
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->text('note')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('pending');
            $table->string('client_name')->nullable();
            $table->string('client_phone')->nullable();
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

    public function test_branch_director_only_sees_bookings_from_own_branch_even_with_foreign_filters(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $directorRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $director = User::create([
            'name' => 'Director A',
            'phone' => '910000001',
            'password' => bcrypt('password'),
            'role_id' => $directorRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        $agentA = User::create([
            'name' => 'Agent A',
            'phone' => '910000002',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        $agentB = User::create([
            'name' => 'Agent B',
            'phone' => '910000003',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branchB->id,
            'status' => 'active',
        ]);

        $propertyA = Property::create([
            'title' => 'Property A',
            'created_by' => $agentA->id,
            'agent_id' => $agentA->id,
        ]);

        $propertyB = Property::create([
            'title' => 'Property B',
            'created_by' => $agentB->id,
            'agent_id' => $agentB->id,
        ]);

        $bookingA = Booking::create([
            'property_id' => $propertyA->id,
            'agent_id' => $agentA->id,
            'start_time' => now()->toDateTimeString(),
            'end_time' => now()->addHour()->toDateTimeString(),
            'client_name' => 'Client A',
            'client_phone' => '920000001',
        ]);

        Booking::create([
            'property_id' => $propertyB->id,
            'agent_id' => $agentB->id,
            'start_time' => now()->toDateTimeString(),
            'end_time' => now()->addHour()->toDateTimeString(),
            'client_name' => 'Client B',
            'client_phone' => '920000002',
        ]);

        Sanctum::actingAs($director);

        $response = $this->getJson('/api/bookings?branch_id=' . $branchB->id . '&agent_id=' . $agentB->id);

        $response->assertOk();
        $response->assertJsonCount(0, 'data');

        $response = $this->getJson('/api/bookings');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $bookingA->id);
    }

    public function test_superadmin_sees_all_bookings_and_can_filter_any_branch_globally(): void
    {
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);

        $superadminRole = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $superadmin = User::create([
            'name' => 'Superadmin',
            'phone' => '910000011',
            'password' => bcrypt('password'),
            'role_id' => $superadminRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        $agentA = User::create([
            'name' => 'Agent A',
            'phone' => '910000012',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branchA->id,
            'status' => 'active',
        ]);

        $agentB = User::create([
            'name' => 'Agent B',
            'phone' => '910000013',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branchB->id,
            'status' => 'active',
        ]);

        $propertyA = Property::create([
            'title' => 'Property A',
            'created_by' => $agentA->id,
            'agent_id' => $agentA->id,
        ]);

        $propertyB = Property::create([
            'title' => 'Property B',
            'created_by' => $agentB->id,
            'agent_id' => $agentB->id,
        ]);

        $bookingA = Booking::create([
            'property_id' => $propertyA->id,
            'agent_id' => $agentA->id,
            'start_time' => now()->toDateTimeString(),
            'end_time' => now()->addHour()->toDateTimeString(),
            'client_name' => 'Client A',
            'client_phone' => '920000011',
        ]);

        $bookingB = Booking::create([
            'property_id' => $propertyB->id,
            'agent_id' => $agentB->id,
            'start_time' => now()->toDateTimeString(),
            'end_time' => now()->addHour()->toDateTimeString(),
            'client_name' => 'Client B',
            'client_phone' => '920000012',
        ]);

        Sanctum::actingAs($superadmin);

        $all = $this->getJson('/api/bookings');
        $all->assertOk();
        $all->assertJsonCount(2, 'data');
        $all->assertJsonFragment(['id' => $bookingA->id]);
        $all->assertJsonFragment(['id' => $bookingB->id]);

        $filtered = $this->getJson('/api/bookings?branch_id=' . $branchB->id);
        $filtered->assertOk();
        $filtered->assertJsonCount(1, 'data');
        $filtered->assertJsonPath('data.0.id', $bookingB->id);
    }

    public function test_client_only_sees_own_bookings_and_cannot_open_foreign_booking(): void
    {
        $branch = Branch::create(['name' => 'Main Branch']);

        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $clientRole = Role::create(['name' => 'Client', 'slug' => 'client']);

        $agent = User::create([
            'name' => 'Agent',
            'phone' => '910000021',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $clientA = User::create([
            'name' => 'Client A',
            'phone' => '910000022',
            'password' => bcrypt('password'),
            'role_id' => $clientRole->id,
            'status' => 'active',
        ]);

        $clientB = User::create([
            'name' => 'Client B',
            'phone' => '910000023',
            'password' => bcrypt('password'),
            'role_id' => $clientRole->id,
            'status' => 'active',
        ]);

        $property = Property::create([
            'title' => 'Property',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
        ]);

        $bookingA = Booking::create([
            'property_id' => $property->id,
            'agent_id' => $agent->id,
            'client_id' => $clientA->id,
            'start_time' => now()->toDateTimeString(),
            'end_time' => now()->addHour()->toDateTimeString(),
            'client_name' => 'Client A',
            'client_phone' => '920000021',
        ]);

        $bookingB = Booking::create([
            'property_id' => $property->id,
            'agent_id' => $agent->id,
            'client_id' => $clientB->id,
            'start_time' => now()->addDay()->toDateTimeString(),
            'end_time' => now()->addDay()->addHour()->toDateTimeString(),
            'client_name' => 'Client B',
            'client_phone' => '920000022',
        ]);

        Sanctum::actingAs($clientA);

        $index = $this->getJson('/api/bookings');
        $index->assertOk();
        $index->assertJsonCount(1, 'data');
        $index->assertJsonPath('data.0.id', $bookingA->id);

        $ownShow = $this->getJson('/api/bookings/'.$bookingA->id);
        $ownShow->assertOk();
        $ownShow->assertJsonPath('id', $bookingA->id);

        $foreignShow = $this->getJson('/api/bookings/'.$bookingB->id);
        $foreignShow->assertForbidden();
    }

    public function test_bookings_index_is_paginated(): void
    {
        $branch = Branch::create(['name' => 'Main Branch']);

        $superadminRole = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $superadmin = User::create([
            'name' => 'Superadmin',
            'phone' => '910000031',
            'password' => bcrypt('password'),
            'role_id' => $superadminRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $agent = User::create([
            'name' => 'Agent',
            'phone' => '910000032',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $property = Property::create([
            'title' => 'Property',
            'created_by' => $agent->id,
            'agent_id' => $agent->id,
        ]);

        Booking::create([
            'property_id' => $property->id,
            'agent_id' => $agent->id,
            'start_time' => now()->toDateTimeString(),
            'end_time' => now()->addHour()->toDateTimeString(),
            'client_name' => 'Client A',
            'client_phone' => '920000031',
        ]);

        Booking::create([
            'property_id' => $property->id,
            'agent_id' => $agent->id,
            'start_time' => now()->addDay()->toDateTimeString(),
            'end_time' => now()->addDay()->addHour()->toDateTimeString(),
            'client_name' => 'Client B',
            'client_phone' => '920000032',
        ]);

        Sanctum::actingAs($superadmin);

        $response = $this->getJson('/api/bookings?per_page=1');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('per_page', 1);
        $response->assertJsonPath('total', 2);
        $response->assertJsonPath('current_page', 1);
        $response->assertJsonPath('last_page', 2);
    }

    public function test_agent_only_sees_own_bookings_even_with_foreign_agent_filter(): void
    {
        $branch = Branch::create(['name' => 'Main Branch']);

        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agentA = User::create([
            'name' => 'Agent A',
            'phone' => '910000041',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $agentB = User::create([
            'name' => 'Agent B',
            'phone' => '910000042',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $propertyA = Property::create([
            'title' => 'Property A',
            'created_by' => $agentA->id,
            'agent_id' => $agentA->id,
        ]);

        $propertyB = Property::create([
            'title' => 'Property B',
            'created_by' => $agentB->id,
            'agent_id' => $agentB->id,
        ]);

        $bookingA = Booking::create([
            'property_id' => $propertyA->id,
            'agent_id' => $agentA->id,
            'start_time' => '2026-04-15 09:00:00',
            'end_time' => '2026-04-15 10:00:00',
            'client_name' => 'Client A',
            'client_phone' => '920000041',
        ]);

        Booking::create([
            'property_id' => $propertyB->id,
            'agent_id' => $agentB->id,
            'start_time' => '2026-04-15 11:00:00',
            'end_time' => '2026-04-15 12:00:00',
            'client_name' => 'Client B',
            'client_phone' => '920000042',
        ]);

        Sanctum::actingAs($agentA);

        $response = $this->getJson('/api/bookings?agent_id=' . $agentB->id);

        $response->assertOk();
        $response->assertJsonCount(0, 'data');

        $ownOnly = $this->getJson('/api/bookings');

        $ownOnly->assertOk();
        $ownOnly->assertJsonCount(1, 'data');
        $ownOnly->assertJsonPath('data.0.id', $bookingA->id);
    }

    public function test_superadmin_can_filter_bookings_by_branch_group_and_date_range(): void
    {
        $branch = Branch::create(['name' => 'Main Branch']);
        $groupA = BranchGroup::create([
            'branch_id' => $branch->id,
            'name' => 'Group A',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_BRANCH,
        ]);
        $groupB = BranchGroup::create([
            'branch_id' => $branch->id,
            'name' => 'Group B',
            'contact_visibility_mode' => BranchGroup::CONTACT_VISIBILITY_BRANCH,
        ]);

        $superadminRole = Role::create(['name' => 'Superadmin', 'slug' => 'superadmin']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $superadmin = User::create([
            'name' => 'Superadmin',
            'phone' => '910000051',
            'password' => bcrypt('password'),
            'role_id' => $superadminRole->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $agentA = User::create([
            'name' => 'Agent A',
            'phone' => '910000052',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $groupA->id,
            'status' => 'active',
        ]);

        $agentB = User::create([
            'name' => 'Agent B',
            'phone' => '910000053',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'branch_id' => $branch->id,
            'branch_group_id' => $groupB->id,
            'status' => 'active',
        ]);

        $propertyA = Property::create([
            'title' => 'Property A',
            'created_by' => $agentA->id,
            'agent_id' => $agentA->id,
        ]);

        $propertyB = Property::create([
            'title' => 'Property B',
            'created_by' => $agentB->id,
            'agent_id' => $agentB->id,
        ]);

        $matchingBooking = Booking::create([
            'property_id' => $propertyA->id,
            'agent_id' => $agentA->id,
            'start_time' => '2026-04-10 09:00:00',
            'end_time' => '2026-04-10 10:00:00',
            'client_name' => 'Client A',
            'client_phone' => '920000051',
        ]);

        Booking::create([
            'property_id' => $propertyA->id,
            'agent_id' => $agentA->id,
            'start_time' => '2026-05-10 09:00:00',
            'end_time' => '2026-05-10 10:00:00',
            'client_name' => 'Client A2',
            'client_phone' => '920000052',
        ]);

        Booking::create([
            'property_id' => $propertyB->id,
            'agent_id' => $agentB->id,
            'start_time' => '2026-04-12 09:00:00',
            'end_time' => '2026-04-12 10:00:00',
            'client_name' => 'Client B',
            'client_phone' => '920000053',
        ]);

        Sanctum::actingAs($superadmin);

        $response = $this->getJson('/api/bookings?branch_group_id=' . $groupA->id . '&date_from=2026-04-01&date_to=2026-04-30');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matchingBooking->id);
        $response->assertJsonPath('total', 1);
    }
}
