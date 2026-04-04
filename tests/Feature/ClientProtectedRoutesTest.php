<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientProtectedRoutesTest extends TestCase
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

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password')->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->rememberToken()->nullable();
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

    public function test_client_is_blocked_from_internal_crm_routes(): void
    {
        $clientRole = Role::create([
            'name' => 'Client',
            'slug' => 'client',
        ]);

        $client = User::create([
            'name' => 'Client User',
            'phone' => '910000031',
            'password' => bcrypt('password'),
            'role_id' => $clientRole->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($client);

        $this->getJson('/api/my-properties')->assertForbidden();
        $this->postJson('/api/bookings', [])->assertForbidden();
        $this->getJson('/api/selections')->assertForbidden();
        $this->getJson('/api/clients')->assertForbidden();
    }
}
