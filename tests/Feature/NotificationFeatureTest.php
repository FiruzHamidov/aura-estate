<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationStatus;
use App\Support\Notifications\NotificationType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationFeatureTest extends TestCase
{
    private int $phoneCounter = 960000000;

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
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('auth_method', ['password', 'sms'])->default('password');
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
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedBigInteger('bitrix_contact_id')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('full_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable();
            $table->string('email')->nullable();
            $table->text('note')->nullable();
            $table->string('source')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('responsible_agent_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('status')->default('new');
            $table->timestamp('first_contact_due_at')->nullable();
            $table->timestamp('first_contacted_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('lost_reason')->nullable();
            $table->json('meta')->nullable();
            $table->json('tags')->nullable();
            $table->string('last_contact_result', 100)->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('next_activity_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('crm_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('context')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('type', 100)->nullable();
            $table->string('category', 32)->nullable();
            $table->string('status', 20)->default('unread');
            $table->unsignedTinyInteger('priority')->default(2);
            $table->json('channels')->nullable();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->string('action_url')->nullable();
            $table->string('action_type', 50)->nullable();
            $table->string('dedupe_key')->nullable();
            $table->unsignedInteger('occurrences_count')->default(1);
            $table->timestamp('last_occurred_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('data')->nullable();
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

        DB::table('client_types')->insert([
            'id' => 1,
            'name' => 'Физлицо',
            'slug' => ClientType::SLUG_INDIVIDUAL,
            'is_business' => false,
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_lead_creation_creates_notifications_for_other_managers_and_assignee(): void
    {
        $branch = Branch::create(['name' => 'Central']);
        $managerRole = Role::create(['name' => 'Manager', 'slug' => 'manager']);

        $creator = $this->createUser($managerRole, $branch, 'Creator');
        $assignee = $this->createUser($managerRole, $branch, 'Assignee');
        $observer = $this->createUser($managerRole, $branch, 'Observer');

        Sanctum::actingAs($creator);

        $this->postJson('/api/leads', [
            'full_name' => 'Hot Lead',
            'phone' => $this->nextPhone(),
            'source' => 'website',
            'responsible_agent_id' => $assignee->id,
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $assignee->id,
            'type' => NotificationType::LEAD_ASSIGNED,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $observer->id,
            'type' => NotificationType::LEAD_NEW,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $creator->id,
            'type' => NotificationType::LEAD_NEW,
        ]);
    }

    public function test_notification_api_lists_marks_read_and_counts_unread(): void
    {
        $branch = Branch::create(['name' => 'Central']);
        $managerRole = Role::create(['name' => 'Manager', 'slug' => 'manager']);
        $manager = $this->createUser($managerRole, $branch, 'Manager');

        $unread = Notification::query()->create([
            'user_id' => $manager->id,
            'type' => NotificationType::LEAD_NEW,
            'category' => NotificationCategory::WORKFLOW,
            'status' => NotificationStatus::UNREAD,
            'priority' => 3,
            'channels' => ['in_app'],
            'title' => 'Новый лид',
            'body' => 'Поступил новый лид.',
            'dedupe_key' => 'test:1',
            'occurrences_count' => 1,
            'last_occurred_at' => now(),
            'delivered_at' => now(),
            'data' => [],
        ]);

        Notification::query()->create([
            'user_id' => $manager->id,
            'type' => NotificationType::MOTIVATION_MANAGER_EVENING_DIGEST,
            'category' => NotificationCategory::MOTIVATION,
            'status' => NotificationStatus::READ,
            'priority' => 1,
            'channels' => ['in_app'],
            'title' => 'Итог дня',
            'body' => 'День завершён.',
            'dedupe_key' => 'test:2',
            'occurrences_count' => 1,
            'last_occurred_at' => now(),
            'delivered_at' => now(),
            'read_at' => now(),
            'data' => [],
        ]);

        Sanctum::actingAs($manager);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 1);

        $this->getJson('/api/notifications?category=workflow&is_read=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unread->id);

        $this->patchJson('/api/notifications/'.$unread->id.'/read')
            ->assertOk()
            ->assertJsonPath('status', NotificationStatus::READ);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
    }

    private function createUser(Role $role, Branch $branch, string $name): User
    {
        return User::create([
            'name' => $name,
            'phone' => $this->nextPhone(),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'status' => 'active',
            'auth_method' => 'password',
        ]);
    }

    private function nextPhone(): string
    {
        return '992'.$this->phoneCounter++;
    }
}
