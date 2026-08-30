<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationMessageCreated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly array $payload,
        public readonly ?string $guestThreadPublicId = null,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('messaging.conversation.'.$this->conversationId)];

        if ($this->guestThreadPublicId !== null) {
            $channels[] = new PrivateChannel('guest-support.conversation.'.$this->guestThreadPublicId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'conversation.message.created';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
