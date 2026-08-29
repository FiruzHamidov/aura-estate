<?php

namespace Tests\Feature;

use App\Services\Chat\ChatService;
use Mockery\MockInterface;
use Tests\TestCase;

class ChatIdentityFeatureTest extends TestCase
{
    public function test_anonymous_chat_cannot_claim_another_user_identity(): void
    {
        $this->mock(ChatService::class, function (MockInterface $mock) {
            $mock->shouldReceive('reply')
                ->once()
                ->with('Здравствуйте', 'guest-session', null, ['page_path' => '/buy'])
                ->andReturn([
                    'session_id' => 'guest-session',
                    'answer' => 'Здравствуйте!',
                    'items' => [],
                ]);
        });

        $this->postJson('/api/chat', [
            'message' => 'Здравствуйте',
            'session_id' => 'guest-session',
            'user_id' => 999,
            'context' => ['page_path' => '/buy'],
        ])->assertOk()
            ->assertJsonPath('session_id', 'guest-session');
    }
}
