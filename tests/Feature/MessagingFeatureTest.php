<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\Conversation;
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
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('auth_method', ['password', 'sms'])->default('password');
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

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('name')->nullable();
            $table->string('direct_key')->nullable()->unique();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
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
            $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
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

        $denied->assertForbidden();
    }

    public function test_client_cannot_create_arbitrary_group_while_internal_user_can(): void
    {
        $client = $this->createUser('client', 'Client', '991000011');
        $agent = $this->createUser('agent', 'Agent', '991000012');
        $operator = $this->createUser('operator', 'Operator', '991000013');

        Sanctum::actingAs($client);

        $clientAttempt = $this->postJson('/api/conversations', [
            'name' => 'Client Group',
            'participant_ids' => [$agent->id],
        ]);

        $clientAttempt->assertForbidden();

        Sanctum::actingAs($operator);

        $internalAttempt = $this->postJson('/api/conversations', [
            'name' => 'Ops Group',
            'participant_ids' => [$agent->id],
        ]);

        $internalAttempt->assertCreated();
        $internalAttempt->assertJsonPath('type', Conversation::TYPE_GROUP);
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
            'chat_session_id' => $session->session_uuid,
            'summary' => 'Нужен живой менеджер',
        ]);

        $create->assertCreated();
        $conversationId = $create->json('conversation.id');

        $thread = SupportThread::query()->where('conversation_id', $conversationId)->first();
        $this->assertNotNull($thread);
        $this->assertSame($session->id, $thread->chat_session_id);

        Sanctum::actingAs($manager);
        $managerReply = $this->postJson('/api/conversations/'.$conversationId.'/messages', [
            'body' => 'Менеджер на связи',
        ]);
        $managerReply->assertCreated();

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

    private function seedInternalPair(string $firstRole, string $secondRole): array
    {
        return [
            $this->createUser($firstRole, ucfirst($firstRole), '990'.random_int(100000, 999999)),
            $this->createUser($secondRole, ucfirst($secondRole), '991'.random_int(100000, 999999)),
        ];
    }

    private function createUser(string $roleSlug, string $name, string $phone): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => $roleSlug],
            ['name' => ucfirst($roleSlug)]
        );

        return User::query()->create([
            'name' => $name,
            'phone' => $phone,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }
}
