<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly array $payload,
        public readonly ?int $userId = null,
        public readonly ?string $guestThreadPublicId = null,
    ) {}

    public function broadcastOn(): array
    {
        if ($this->userId !== null) {
            return [new PrivateChannel('messaging.user.'.$this->userId)];
        }

        return [new PrivateChannel('guest-support.conversation.'.$this->guestThreadPublicId)];
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
