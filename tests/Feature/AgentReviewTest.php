<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\SmsAuthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AgentReviewTest extends TestCase
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

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->string('reviewable_type');
            $table->unsignedBigInteger('reviewable_id');
            $table->string('author_name');
            $table->string('author_phone', 64);
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('text')->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['reviewable_type', 'reviewable_id']);
            $table->unique(['reviewable_type', 'reviewable_id', 'author_phone']);
        });
    }

    public function test_public_can_create_review_for_active_agent(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agent = User::create([
            'name' => 'Agent',
            'phone' => '900000100',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        $smsAuthService = $this->createMock(SmsAuthService::class);
        $smsAuthService->expects($this->once())
            ->method('verifyCode')
            ->with('992900000000', '123456')
            ->willReturn(true);
        $this->app->instance(SmsAuthService::class, $smsAuthService);

        $response = $this->postJson('/api/agents/' . $agent->id . '/reviews', [
            'reviewer_name' => 'Фирдавс',
            'reviewer_phone' => '+992 900 000 000',
            'code' => '123456',
            'rating' => 5,
            'text' => 'Хороший специалист',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('review.author', 'Фирдавс');
        $response->assertJsonPath('review.rating', 5);
        $response->assertJsonPath('agent.id', $agent->id);
        $response->assertJsonPath('agent.reviewCount', 1);

        $this->assertDatabaseHas('reviews', [
            'reviewable_type' => 'user',
            'reviewable_id' => $agent->id,
            'author_name' => 'Фирдавс',
            'author_phone' => '992900000000',
            'author_user_id' => null,
            'rating' => 5,
            'status' => 'approved',
        ]);
        $this->assertDatabaseMissing('users', [
            'phone' => '992900000000',
        ]);
    }

    public function test_public_cannot_create_review_for_non_agent(): void
    {
        $managerRole = Role::create(['name' => 'Manager', 'slug' => 'manager']);

        $manager = User::create([
            'name' => 'Manager',
            'phone' => '900000101',
            'password' => bcrypt('password'),
            'role_id' => $managerRole->id,
            'status' => 'active',
        ]);

        $smsAuthService = $this->createMock(SmsAuthService::class);
        $smsAuthService->expects($this->never())->method('verifyCode');
        $this->app->instance(SmsAuthService::class, $smsAuthService);

        $this->postJson('/api/agents/' . $manager->id . '/reviews', [
            'reviewer_name' => 'Фирдавс',
            'reviewer_phone' => '992900000000',
            'code' => '123456',
            'rating' => 5,
        ])->assertNotFound();
    }

    public function test_existing_user_is_not_linked_to_public_review_author(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $clientRole = Role::create(['name' => 'Client', 'slug' => 'client']);

        $agent = User::create([
            'name' => 'Agent',
            'phone' => '900000111',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        $reviewer = User::create([
            'name' => 'Existing Client',
            'phone' => '992900000010',
            'password' => null,
            'role_id' => $clientRole->id,
            'status' => 'active',
        ]);

        $smsAuthService = $this->createMock(SmsAuthService::class);
        $smsAuthService->expects($this->once())
            ->method('verifyCode')
            ->with('992900000010', '123456')
            ->willReturn(true);
        $this->app->instance(SmsAuthService::class, $smsAuthService);

        $response = $this->postJson('/api/agents/' . $agent->id . '/reviews', [
            'reviewer_name' => 'Updated Name From Form',
            'reviewer_phone' => '992900000010',
            'code' => '123456',
            'rating' => 4,
            'text' => 'Нормально',
        ]);

        $response->assertCreated();
        $response->assertJsonMissingPath('reviewer');

        $this->assertDatabaseHas('reviews', [
            'author_user_id' => null,
            'author_phone' => '992900000010',
            'rating' => 4,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $reviewer->id,
            'phone' => '992900000010',
        ]);
    }

    public function test_public_can_list_only_approved_reviews_for_agent(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agent = User::create([
            'name' => 'Agent',
            'phone' => '900000102',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
        ]);

        \DB::table('reviews')->insert([
            [
                'reviewable_type' => 'user',
                'reviewable_id' => $agent->id,
                'author_name' => 'Алишер',
                'author_phone' => '992900000001',
                'rating' => 5,
                'text' => 'Отлично',
                'status' => 'approved',
                'published_at' => '2026-03-01 12:00:00',
                'created_at' => '2026-03-01 12:00:00',
                'updated_at' => '2026-03-01 12:00:00',
            ],
            [
                'reviewable_type' => 'user',
                'reviewable_id' => $agent->id,
                'author_name' => 'Черновик',
                'author_phone' => '992900000002',
                'rating' => 1,
                'text' => 'Не показывать',
                'status' => 'pending',
                'published_at' => null,
                'created_at' => '2026-03-02 12:00:00',
                'updated_at' => '2026-03-02 12:00:00',
            ],
        ]);

        $response = $this->getJson('/api/agents/' . $agent->id . '/reviews');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.author', 'Алишер');
        $response->assertJsonPath('summary.count', 1);
        $this->assertSame(5.0, (float) $response->json('summary.avg_rating'));
    }

    public function test_public_profile_and_reviews_summary_use_same_approved_aggregates(): void
    {
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);

        $agent = User::create([
            'name' => 'Agent',
            'phone' => '900000120',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'status' => 'active',
            'description' => 'Agent description',
        ]);

        \DB::table('reviews')->insert([
            [
                'reviewable_type' => 'user',
                'reviewable_id' => $agent->id,
                'author_name' => 'A',
                'author_phone' => '992900000101',
                'rating' => 5,
                'text' => 'A',
                'status' => 'approved',
                'published_at' => '2026-03-01 09:00:00',
                'created_at' => '2026-03-01 09:00:00',
                'updated_at' => '2026-03-01 09:00:00',
            ],
            [
                'reviewable_type' => 'user',
                'reviewable_id' => $agent->id,
                'author_name' => 'B',
                'author_phone' => '992900000102',
                'rating' => 4,
                'text' => 'B',
                'status' => 'approved',
                'published_at' => '2026-03-02 09:00:00',
                'created_at' => '2026-03-02 09:00:00',
                'updated_at' => '2026-03-02 09:00:00',
            ],
            [
                'reviewable_type' => 'user',
                'reviewable_id' => $agent->id,
                'author_name' => 'C',
                'author_phone' => '992900000103',
                'rating' => 1,
                'text' => 'C',
                'status' => 'pending',
                'published_at' => null,
                'created_at' => '2026-03-03 09:00:00',
                'updated_at' => '2026-03-03 09:00:00',
            ],
        ]);

        $publicResponse = $this->getJson('/api/public/realtors/' . $agent->id);
        $reviewsResponse = $this->getJson('/api/agents/' . $agent->id . '/reviews?per_page=10');

        $publicResponse->assertOk();
        $reviewsResponse->assertOk();

        $this->assertSame($publicResponse->json('review_count'), $reviewsResponse->json('summary.count'));
        $this->assertSame((float) $publicResponse->json('rating'), (float) $reviewsResponse->json('summary.avg_rating'));
        $this->assertSame($reviewsResponse->json('pagination.total'), $reviewsResponse->json('summary.count'));
        $publicResponse->assertJsonPath('review_count', 2);
        $this->assertSame(4.5, (float) $publicResponse->json('rating'));
    }
}
