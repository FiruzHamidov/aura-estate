<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
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

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
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
        $response->assertJsonCount(0);

        $response = $this->getJson('/api/bookings');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.id', $bookingA->id);
    }
}
