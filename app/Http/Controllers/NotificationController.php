<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications
    ) {}

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        abort_unless($user, 401, 'Unauthenticated.');

        return $user;
    }

    private function serialize(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'category' => $notification->category,
            'status' => $notification->status,
            'priority' => $notification->priority,
            'channels' => $notification->channels ?? [],
            'title' => $notification->title,
            'body' => $notification->body,
            'action_url' => $notification->action_url,
            'action_type' => $notification->action_type,
            'occurrences_count' => $notification->occurrences_count,
            'last_occurred_at' => $notification->last_occurred_at?->toIso8601String(),
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
            'actor' => $notification->actor ? [
                'id' => $notification->actor->id,
                'name' => $notification->actor->name,
                'role_slug' => $notification->actor->role?->slug,
            ] : null,
            'subject' => [
                'type' => $notification->subject_type,
                'id' => $notification->subject_id,
            ],
            'data' => $notification->data ?? [],
        ];
    }

    public function index(Request $request)
    {
        $user = $this->authUser();

        $validated = $request->validate([
            'type' => ['nullable', Rule::in(NotificationType::all())],
            'category' => ['nullable', Rule::in(NotificationCategory::all())],
            'is_read' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Notification::query()
            ->with('actor.role')
            ->where('user_id', $user->id)
            ->latest('last_occurred_at')
            ->latest('id');

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        if (array_key_exists('is_read', $validated) && $validated['is_read'] !== null) {
            $validated['is_read']
                ? $query->whereNotNull('read_at')
                : $query->whereNull('read_at');
        }

        return response()->json(
            $query->paginate((int) ($validated['per_page'] ?? 20))
                ->through(fn (Notification $notification) => $this->serialize($notification))
        );
    }

    public function unreadCount()
    {
        return response()->json([
            'unread_count' => $this->notifications->unreadCount($this->authUser()),
        ]);
    }

    public function markRead(Notification $notification)
    {
        return response()->json(
            $this->serialize($this->notifications->markAsRead($notification, $this->authUser()))
        );
    }

    public function markAllRead(Request $request)
    {
        $validated = $request->validate([
            'category' => ['nullable', Rule::in(NotificationCategory::all())],
        ]);

        return response()->json([
            'updated' => $this->notifications->markAllAsRead($this->authUser(), $validated['category'] ?? null),
        ]);
    }
}
