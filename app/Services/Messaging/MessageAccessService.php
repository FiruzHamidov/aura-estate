<?php

namespace App\Services\Messaging;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class MessageAccessService
{
    public function canJoinGroup(?User $user): bool
    {
        return $user?->hasPermission('messaging.groups.join') ?? false;
    }

    public function canCreateDirectConversation(User $actor, User $target): bool
    {
        if ((int) $actor->id === (int) $target->id
            || ! $this->isActiveVisibleUser($actor)
            || ! $this->isActiveVisibleUser($target)) {
            return false;
        }

        if ($actor->hasPermission('messaging.personal.to_agents') && $target->hasPermission('messaging.personal.to_clients')) {
            return true;
        }

        if ($actor->hasPermission('messaging.personal.to_clients') && $target->hasPermission('messaging.personal.to_agents')) {
            return true;
        }

        return $this->isPersonalStaffUser($actor)
            && $this->isPersonalStaffUser($target)
            && $this->sharesPersonalMessagingScope($actor, $target);
    }

    public function eligibleDirectUsers(User $actor, bool $directoryOnly = false): Builder
    {
        $query = User::query()
            ->with('role')
            ->whereKeyNot($actor->id)
            ->where('status', User::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->whereNull('deletion_requested_at');

        $staffRoles = $this->roleIdsWithPermission('messaging.personal.staff');
        $globalRoles = $this->roleIdsWithPermission('messaging.personal.global');
        $agentRoles = $this->roleIdsWithPermission('messaging.personal.to_clients');
        $clientRoles = $this->roleIdsWithPermission('messaging.personal.to_agents');

        return $query->where(function (Builder $eligible) use ($actor, $staffRoles, $globalRoles, $agentRoles, $clientRoles, $directoryOnly) {
            $eligible->whereRaw('1 = 0');
            if ($this->isPersonalStaffUser($actor)) {
                $eligible->orWhere(function (Builder $staff) use ($actor, $staffRoles, $globalRoles) {
                    $staff->whereIn('role_id', $staffRoles);
                    if (! $this->isGlobalPersonalMessagingUser($actor)) {
                        $staff->where(function (Builder $scope) use ($actor, $globalRoles) {
                            $scope->where('branch_id', $actor->branch_id)->whereNotNull('branch_id')
                                ->orWhereIn('role_id', $globalRoles);
                        });
                    }
                });
            }
            if ($actor->hasPermission('messaging.personal.to_agents')) $eligible->orWhereIn('role_id', $agentRoles);
            if (! $directoryOnly && $actor->hasPermission('messaging.personal.to_clients')) $eligible->orWhereIn('role_id', $clientRoles);
        });
    }

    public function roleIdsWithPermission(string $permission): array
    {
        return Role::query()->get()->filter(fn (Role $role) => $role->hasPermission($permission))->modelKeys();
    }

    public function canCreateGroupConversation(User $actor, array $participants): bool
    {
        if (! $this->canJoinGroup($actor) || ! $actor->hasPermission('messaging.groups.create')) {
            return false;
        }

        foreach ($participants as $participant) {
            if (! $participant instanceof User || ! $this->canJoinGroup($participant)) {
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
        if (! $this->canAccessConversation($actor, $conversation)) {
            return false;
        }

        if ($conversation->type !== Conversation::TYPE_DIRECT) {
            return true;
        }

        $counterpart = $conversation->participants()
            ->where('user_id', '!=', $actor->id)
            ->with('user.role')
            ->first()
            ?->user;

        return $counterpart && $this->canCreateDirectConversation($actor, $counterpart);
    }

    public function canManageParticipants(User $actor, Conversation $conversation): bool
    {
        if ($conversation->type !== Conversation::TYPE_GROUP) {
            return false;
        }

        if ($actor->hasPermission('messaging.groups.manage_all')) {
            return true;
        }

        $participant = $conversation->participants()
            ->where('user_id', $actor->id)
            ->first();

        return $actor->hasPermission('messaging.groups.manage_own') && $participant
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

        return $this->canJoinGroup($target)
            && $this->isActiveVisibleUser($target)
            && ($this->isGlobalPersonalMessagingUser($actor)
                || $this->isGlobalPersonalMessagingUser($target)
                || ($actor->branch_id && (int) $actor->branch_id === (int) $target->branch_id));
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
            ->whereIn('role_id', $this->roleIdsWithPermission('messaging.support.receive'))
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

    public function unreadMessageCount(User $user): int
    {
        return ConversationMessage::query()
            ->join('conversation_participants as viewer_participants', function ($join) {
                $join->on(
                    'viewer_participants.conversation_id',
                    '=',
                    'conversation_messages.conversation_id'
                );
            })
            ->where('viewer_participants.user_id', $user->id)
            ->whereRaw(
                'conversation_messages.id > COALESCE(viewer_participants.last_read_message_id, 0)'
            )
            ->where(function (Builder $messages) use ($user) {
                $messages->whereNull('conversation_messages.author_id')
                    ->orWhere('conversation_messages.author_id', '!=', $user->id);
            })
            ->count();
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

    private function isPersonalStaffUser(?User $user): bool
    {
        return $user?->hasPermission('messaging.personal.staff') ?? false;
    }

    private function isGlobalPersonalMessagingUser(?User $user): bool
    {
        return $user?->hasPermission('messaging.personal.global') ?? false;
    }

    private function isActiveVisibleUser(?User $user): bool
    {
        return $user
            && $user->status === User::STATUS_ACTIVE
            && ! $user->isDeletedAccount();
    }

    private function sharesPersonalMessagingScope(User $actor, User $target): bool
    {
        if ($this->isGlobalPersonalMessagingUser($actor) || $this->isGlobalPersonalMessagingUser($target)) {
            return true;
        }

        return $actor->branch_id !== null
            && $target->branch_id !== null
            && (int) $actor->branch_id === (int) $target->branch_id;
    }
}
