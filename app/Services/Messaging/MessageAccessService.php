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

    private const GLOBAL_PERSONAL_MESSAGING_ROLE_SLUGS = [
        'admin',
        'superadmin',
        'owner',
        'marketing',
        'hr',
        'accountant',
        'reels_manager',
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
        if ((int) $actor->id === (int) $target->id
            || ! $this->isActiveVisibleUser($actor)
            || ! $this->isActiveVisibleUser($target)) {
            return false;
        }

        if ($this->isClient($actor) && $this->isAgent($target)) {
            return true;
        }

        if ($this->isAgent($actor) && $this->isClient($target)) {
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

        if ($this->isClient($actor)) {
            return $query->whereHas('role', fn (Builder $roles) => $roles->where('slug', 'agent'));
        }

        if (! $this->isPersonalStaffUser($actor)) {
            return $query->whereRaw('1 = 0');
        }

        $staffRoles = $this->personalStaffRoleSlugs();
        $globalRoles = self::GLOBAL_PERSONAL_MESSAGING_ROLE_SLUGS;

        return $query->where(function (Builder $eligible) use ($actor, $staffRoles, $globalRoles, $directoryOnly) {
            $eligible->where(function (Builder $staff) use ($actor, $staffRoles, $globalRoles) {
                $staff->whereHas('role', fn (Builder $roles) => $roles->whereIn('slug', $staffRoles));

                if (! $this->isGlobalPersonalMessagingUser($actor)) {
                    $staff->where(function (Builder $scope) use ($actor, $globalRoles) {
                        $scope->where('branch_id', $actor->branch_id)
                            ->whereNotNull('branch_id')
                            ->orWhereHas('role', fn (Builder $roles) => $roles->whereIn('slug', $globalRoles));
                    });
                }
            });

            if ($this->isAgent($actor) && ! $directoryOnly) {
                $eligible->orWhereHas('role', fn (Builder $roles) => $roles->where('slug', 'client'));
            }
        });
    }

    public function personalStaffRoleSlugs(): array
    {
        return collect(Role::systemRoles())
            ->pluck('slug')
            ->filter(fn ($slug) => is_string($slug) && ! in_array($slug, ['client', 'external_agent'], true))
            ->push('owner')
            ->unique()
            ->values()
            ->all();
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

        return $this->isInternalUser($target)
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

    private function isPersonalStaffUser(?User $user): bool
    {
        return in_array($this->roleSlug($user), $this->personalStaffRoleSlugs(), true);
    }

    private function isGlobalPersonalMessagingUser(?User $user): bool
    {
        return in_array($this->roleSlug($user), self::GLOBAL_PERSONAL_MESSAGING_ROLE_SLUGS, true);
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
