<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PublicRealtorProfileTest extends TestCase
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
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('auth_method', ['password', 'sms'])->default('password');
            $table->rememberToken()->nullable();
            $table->text('description')->nullable();
            $table->string('photo')->nullable();
            $table->timestamps();
        });
    }

    public function test_public_realtor_profile_is_accessible_without_authorization(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agent = User::create([
            'name' => 'Искандар',
            'phone' => '750762020',
            'email' => 'agent@example.test',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
            'description' => 'Текст о специалисте',
            'photo' => 'users/107/photo.jpg',
        ]);

        $response = $this->getJson('/api/public/realtors/' . $agent->id);

        $response->assertOk();
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('s-maxage=900', (string) $response->headers->get('Cache-Control'));
        $response->assertJson([
            'id' => $agent->id,
            'name' => 'Искандар',
            'photo' => 'users/107/photo.jpg',
            'description' => 'Текст о специалисте',
            'phone' => '750762020',
            'position' => 'Специалист по недвижимости',
            'rating' => null,
            'review_count' => 0,
            'reviews' => [],
        ]);
        $response->assertJsonMissingPath('email');
        $response->assertJsonMissingPath('status');
        $response->assertJsonMissingPath('role_id');
        $response->assertJsonMissingPath('created_at');
        $response->assertJsonMissingPath('updated_at');
    }

    public function test_public_realtor_profile_returns_404_for_non_public_roles(): void
    {
        $managerRole = Role::create(['name' => 'Manager', 'slug' => 'manager']);

        $manager = User::create([
            'name' => 'Manager',
            'phone' => '750762021',
            'password' => bcrypt('password'),
            'role_id' => $managerRole->id,
            'status' => 'active',
        ]);

        $this->getJson('/api/public/realtors/' . $manager->id)->assertNotFound();
    }

    public function test_public_realtor_profile_returns_404_for_inactive_agents(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agent = User::create([
            'name' => 'Inactive Agent',
            'phone' => '750762022',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'inactive',
        ]);

        $this->getJson('/api/public/realtors/' . $agent->id)->assertNotFound();
    }

    public function test_public_realtor_profile_includes_reviews_when_review_schema_is_available(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->string('reviewer_name');
            $table->unsignedTinyInteger('rating');
            $table->text('text')->nullable();
            $table->string('status')->default('approved');
            $table->timestamps();
        });

        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agent = User::create([
            'name' => 'Искандар',
            'phone' => '750762023',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        Schema::disableForeignKeyConstraints();
        \DB::table('reviews')->insert([
            [
                'agent_id' => $agent->id,
                'reviewer_name' => 'Фирдавс',
                'rating' => 5,
                'text' => 'Хороший специалист',
                'status' => 'approved',
                'created_at' => '2026-03-01 10:00:00',
                'updated_at' => '2026-03-01 10:00:00',
            ],
            [
                'agent_id' => $agent->id,
                'reviewer_name' => 'Hidden',
                'rating' => 1,
                'text' => 'Не должен попасть',
                'status' => 'pending',
                'created_at' => '2026-03-02 10:00:00',
                'updated_at' => '2026-03-02 10:00:00',
            ],
        ]);
        Schema::enableForeignKeyConstraints();

        $response = $this->getJson('/api/public/realtors/' . $agent->id);

        $response->assertOk();
        $this->assertSame(5.0, (float) $response->json('rating'));
        $response->assertJsonPath('review_count', 1);
        $response->assertJsonCount(1, 'reviews');
        $response->assertJsonPath('reviews.0.author', 'Фирдавс');
        $response->assertJsonPath('reviews.0.date', '2026-03-01');
        $response->assertJsonPath('reviews.0.text', 'Хороший специалист');
    }
}
