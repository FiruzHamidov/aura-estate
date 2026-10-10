<?php

namespace Tests\Feature;

use App\Jobs\SendFirebasePushNotification;
use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientType;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Selection;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationStatus;
use App\Support\Notifications\NotificationType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
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

        Schema::create('branch_groups', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('branch_id'); $table->string('name'); $table->timestamps();
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

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->text('token');
            $table->string('token_hash', 64)->unique();
            $table->string('platform', 32)->default('web');
            $table->string('device_name')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_used_at')->nullable();
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

    public function test_property_control_event_notifies_security_once_and_aggregates_retries(): void
    {
        $branch = Branch::create(['name' => 'Central']);
        $securityRole = Role::create(['name' => 'Security', 'slug' => 'security']);
        $security = $this->createUser($securityRole, $branch, 'Security Officer');
        $deal = new Deal;
        $deal->forceFill([
            'id' => 501,
            'primary_property_id' => 77,
            'branch_id' => $branch->id,
            'source_property_status' => 'sold',
            'source_event_uuid' => 'f9cd6454-cb8e-5aac-a6a4-876d6fd8f4ce',
        ]);
        $deal->exists = true;

        $service = app(NotificationService::class);
        $service->handlePropertyControlCreated($deal);
        $service->handlePropertyControlCreated($deal);

        $this->assertSame(1, Notification::query()
            ->where('user_id', $security->id)
            ->where('type', NotificationType::PROPERTY_CONTROL_NEW)
            ->count());
        $this->assertSame(2, (int) Notification::query()
            ->where('user_id', $security->id)
            ->where('type', NotificationType::PROPERTY_CONTROL_NEW)
            ->value('occurrences_count'));
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

    public function test_notification_action_urls_match_frontend_routes(): void
    {
        $service = app(NotificationService::class);
        $method = new \ReflectionMethod(NotificationService::class, 'normalizeActionUrl');

        $this->assertSame(
            '/profile/crm/leads?leadId=17&mode=view',
            $method->invoke($service, '/leads/17')
        );
        $this->assertSame(
            '/profile/crm/deals?dealId=23&mode=view',
            $method->invoke($service, '/deals/23')
        );
        $this->assertSame(
            '/profile/chats?conversation=31',
            $method->invoke($service, '/conversations/31')
        );
        $this->assertSame(
            '/profile/my-booking?bookingId=47',
            $method->invoke($service, '/bookings/47')
        );
        $this->assertSame('/apartment/59', $method->invoke($service, '/properties/59'));
        $this->assertSame('/attendance?tab=devices', $method->invoke($service, '/attendance/devices'));
        $this->assertSame(
            'https://aura.tj/apartment/59',
            $method->invoke($service, 'https://aura.tj/properties/59')
        );

        $selection = new Selection(['selection_hash' => 'public/hash']);
        $this->assertSame('/s/public%2Fhash', $method->invoke($service, '/selections/71', $selection));
    }

    public function test_authenticated_user_can_register_refresh_and_remove_a_push_subscription(): void
    {
        $branch = Branch::create(['name' => 'Central']);
        $managerRole = Role::create(['name' => 'Manager', 'slug' => 'manager']);
        $firstUser = $this->createUser($managerRole, $branch, 'First user');
        $secondUser = $this->createUser($managerRole, $branch, 'Second user');
        $token = 'firebase-registration-token';

        Sanctum::actingAs($firstUser);
        $this->putJson('/api/push-subscriptions', [
            'token' => $token,
            'platform' => 'web',
            'device_name' => 'Chrome on macOS',
        ])->assertCreated()->assertJsonPath('registered', true);

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $firstUser->id,
            'token_hash' => hash('sha256', $token),
            'platform' => 'web',
        ]);

        foreach (['android', 'ios'] as $platform) {
            $mobileToken = "{$platform}-firebase-registration-token";
            $this->putJson('/api/push-subscriptions', [
                'token' => $mobileToken,
                'platform' => $platform,
                'device_name' => "Aura {$platform}",
            ])->assertCreated()->assertJsonPath('registered', true);

            $this->assertDatabaseHas('push_subscriptions', [
                'user_id' => $firstUser->id,
                'token_hash' => hash('sha256', $mobileToken),
                'platform' => $platform,
            ]);
        }

        $this->putJson('/api/push-subscriptions', [
            'token' => 'unsupported-platform-token',
            'platform' => 'desktop',
        ])->assertUnprocessable();

        Sanctum::actingAs($secondUser);
        $this->putJson('/api/push-subscriptions', ['token' => $token])
            ->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 3);
        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $secondUser->id,
            'token_hash' => hash('sha256', $token),
        ]);

        $this->deleteJson('/api/push-subscriptions', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('registered', false);
        $this->assertDatabaseCount('push_subscriptions', 2);
        $this->assertDatabaseMissing('push_subscriptions', [
            'token_hash' => hash('sha256', $token),
        ]);
    }

    public function test_firebase_push_builds_platform_specific_payloads(): void
    {
        $service = app(\App\Services\FirebasePushService::class);
        $method = new \ReflectionMethod($service, 'messageForPlatform');
        $data = [
            'title' => 'Новое сообщение',
            'body' => 'Вам ответили в чате',
            'action_url' => 'https://aura.tj/profile/chats?conversation=42',
        ];

        $web = $method->invoke($service, 'web', $data, $data['action_url'], 3)->jsonSerialize();
        $android = $method->invoke($service, 'android', $data, $data['action_url'], 3)->jsonSerialize();
        $ios = $method->invoke($service, 'ios', $data, $data['action_url'], 3)->jsonSerialize();

        $this->assertSame($data['action_url'], $web['webpush']['fcm_options']['link']);
        $this->assertSame('high', $android['android']['priority']);
        $this->assertSame('Новое сообщение', $android['android']['notification']['title']);
        $this->assertSame('10', $ios['apns']['headers']['apns-priority']);
        $this->assertSame('Вам ответили в чате', $ios['apns']['payload']['aps']['alert']['body']);
    }

    public function test_overdue_lead_without_a_manager_is_skipped_without_an_info_log(): void
    {
        $branch = Branch::create(['name' => 'Central']);
        $agentRole = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $agent = $this->createUser($agentRole, $branch, 'Agent');
        $lead = Lead::create([
            'full_name' => 'Unassigned lead',
            'branch_id' => $branch->id,
            'responsible_agent_id' => $agent->id,
            'status' => Lead::STATUS_NEW,
            'first_contact_due_at' => now()->subMinute(),
        ]);

        Log::spy();

        $method = new \ReflectionMethod(NotificationService::class, 'dispatchLeadSlaOverdue');
        $method->invoke(app(NotificationService::class));

        $this->assertDatabaseMissing('notifications', [
            'subject_type' => $lead->getMorphClass(),
            'subject_id' => $lead->id,
        ]);
        Log::shouldNotHaveReceived('info');
    }

    public function test_overdue_lead_with_an_active_branch_manager_is_not_skipped(): void
    {
        Queue::fake();
        $branch = Branch::create(['name' => 'Central']);
        $managerRole = Role::create(['name' => 'Manager', 'slug' => 'manager']);
        $manager = $this->createUser($managerRole, $branch, 'Manager');
        $lead = Lead::create([
            'full_name' => 'Overdue lead',
            'branch_id' => $branch->id,
            'status' => Lead::STATUS_NEW,
            'first_contact_due_at' => now()->subMinute(),
        ]);

        $method = new \ReflectionMethod(NotificationService::class, 'dispatchLeadSlaOverdue');
        $method->invoke(app(NotificationService::class));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $manager->id,
            'type' => NotificationType::LEAD_SLA_OVERDUE,
            'subject_type' => $lead->getMorphClass(),
            'subject_id' => $lead->id,
        ]);
        Queue::assertPushed(SendFirebasePushNotification::class);
    }

    public function test_custom_notification_subscriptions_obey_branch_status_and_revocation(): void
    {
        Queue::fake();
        $branch = Branch::create(['name' => 'Central']);
        $otherBranch = Branch::create(['name' => 'Other']);
        $role = Role::create(['name' => 'Custom subscriber', 'slug' => 'custom_subscriber']);
        $local = $this->createUser($role, $branch, 'Local');
        $foreign = $this->createUser($role, $otherBranch, 'Foreign');
        $inactive = $this->createUser($role, $branch, 'Inactive');
        $inactive->update(['status' => 'inactive']);
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $resolver = app(\App\Services\NotificationRecipientResolver::class);
        $lead = Lead::create(['full_name' => 'New lead', 'branch_id' => $branch->id,
            'status' => Lead::STATUS_NEW, 'first_contact_due_at' => now()->subMinute()]);
        foreach (['notifications.receive.leads', 'notifications.receive.public_leads.branch',
            'notifications.receive.public_leads.all', 'notifications.receive.property_control.security'] as $permission) {
            $role->forceFill(['permissions' => [$permission]])->save();
            $users = match ($permission) {
                'notifications.receive.leads' => $resolver->leadManagers($lead->fresh()),
                'notifications.receive.property_control.security' => $resolver->securityOfficers(),
                default => $resolver->publicLeadSubscribers($lead->fresh()),
            };
            $this->assertContains($local->id, $users->pluck('id')->all());
            $this->assertNotContains($inactive->id, $users->pluck('id')->all());
            $this->assertSame(in_array($permission, ['notifications.receive.public_leads.all', 'notifications.receive.property_control.security']), $users->contains('id', $foreign->id));
        }
        $deal = (new Deal)->forceFill(['branch_id' => $branch->id]);
        $deal->setRelation('primaryProperty', null)->setRelation('responsibleAgent', null);
        $property = (new \App\Models\Property)->forceFill(['branch_id' => $branch->id]);
        $property->setRelation('agent', null)->setRelation('creator', null);
        foreach (['notifications.receive.property_control.branch', 'notifications.receive.property_control.escalation',
            'notifications.receive.properties.escalation', 'notifications.receive.attendance_offline'] as $permission) {
            $role->forceFill(['permissions' => [$permission]])->save();
            $recipients = fn () => match ($permission) {
                'notifications.receive.property_control.branch' => $resolver->propertyControlBranchRecipients($deal),
                'notifications.receive.property_control.escalation' => $resolver->propertyControlEscalationRecipients($deal),
                'notifications.receive.properties.escalation' => $resolver->propertyEscalationRecipients($property),
                default => $resolver->usersByPermission($permission),
            };
            $users = $recipients();
            $this->assertTrue($users->contains('id', $local->id));
            $this->assertFalse($users->contains('id', $inactive->id));
            $this->assertSame(in_array($permission, ['notifications.receive.property_control.escalation', 'notifications.receive.attendance_offline']), $users->contains('id', $foreign->id));
            $role->forceFill(['permissions' => []])->save();
            $this->assertCount(0, $recipients());
        }
        $role->forceFill(['permissions' => ['notifications.receive.leads']])->save();
        $method = new \ReflectionMethod(NotificationService::class, 'dispatchLeadSlaOverdue');
        $method->invoke(app(NotificationService::class));
        $this->assertDatabaseHas('notifications', ['user_id' => $local->id, 'type' => NotificationType::LEAD_SLA_OVERDUE, 'subject_id' => $lead->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $foreign->id, 'type' => NotificationType::LEAD_SLA_OVERDUE, 'subject_id' => $lead->id]);
        $role->forceFill(['permissions' => []])->save();
        $this->assertCount(0, $resolver->leadManagers($lead->fresh()));
        $this->assertCount(0, $resolver->securityOfficers());
        $this->assertCount(0, $resolver->publicLeadSubscribers($lead->fresh()));
        $second = Lead::create(['full_name' => 'After revoke', 'branch_id' => $branch->id,
            'status' => Lead::STATUS_NEW, 'first_contact_due_at' => now()->subMinute()]);
        $method->invoke(app(NotificationService::class));
        $this->assertDatabaseMissing('notifications', ['user_id' => $local->id, 'subject_id' => $second->id]);
    }

    public function test_digest_subscriptions_work_for_custom_roles_and_stop_on_revocation(): void
    {
        Queue::fake();
        Schema::create('bookings', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('agent_id'); $table->timestamp('start_time');
        });
        Schema::create('crm_deals', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('responsible_agent_id');
            $table->timestamp('closed_at')->nullable(); $table->string('lost_reason')->nullable(); $table->softDeletes();
        });
        $branch = Branch::create(['name' => 'Central']);
        $role = Role::create(['name' => 'Subscriber', 'slug' => 'custom_digest']);
        $user = $this->createUser($role, $branch, 'Subscriber');
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        foreach ([
            ['dispatchManagerMorningDigest', 'digest.leads.morning', 8],
            ['dispatchManagerEveningDigest', 'digest.leads.evening', 18],
            ['dispatchAgentDayPlan', 'digest.deals.morning', 8],
            ['dispatchAgentEveningDigest', 'digest.deals.evening', 18],
        ] as [$methodName, $key, $hour]) {
            $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-10')->setHour($hour));
            $method = new \ReflectionMethod(NotificationService::class, $methodName);
            $role->forceFill(['permissions' => ['notifications.receive.'.$key]])->save();
            $this->assertSame(1, $method->invoke(app(NotificationService::class)));
            $role->forceFill(['permissions' => []])->save();
            $this->assertSame(0, $method->invoke(app(NotificationService::class)));
        }
        $this->assertSame(4, Notification::query()->where('user_id', $user->id)->count());
        $this->travelBack();
    }

    public function test_queued_digest_rechecks_subscription_without_removing_saved_history(): void
    {
        $branch = Branch::create(['name' => 'Central']);
        $role = Role::create(['name' => 'Subscriber', 'slug' => 'custom_delivery']);
        $user = $this->createUser($role, $branch, 'Subscriber');
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $permission = 'notifications.receive.digest.leads.morning';
        $role->forceFill(['permissions' => [$permission]])->save();
        $notification = Notification::create(['user_id' => $user->id,
            'type' => NotificationType::MOTIVATION_MANAGER_MORNING_DIGEST,
            'category' => NotificationCategory::MOTIVATION, 'status' => NotificationStatus::UNREAD,
            'priority' => 1, 'channels' => ['push'], 'title' => 'Digest', 'body' => 'Private digest',
            'dedupe_key' => 'queued-rbac', 'occurrences_count' => 1, 'data' => []]);
        $job = new SendFirebasePushNotification($notification->id);
        $firebase = \Mockery::mock(\App\Services\FirebasePushService::class);
        $firebase->shouldReceive('send')->once()->with(\Mockery::on(fn ($item) => $item->id === $notification->id));
        $role->forceFill(['permissions' => []])->save();
        $job->handle($firebase);
        $firebase->shouldNotHaveReceived('send');
        $this->assertTrue(app(\App\Services\GroupAccess\NotificationGroupAccess::class)->allows($notification, $user->fresh()));
        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
        $role->forceFill(['permissions' => [$permission]])->save();
        $job->handle($firebase);
        $user->update(['status' => 'inactive']);
        $job->handle($firebase);
    }

    public function test_mixed_delivery_revokes_role_subscription_but_keeps_personal_assignment(): void
    {
        Queue::fake();
        $branch = Branch::create(['name' => 'Central']);
        $role = Role::create(['name' => 'Subscriber', 'slug' => 'mixed_subscriber']);
        $observer = $this->createUser($role, $branch, 'Observer');
        $assignee = $this->createUser($role, $branch, 'Assignee');
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        $role->forceFill(['permissions' => ['notifications.receive.leads']])->save();
        $lead = Lead::create(['full_name' => 'Assigned lead', 'branch_id' => $branch->id,
            'responsible_agent_id' => $assignee->id, 'status' => Lead::STATUS_NEW]);
        app(NotificationService::class)->handleLeadCreated($lead);
        $broadcast = Notification::where('user_id', $observer->id)->where('type', NotificationType::LEAD_NEW)->firstOrFail();
        $personal = Notification::where('user_id', $assignee->id)->where('type', NotificationType::LEAD_ASSIGNED)->firstOrFail();
        $policy = app(\App\Services\NotificationDeliveryAccess::class);
        $this->assertSame('lead_managers', $broadcast->data['delivery_audience']);
        $this->assertTrue($policy->allows($broadcast, $observer->fresh()));
        $role->forceFill(['permissions' => []])->save();
        $firebase = \Mockery::mock(\App\Services\FirebasePushService::class);
        $firebase->shouldReceive('send')->once()->with(\Mockery::on(fn ($item) => $item->id === $personal->id));
        (new SendFirebasePushNotification($broadcast->id))->handle($firebase);
        $firebase->shouldNotHaveReceived('send');
        (new SendFirebasePushNotification($personal->id))->handle($firebase);
        $this->assertFalse($policy->allows($broadcast, $observer->fresh()));
        $public = (new Notification)->forceFill(['type' => NotificationType::LEAD_NEW, 'data' => ['delivery_audience' => 'public_lead']]);
        $public->setRelation('subject', $lead->fresh());
        $this->assertTrue($policy->allows($public, $assignee->fresh()));
        $this->assertFalse($policy->allows($public, $observer->fresh()));
        $role->forceFill(['permissions' => ['notifications.receive.leads']])->save();
        $otherBranch = Branch::create(['name' => 'Other']);
        $observer->update(['branch_id' => $otherBranch->id]);
        $this->assertFalse($policy->allows($broadcast, $observer->fresh()));
    }

    public function test_kpi_risk_subject_policy_supports_custom_roles_and_revocation(): void
    {
        Queue::fake();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-09 18:31:00'));
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->date('report_date');
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->string('role_slug')->nullable(); $table->timestamps();
        });
        $branch = Branch::create(['name' => 'Local']);
        $other = Branch::create(['name' => 'Other']);
        $subjectRole = Role::create(['name' => 'Custom subject', 'slug' => 'custom_risk_subject']);
        $receiverRole = Role::create(['name' => 'Director', 'slug' => 'branch_director']);
        $subject = $this->createUser($subjectRole, $branch, 'Subject');
        $receiver = $this->createUser($receiverRole, $branch, 'Receiver');
        $this->createUser($receiverRole, $other, 'Foreign receiver');
        (require database_path('migrations/2026_10_10_000000_add_role_permissions.php'))->up();
        foreach (['2026-10-08', '2026-10-09'] as $date) {
            DB::table('daily_reports')->insert(['user_id' => $subject->id, 'report_date' => $date]);
        }
        $service = app(NotificationService::class);
        $dispatch = new \ReflectionMethod($service, 'dispatchKpiEarlyRisk');
        $this->assertSame(0, $dispatch->invoke($service));
        $subjectRole->forceFill(['permissions' => ['kpi.subject.early_risk']])->save();
        $this->assertSame(1, $dispatch->invoke($service));
        $this->assertSame([$receiver->id], Notification::query()->pluck('user_id')->all());
        $subjectRole->forceFill(['permissions' => []])->save();
        $this->assertSame(0, $dispatch->invoke($service));
        $this->assertSame(1, Notification::count());
        $this->assertSame(2, DB::table('daily_reports')->count());
        foreach (['agent', 'mop', 'intern'] as $slug) {
            $role = new Role(['slug' => $slug]);
            $this->assertSame($slug !== 'intern', $role->hasPermission('kpi.subject.early_risk'));
        }
        $this->travelBack();
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
