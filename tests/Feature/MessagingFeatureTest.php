<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\Conversation;
use App\Models\GuestSupportSession;
use App\Models\Role;
use App\Models\SupportThread;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagingFeatureTest extends TestCase
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
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('auth_method', ['password', 'sms'])->default('password');
            $table->rememberToken()->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('deletion_requested_at')->nullable();
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

        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('language', 10)->nullable();
            $table->timestamp('last_user_message_at')->nullable();
            $table->timestamp('last_assistant_message_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('guest_support_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('name')->nullable();
            $table->string('direct_key')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('guest_session_id')->nullable()->constrained('guest_support_sessions')->nullOnDelete();
            $table->string('type', 32)->default('text');
            $table->longText('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32)->default('member');
            $table->foreignId('last_read_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });

        Schema::create('support_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete()->unique();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('guest_session_id')->nullable()->constrained('guest_support_sessions')->cascadeOnDelete();
            $table->foreignId('chat_session_id')->nullable()->constrained('chat_sessions')->nullOnDelete();
            $table->foreignId('escalated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('open');
            $table->text('summary')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function test_internal_user_can_create_direct_conversation_and_it_is_reused(): void
    {
        [$admin, $manager] = $this->seedInternalPair('admin', 'manager');

        Sanctum::actingAs($admin);

        $first = $this->postJson('/api/conversations/direct', [
            'target_user_id' => $manager->id,
        ]);

        $first->assertOk();
        $first->assertJsonPath('type', Conversation::TYPE_DIRECT);
        $conversationId = $first->json('id');

        $second = $this->postJson('/api/conversations/direct', [
            'target_user_id' => $manager->id,
        ]);

        $second->assertOk();
        $second->assertJsonPath('id', $conversationId);

        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_client_can_create_direct_conversation_with_agent_but_not_manager(): void
    {
        $client = $this->createUser('client', 'Client', '991000001');
        $agent = $this->createUser('agent', 'Agent', '991000002');
        $manager = $this->createUser('manager', 'Manager', '991000003');

        Sanctum::actingAs($client);

        $allowed = $this->postJson('/api/conversations/direct', [
            'target_user_id' => $agent->id,
        ]);

        $allowed->assertOk();
        $allowed->assertJsonPath('type', Conversation::TYPE_DIRECT);

        $denied = $this->postJson('/api/conversations/direct', [
            'target_user_id' => $manager->id,
        ]);

        $denied->assertNotFound();
    }

    public function test_non_internal_user_posting_conversation_with_one_participant_creates_direct_chat(): void
    {
        $client = $this->createUser('client', 'Client', '991000011');
        $agent = $this->createUser('agent', 'Agent', '991000012');
        $operator = $this->createUser('operator', 'Operator', '991000013');

        Sanctum::actingAs($client);

        $clientAttempt = $this->postJson('/api/conversations', [
            'name' => 'Client Group',
            'participant_ids' => [$agent->id],
        ]);

        $clientAttempt->assertOk();
        $clientAttempt->assertJsonPath('type', Conversation::TYPE_DIRECT);

        Sanctum::actingAs($operator);

        $internalAttempt = $this->postJson('/api/conversations', [
            'name' => 'Ops Group',
            'participant_ids' => [$agent->id],
        ]);

        $internalAttempt->assertCreated();
        $internalAttempt->assertJsonPath('type', Conversation::TYPE_GROUP);
        $internalAttempt->assertJsonPath('kind', Conversation::KIND_GROUP);
    }

    public function test_only_participants_can_access_conversation(): void
    {
        $agent = $this->createUser('agent', 'Agent', '991000021');
        $client = $this->createUser('client', 'Client', '991000022');
        $otherClient = $this->createUser('client', 'Other Client', '991000023');

        Sanctum::actingAs($agent);
        $create = $this->postJson('/api/conversations/direct', [
            'target_user_id' => $client->id,
        ]);

        $conversationId = $create->json('id');

        Sanctum::actingAs($client);
        $this->getJson('/api/conversations/'.$conversationId)->assertOk();

        Sanctum::actingAs($otherClient);
        $this->getJson('/api/conversations/'.$conversationId)->assertForbidden();
    }

    public function test_chat_endpoints_include_ios_header_and_message_state_fields(): void
    {
        $agent = $this->createUser('agent', 'Ситора Рахмонова', '991000024');
        $client = $this->createUser('client', 'Current User', '991000025');

        Sanctum::actingAs($agent);

        $create = $this->postJson('/api/conversations/direct', [
            'target_user_id' => $client->id,
        ]);

        $conversationId = $create->json('id');

        $show = $this->getJson('/api/conversations/'.$conversationId);

        $show->assertOk()
            ->assertJsonPath('name', 'Current User')
            ->assertJsonPath('type', Conversation::TYPE_DIRECT)
            ->assertJsonPath('unread_count', 0)
            ->assertJsonPath('participants.0.user.is_online', false)
            ->assertJsonPath('participants.0.user.status', 'offline')
            ->assertJsonPath('participants.0.user.last_seen_at', null);

        $message = $this->postJson('/api/conversations/'.$conversationId.'/messages', [
            'body' => 'Салом) Ман мехостам хона харам чи маслихат медихед',
        ]);

        $message->assertCreated()
            ->assertJsonPath('role', 'me')
            ->assertJsonPath('sender.id', $agent->id)
            ->assertJsonPath('sender.name', 'Ситора Рахмонова')
            ->assertJsonPath('sender.photo', null)
            ->assertJsonPath('delivery_status', 'sent');

        Sanctum::actingAs($client);

        $messages = $this->getJson('/api/conversations/'.$conversationId.'/messages?per_page=50');

        $messages->assertOk()
            ->assertJsonPath('data.0.role', 'agent')
            ->assertJsonPath('data.0.sender.id', $agent->id)
            ->assertJsonPath('data.0.delivery_status', 'delivered');

        $participants = $this->getJson('/api/conversations/'.$conversationId.'/participants');

        $participants->assertOk()
            ->assertJsonPath('0.id', 1)
            ->assertJsonPath('0.user.name', 'Ситора Рахмонова')
            ->assertJsonPath('0.user.photo', null)
            ->assertJsonPath('0.user.is_online', false);
    }

    public function test_support_conversation_can_be_created_from_client_flow_and_manager_operator_can_reply(): void
    {
        $client = $this->createUser('client', 'Client', '991000031');
        $manager = $this->createUser('manager', 'Manager', '991000032');
        $operator = $this->createUser('operator', 'Operator', '991000033');
        $otherClient = $this->createUser('client', 'Other Client', '991000034');

        $session = ChatSession::query()->create([
            'session_uuid' => 'support-session-1',
            'user_id' => $client->id,
            'language' => 'ru',
        ]);

        Sanctum::actingAs($client);

        $create = $this->postJson('/api/support/conversations', [
            'session_id' => $session->session_uuid,
            'title' => 'Вопрос по объекту',
            'initial_message' => 'Нужен живой менеджер',
            'source' => 'ai_chat',
        ]);

        $create->assertCreated()
            ->assertJsonPath('source', 'ai_chat')
            ->assertJsonPath('requester.kind', 'client')
            ->assertJsonPath('conversation.name', 'Вопрос по объекту')
            ->assertJsonPath('conversation.latest_message.body', 'Нужен живой менеджер')
            ->assertJsonPath('responsibility.queue', 'support')
            ->assertJsonPath('responsibility.response_required_from', 'support_staff');
        $conversationId = $create->json('conversation.id');

        $thread = SupportThread::query()->where('conversation_id', $conversationId)->first();
        $this->assertNotNull($thread);
        $this->assertSame($session->id, $thread->chat_session_id);

        Sanctum::actingAs($manager);
        $managerReply = $this->postJson('/api/conversations/'.$conversationId.'/messages', [
            'body' => 'Менеджер на связи',
        ]);
        $managerReply->assertCreated();

        $managerThread = $this->getJson('/api/support/conversations/'.$conversationId);
        $managerThread->assertOk()
            ->assertJsonPath('requester.kind', 'client')
            ->assertJsonPath('source', 'ai_chat')
            ->assertJsonPath('responsibility.response_required_from', 'requester');

        Sanctum::actingAs($operator);
        $operatorReply = $this->postJson('/api/conversations/'.$conversationId.'/messages', [
            'body' => 'Оператор подключился',
        ]);
        $operatorReply->assertCreated();

        Sanctum::actingAs($otherClient);
        $this->getJson('/api/support/conversations/'.$conversationId)->assertForbidden();
        $this->postJson('/api/conversations/'.$conversationId.'/messages', [
            'body' => 'Я тут чужой',
        ])->assertForbidden();
    }

    public function test_personal_conversations_support_cross_role_pairs_and_are_idempotent(): void
    {
        $pairs = [
            ['agent', 'agent', 1],
            ['agent', 'manager', 1],
            ['manager', 'operator', 1],
            ['mop', 'intern', 1],
            ['hr', 'accountant', null],
        ];

        foreach ($pairs as $index => [$leftRole, $rightRole, $branchId]) {
            $left = $this->createUser($leftRole, 'Left '.$index, '9921000'.$index.'1', ['branch_id' => $branchId]);
            $right = $this->createUser($rightRole, 'Right '.$index, '9921000'.$index.'2', ['branch_id' => $branchId]);

            Sanctum::actingAs($left);
            $first = $this->postJson('/api/conversations/direct', ['target_user_id' => $right->id]);
            $first->assertOk()
                ->assertJsonPath('type', Conversation::TYPE_DIRECT)
                ->assertJsonPath('kind', Conversation::KIND_PERSONAL)
                ->assertJsonPath('kind_label', 'Личный диалог')
                ->assertJsonPath('source', 'personal')
                ->assertJsonPath('context', null)
                ->assertJsonPath('counterpart.id', $right->id)
                ->assertJsonPath('counterpart.role_slug', $rightRole)
                ->assertJsonPath('participants.1.user.display_role', ucfirst($rightRole))
                ->assertJsonMissingPath('responsibility');

            Sanctum::actingAs($right);
            $second = $this->postJson('/api/conversations/direct', ['target_user_id' => $left->id]);
            $second->assertOk()->assertJsonPath('id', $first->json('id'));
        }

        $this->assertDatabaseCount('conversations', count($pairs));
    }

    public function test_personal_directory_and_creation_reject_self_inactive_hidden_and_foreign_scope_users(): void
    {
        $actor = $this->createUser('agent', 'Actor', '992200001', ['branch_id' => 1]);
        $visible = $this->createUser('manager', 'Visible Manager', '992200002', ['branch_id' => 1]);
        $global = $this->createUser('hr', 'Global HR', '992200003');
        $inactive = $this->createUser('operator', 'Inactive', '992200004', [
            'branch_id' => 1,
            'status' => User::STATUS_INACTIVE,
        ]);
        $foreign = $this->createUser('manager', 'Foreign Manager', '992200005', ['branch_id' => 2]);
        $hidden = $this->createUser('manager', 'Hidden Manager', '992200006', [
            'branch_id' => 1,
            'deleted_at' => now(),
        ]);
        $client = $this->createUser('client', 'Private Client', '992200007');

        Sanctum::actingAs($actor);

        $directory = $this->getJson('/api/conversations/available-users?per_page=50');
        $directory->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $visible->id, 'role_slug' => 'manager'])
            ->assertJsonFragment(['id' => $global->id, 'role_slug' => 'hr'])
            ->assertJsonMissing(['id' => $client->id, 'role_slug' => 'client'])
            ->assertJsonMissing(['phone' => $visible->phone])
            ->assertJsonMissing(['email' => $visible->email]);

        foreach ([$actor, $inactive, $foreign, $hidden] as $forbiddenTarget) {
            $this->postJson('/api/conversations/direct', ['target_user_id' => $forbiddenTarget->id])
                ->assertNotFound();
        }

        $conversation = $this->postJson('/api/conversations/direct', ['target_user_id' => $visible->id]);
        $conversation->assertOk();

        $this->postJson('/api/conversations/'.$conversation->json('id').'/participants', [
            'user_id' => $foreign->id,
            'role' => 'admin',
        ])->assertForbidden();

        $visible->update(['status' => User::STATUS_INACTIVE]);
        $this->postJson('/api/conversations/'.$conversation->json('id').'/messages', [
            'body' => 'Нельзя отправить деактивированному пользователю',
        ])->assertForbidden();
    }

    public function test_conversation_kind_filters_keep_personal_and_support_separate(): void
    {
        $agent = $this->createUser('agent', 'Agent', '992300001', ['branch_id' => 1]);
        $manager = $this->createUser('manager', 'Manager', '992300002', ['branch_id' => 1]);

        Sanctum::actingAs($agent);
        $personal = $this->postJson('/api/conversations/direct', ['target_user_id' => $manager->id]);
        $personal->assertOk()->assertJsonMissingPath('responsibility');

        $support = $this->postJson('/api/support/conversations', [
            'initial_message' => 'Нужна поддержка',
            'source' => 'support_form',
        ]);
        $support->assertCreated()
            ->assertJsonPath('kind', Conversation::KIND_SUPPORT)
            ->assertJsonPath('responsibility.queue', 'support');

        $this->getJson('/api/conversations?kind=all&per_page=50')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->getJson('/api/conversations?kind=personal&per_page=50')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $personal->json('id'))
            ->assertJsonPath('data.0.kind', Conversation::KIND_PERSONAL)
            ->assertJsonPath('data.0.support_thread', null)
            ->assertJsonMissingPath('data.0.responsibility');
        $this->getJson('/api/conversations?kind=support&per_page=50')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', Conversation::KIND_SUPPORT)
            ->assertJsonPath('data.0.responsibility.queue', 'support');
    }

    public function test_guest_can_create_read_continue_and_see_staff_reply_in_existing_messaging_domain(): void
    {
        config()->set('guest-support.cookie_secure', true);
        $manager = $this->createUser('manager', 'Manager', '991000041');
        $operator = $this->createUser('operator', 'Operator', '991000042');

        $create = $this->withHeader('Origin', 'https://aura.tj')->postJson('/api/guest-support/conversations', [
            'title' => 'Вопрос с сайта',
            'initial_message' => 'Помогите подобрать квартиру',
            'source' => 'website',
            'context' => ['page_path' => '/buy'],
        ]);

        $create->assertCreated()
            ->assertJsonPath('kind', Conversation::KIND_SUPPORT)
            ->assertJsonPath('requester.kind', 'guest')
            ->assertJsonPath('source', 'website')
            ->assertJsonPath('conversation.latest_message.role', 'me')
            ->assertJsonPath('conversation.latest_message.sender_identity.kind', 'guest')
            ->assertJsonPath('responsibility.response_required_from', 'support_staff');

        $cookie = collect($create->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === config('guest-support.cookie'));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertSame('/api/guest-support', $cookie->getPath());

        $conversationId = $create->json('conversation.id');
        $guestCookie = $cookie->getValue();

        $this->withCredentials()->withUnencryptedCookie(config('guest-support.cookie'), $guestCookie)
            ->getJson('/api/guest-support/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.conversation.id', $conversationId);

        $this->withCredentials()->withUnencryptedCookie(config('guest-support.cookie'), $guestCookie)
            ->postJson('/api/guest-support/conversations/'.$conversationId.'/messages', [
                'body' => 'Нужна двухкомнатная квартира',
            ])
            ->assertCreated()
            ->assertJsonPath('role', 'me')
            ->assertJsonPath('sender_identity.kind', 'guest');

        Sanctum::actingAs($manager);
        $this->getJson('/api/conversations?type=support')
            ->assertOk()
            ->assertJsonPath('data.0.support_thread.requester.kind', 'guest')
            ->assertJsonPath('data.0.support_thread.source', 'website')
            ->assertJsonPath('data.0.support_thread.responsibility.response_required_from', 'support_staff')
            ->assertJsonPath('data.0.latest_message.sender_identity.kind', 'guest');

        $this->postJson('/api/conversations/'.$conversationId.'/messages', [
            'body' => 'Менеджер подключился',
        ])->assertCreated()
            ->assertJsonPath('sender_identity.kind', 'support_staff');

        auth()->forgetGuards();

        $this->withCredentials()->withUnencryptedCookie(config('guest-support.cookie'), $guestCookie)
            ->getJson('/api/guest-support/conversations/'.$conversationId.'/messages')
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Менеджер подключился')
            ->assertJsonPath('data.0.role', 'manager')
            ->assertJsonPath('data.0.sender_identity.kind', 'support_staff')
            ->assertJsonPath('data.1.sender_identity.kind', 'guest');

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $manager->id,
        ]);
        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $operator->id,
        ]);
    }

    public function test_each_guest_can_only_access_their_own_conversations(): void
    {
        $this->createUser('manager', 'Manager', '991000051');

        $first = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson('/api/guest-support/conversations', ['initial_message' => 'Первое обращение']);
        $first->assertCreated();

        $second = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/api/guest-support/conversations', ['initial_message' => 'Второе обращение']);
        $second->assertCreated();

        $conversationId = $first->json('conversation.id');
        $secondCookie = collect($second->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('guest-support.cookie'))
            ?->getValue();
        $this->assertNotNull($secondCookie);

        $this->withCredentials()->withUnencryptedCookie(config('guest-support.cookie'), $secondCookie)
            ->getJson('/api/guest-support/conversations/'.$conversationId)
            ->assertNotFound();
        $this->withCredentials()->withUnencryptedCookie(config('guest-support.cookie'), $secondCookie)
            ->getJson('/api/guest-support/conversations/'.$conversationId.'/messages')
            ->assertNotFound();
        $this->withCredentials()->withUnencryptedCookie(config('guest-support.cookie'), $secondCookie)
            ->postJson('/api/guest-support/conversations/'.$conversationId.'/messages', ['body' => 'Подмена'])
            ->assertNotFound();
    }

    public function test_guest_identity_spoofing_validation_origin_and_rate_limits_are_enforced(): void
    {
        $this->createUser('manager', 'Manager', '991000061');

        $this->withHeader('Origin', 'https://evil.example')
            ->postJson('/api/guest-support/conversations', ['initial_message' => 'Запрещённый origin'])
            ->assertForbidden();
        $this->flushHeaders();

        $this->postJson('/api/guest-support/conversations', ['initial_message' => 'x'])
            ->assertUnprocessable();

        $this->postJson('/api/guest-support/conversations', [
            'initial_message' => 'Попытка подделки',
            'user_id' => 1,
            'role' => 'manager',
            'sender_identity' => ['kind' => 'support_staff'],
        ])->assertUnprocessable();

        $create = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.63'])
            ->postJson('/api/guest-support/conversations', ['initial_message' => 'Корректное обращение']);
        $create->assertCreated();

        config()->set('guest-support.message_rate_per_minute', 3);
        $cookie = collect($create->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === config('guest-support.cookie'));
        $guestCookie = $cookie?->getValue();
        $conversationId = $create->json('conversation.id');

        $this->withCredentials()->withUnencryptedCookie(config('guest-support.cookie'), $guestCookie)
            ->postJson('/api/guest-support/conversations/'.$conversationId.'/messages', [
                'body' => 'Попытка подделки продолжения',
                'author_id' => 1,
                'role' => 'manager',
            ])
            ->assertUnprocessable();

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->withCredentials()->withUnencryptedCookie(config('guest-support.cookie'), $guestCookie)
                ->postJson('/api/guest-support/conversations/'.$conversationId.'/messages', [
                    'body' => 'Сообщение '.$attempt,
                ])
                ->assertCreated();
        }

        $this->withCredentials()->withUnencryptedCookie(config('guest-support.cookie'), $guestCookie)
            ->postJson('/api/guest-support/conversations/'.$conversationId.'/messages', ['body' => 'Спам'])
            ->assertTooManyRequests();

        $this->assertSame(1, GuestSupportSession::query()->count());
    }

    private function seedInternalPair(string $firstRole, string $secondRole): array
    {
        return [
            $this->createUser($firstRole, ucfirst($firstRole), '990'.random_int(100000, 999999)),
            $this->createUser($secondRole, ucfirst($secondRole), '991'.random_int(100000, 999999)),
        ];
    }

    private function createUser(string $roleSlug, string $name, string $phone, array $attributes = []): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => $roleSlug],
            ['name' => ucfirst($roleSlug)]
        );

        return User::query()->create(array_merge([
            'name' => $name,
            'phone' => $phone,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ], $attributes));
    }
}
