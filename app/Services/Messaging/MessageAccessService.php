<?php

namespace App\Services\Messaging;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\SupportThread;
use App\Models\User;

class MessageAccessService
{
    private const INTERNAL_ROLE_SLUGS = [
        'admin',
        'superadmin',
        'marketing',
        'rop',
        'branch_director',
        'manager',
        'operator',
        'agent',
        'reels_manager',
    ];

    private const SUPPORT_ROLE_SLUGS = [
        'admin',
        'superadmin',
        'manager',
        'operator',
    ];

    public function roleSlug(?User $user): ?string
    {
        $user?->loadMissing('role');

        return $user?->role?->slug;
    }

    public function isInternalUser(?User $user): bool
    {
        return in_array($this->roleSlug($user), self::INTERNAL_ROLE_SLUGS, true);
    }

    public function isClient(?User $user): bool
    {
        return $this->roleSlug($user) === 'client';
    }

    public function isAgent(?User $user): bool
    {
        return $this->roleSlug($user) === 'agent';
    }

    public function isSupportStaff(?User $user): bool
    {
        return in_array($this->roleSlug($user), self::SUPPORT_ROLE_SLUGS, true);
    }

    public function canCreateDirectConversation(User $actor, User $target): bool
    {
        if ((int) $actor->id === (int) $target->id) {
            return false;
        }

        if ($this->isInternalUser($actor) && $this->isInternalUser($target)) {
            return true;
        }

        if ($this->isClient($actor) && $this->isAgent($target)) {
            return true;
        }

        if ($this->isAgent($actor) && $this->isClient($target)) {
            return true;
        }

        return false;
    }

    public function canCreateGroupConversation(User $actor, array $participants): bool
    {
        if (! $this->isInternalUser($actor)) {
            return false;
        }

        foreach ($participants as $participant) {
            if (! $participant instanceof User || ! $this->isInternalUser($participant)) {
                return false;
            }
        }

        return true;
    }

    public function canAccessConversation(User $actor, Conversation $conversation): bool
    {
        return $conversation->participants()
            ->where('user_id', $actor->id)
            ->exists();
    }

    public function canSendMessage(User $actor, Conversation $conversation): bool
    {
        return $this->canAccessConversation($actor, $conversation);
    }

    public function canManageParticipants(User $actor, Conversation $conversation): bool
    {
        if ($conversation->type !== Conversation::TYPE_GROUP) {
            return false;
        }

        if ($this->isSupportStaff($actor) || in_array($this->roleSlug($actor), ['admin', 'superadmin'], true)) {
            return true;
        }

        $participant = $conversation->participants()
            ->where('user_id', $actor->id)
            ->first();

        return $participant
            && in_array($participant->role, [ConversationParticipant::ROLE_OWNER, ConversationParticipant::ROLE_ADMIN], true);
    }

    public function canAddParticipantToConversation(User $actor, Conversation $conversation, User $target): bool
    {
        if (! $this->canManageParticipants($actor, $conversation)) {
            return false;
        }

        if ($conversation->type !== Conversation::TYPE_GROUP) {
            return false;
        }

        return $this->isInternalUser($target);
    }

    public function canRemoveParticipantFromConversation(User $actor, Conversation $conversation, User $target): bool
    {
        if (! $this->canManageParticipants($actor, $conversation)) {
            return false;
        }

        $participant = $conversation->participants()
            ->where('user_id', $target->id)
            ->first();

        return $participant?->role !== ConversationParticipant::ROLE_OWNER;
    }

    public function canCreateSupportConversation(User $actor): bool
    {
        return $actor->status === User::STATUS_ACTIVE;
    }

    public function supportAssignableUsers()
    {
        return User::query()
            ->whereHas('role', fn ($query) => $query->whereIn('slug', self::SUPPORT_ROLE_SLUGS))
            ->where(function ($query) {
                $query->where('status', User::STATUS_ACTIVE)
                    ->orWhereNull('status');
            });
    }

    public function touchParticipantReadState(Conversation $conversation, User $user, ?ConversationMessage $message = null): void
    {
        $conversation->participants()
            ->where('user_id', $user->id)
            ->update([
                'last_read_message_id' => $message?->id,
                'last_read_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function ensureAccessible(User $actor, Conversation $conversation): void
    {
        abort_unless($this->canAccessConversation($actor, $conversation), 403, 'Forbidden');
    }

    public function ensureCanSend(User $actor, Conversation $conversation): void
    {
        abort_unless($this->canSendMessage($actor, $conversation), 403, 'Forbidden');
    }

    public function ensureCanCreateDirect(User $actor, User $target): void
    {
        abort_unless($this->canCreateDirectConversation($actor, $target), 403, 'Forbidden');
    }

    public function ensureCanCreateGroup(User $actor, array $participants): void
    {
        abort_unless($this->canCreateGroupConversation($actor, $participants), 403, 'Forbidden');
    }

    public function ensureCanManageParticipants(User $actor, Conversation $conversation): void
    {
        abort_unless($this->canManageParticipants($actor, $conversation), 403, 'Forbidden');
    }

    public function ensureCanAddParticipant(User $actor, Conversation $conversation, User $target): void
    {
        abort_unless($this->canAddParticipantToConversation($actor, $conversation, $target), 403, 'Forbidden');
    }

    public function ensureCanRemoveParticipant(User $actor, Conversation $conversation, User $target): void
    {
        abort_unless($this->canRemoveParticipantFromConversation($actor, $conversation, $target), 403, 'Forbidden');
    }

    public function ensureCanCreateSupportConversation(User $actor): void
    {
        abort_unless($this->canCreateSupportConversation($actor), 403, 'Forbidden');
    }
}
