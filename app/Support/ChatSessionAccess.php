<?php

namespace App\Support;

use App\Models\ChatSession;
use App\Models\User;

final class ChatSessionAccess
{
    public static function ensureVisible(?User $actor, ?ChatSession $session): void
    {
        // Group supervision never grants participation in somebody else's chat.
        if ($session?->user_id || $actor?->hasRole('rop')) {
            abort_unless($session && $actor && (int) $session->user_id === (int) $actor->id, 404);
        }
    }
}
