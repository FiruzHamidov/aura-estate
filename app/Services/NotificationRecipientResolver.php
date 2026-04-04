<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Selection;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationRecipientResolver
{
    public function branchManagers(?int $branchId): Collection
    {
        return $this->usersByRole(['manager'], $branchId);
    }

    public function leadManagers(Lead $lead): Collection
    {
        $users = collect();

        if ($lead->responsible_agent_id) {
            $responsible = User::query()
                ->with('role')
                ->whereKey($lead->responsible_agent_id)
                ->where('status', User::STATUS_ACTIVE)
                ->first();

            if ($responsible?->role?->slug === 'manager') {
                $users->push($responsible);
            }
        }

        return $users
            ->merge($this->branchManagers($lead->branch_id))
            ->unique('id')
            ->values();
    }

    public function dealStakeholders(Deal $deal): Collection
    {
        $deal->loadMissing('creator.role', 'responsibleAgent.role');

        return collect([$deal->creator, $deal->responsibleAgent])
            ->filter(fn ($user) => $user instanceof User && $user->status === User::STATUS_ACTIVE)
            ->unique('id')
            ->values();
    }

    public function bookingAgent(Booking $booking): Collection
    {
        $booking->loadMissing('agent.role');

        return collect([$booking->agent])
            ->filter(fn ($user) => $user instanceof User && $user->status === User::STATUS_ACTIVE)
            ->values();
    }

    public function conversationParticipants(Conversation $conversation, ?int $exceptUserId = null): Collection
    {
        $conversation->loadMissing('participants.user.role');

        return $conversation->participants
            ->pluck('user')
            ->filter(fn ($user) => $user instanceof User && $user->status === User::STATUS_ACTIVE)
            ->reject(fn (User $user) => $exceptUserId !== null && (int) $user->id === (int) $exceptUserId)
            ->unique('id')
            ->values();
    }

    public function selectionOwner(Selection $selection): Collection
    {
        $owner = User::query()
            ->with('role')
            ->whereKey($selection->created_by)
            ->where('status', User::STATUS_ACTIVE)
            ->first();

        return collect([$owner])->filter()->values();
    }

    public function usersByRole(array $roleSlugs, ?int $branchId = null): Collection
    {
        return User::query()
            ->with('role')
            ->where('status', User::STATUS_ACTIVE)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereHas('role', fn ($query) => $query->whereIn('slug', $roleSlugs))
            ->get();
    }
}
