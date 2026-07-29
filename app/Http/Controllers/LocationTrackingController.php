<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserLocationAuditLog;
use App\Models\UserLocationDevice;
use App\Models\UserLocationPoint;
use App\Models\UserLocationStatusEvent;
use App\Models\UserLocationTrackingSetting;
use App\Models\UserLocationViewPreference;
use App\Services\LocationTracking\LocationAccessService;
use App\Services\LocationTracking\LocationIngestionService;
use App\Services\LocationTracking\LocationTrackingPolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class LocationTrackingController extends Controller
{
    public function __construct(
        private readonly LocationAccessService $access,
        private readonly LocationTrackingPolicyService $policies,
        private readonly LocationIngestionService $ingestion,
    ) {}

    public function policy(Request $request)
    {
        return response()->json(['data' => $this->policies->payload($request->user())]);
    }

    public function upsertDevice(Request $request)
    {
        $user = $request->user();
        $this->access->assertCanTransmit($user);
        $data = $request->validate([
            'device_uuid' => ['required', 'uuid'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
            'app_version' => ['nullable', 'string', 'max:32'],
            'os_version' => ['nullable', 'string', 'max:32'],
            'permission_status' => ['required', Rule::in(['not_determined', 'when_in_use', 'always', 'denied', 'restricted', 'approximate'])],
            'background_permission' => ['required', 'boolean'],
            'last_policy_version' => ['nullable', 'integer', 'min:0'],
        ]);

        $device = UserLocationDevice::query()->updateOrCreate(
            ['user_id' => $user->id, 'device_uuid' => $data['device_uuid']],
            [...$data, 'last_seen_at' => now(), 'revoked_at' => null]
        );

        return response()->json(['data' => $device], 200);
    }

    public function storePoints(Request $request)
    {
        $this->access->assertCanTransmit($request->user());
        $base = $request->validate([
            'device_uuid' => ['required', 'uuid'],
            'points' => ['required', 'array', 'min:1', 'max:'.config('location_tracking.max_batch_size', 50)],
        ]);

        $valid = [];
        $rejected = [];

        foreach ($base['points'] as $raw) {
            $validator = Validator::make(is_array($raw) ? $raw : [], [
                'event_id' => ['required', 'uuid'],
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
                'accuracy_m' => ['required', 'numeric', 'gt:0', 'max:5000'],
                'altitude_m' => ['nullable', 'numeric', 'between:-1000,20000'],
                'speed_mps' => ['nullable', 'numeric', 'min:0', 'max:1000'],
                'heading_deg' => ['nullable', 'numeric', 'between:0,360'],
                'source' => ['nullable', Rule::in(['gps', 'network', 'fused', 'unknown'])],
                'app_state' => ['required', Rule::in(['foreground', 'background'])],
                'battery_percent' => ['nullable', 'integer', 'between:0,100'],
                'is_mocked' => ['nullable', 'boolean'],
                'captured_at' => ['required', 'date'],
            ]);

            if ($validator->fails()) {
                $rejected[] = [
                    'event_id' => is_array($raw) ? ($raw['event_id'] ?? null) : null,
                    'code' => 'LOCATION_INVALID_POINT',
                    'message' => 'Некорректная точка.',
                    'errors' => $validator->errors(),
                ];

                continue;
            }

            $valid[] = [
                ...$validator->validated(),
                'source' => $raw['source'] ?? 'unknown',
            ];
        }

        $result = $valid === []
            ? ['accepted' => [], 'duplicates' => [], 'rejected' => []]
            : $this->ingestion->ingest($request->user(), $base['device_uuid'], $valid);
        $result['rejected'] = [...$rejected, ...$result['rejected']];

        return response()->json(['data' => $result]);
    }

    public function storeStatus(Request $request)
    {
        $user = $request->user();
        $this->access->assertCanTransmit($user);
        $data = $request->validate([
            'device_uuid' => ['required', 'uuid'],
            'event' => ['required', Rule::in([
                'permission_denied', 'permission_granted', 'background_permission_missing',
                'tracking_started', 'tracking_stopped', 'policy_applied',
            ])],
            'occurred_at' => ['required', 'date'],
            'meta' => ['nullable', 'array'],
        ]);

        $device = UserLocationDevice::query()
            ->where('user_id', $user->id)
            ->where('device_uuid', $data['device_uuid'])
            ->whereNull('revoked_at')
            ->first();

        if (! $device) {
            $this->access->deny('LOCATION_DEVICE_NOT_REGISTERED', 'Устройство не зарегистрировано.', 409);
        }

        $event = UserLocationStatusEvent::query()->create([
            'user_id' => $user->id,
            'device_id' => $device->id,
            'event' => $data['event'],
            'meta' => $data['meta'] ?? null,
            'occurred_at' => CarbonImmutable::parse($data['occurred_at'])->setTimezone(config('app.timezone')),
        ]);

        $permission = data_get($data, 'meta.permission_status');
        if (is_string($permission)) {
            $device->permission_status = $permission;
        }
        if (data_get($data, 'meta.background_permission') !== null) {
            $device->background_permission = (bool) data_get($data, 'meta.background_permission');
        }
        $device->last_seen_at = now();
        $device->save();

        return response()->json(['data' => $event], 201);
    }

    public function myCurrent(Request $request)
    {
        $user = $request->user()->load(['role', 'currentLocation']);
        $this->access->assertCanViewModule($user);

        return response()->json(['data' => $this->mapItem($user)]);
    }

    public function availableUsers(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(['agent', 'mop'])],
            'branch_id' => ['nullable', 'integer'],
            'branch_group_id' => ['nullable', 'integer'],
            'tracking_enabled' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $viewer = $request->user();
        $query = $this->access->availableUsersQuery($viewer);

        if ($search = ($validated['search'] ?? null)) {
            $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
        }
        if ($role = ($validated['role'] ?? null)) {
            $query->whereHas('role', fn (Builder $q) => $q->where('slug', $role));
        }
        if (isset($validated['branch_id'])) {
            $query->where('branch_id', $validated['branch_id']);
        }
        if (isset($validated['branch_group_id'])) {
            $query->where('branch_group_id', $validated['branch_group_id']);
        }
        if (array_key_exists('tracking_enabled', $validated)) {
            if ((bool) $validated['tracking_enabled']) {
                $query->where(function (Builder $q) {
                    $q->whereDoesntHave('locationTrackingSetting')
                        ->orWhereHas('locationTrackingSetting', fn (Builder $settings) => $settings
                            ->where('tracking_enabled', true)
                            ->where('mode', '!=', 'off'));
                });
            } else {
                $query->whereHas('locationTrackingSetting', fn (Builder $settings) => $settings
                    ->where(fn (Builder $disabled) => $disabled
                        ->where('tracking_enabled', false)
                        ->orWhere('mode', 'off')));
            }
        }

        $selected = DB::table('user_location_watchlist')
            ->where('viewer_user_id', $viewer->id)
            ->pluck('target_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $page = $query->orderBy('name')->paginate($validated['per_page'] ?? 50);
        $page->getCollection()->transform(function (User $user) use ($selected) {
            $settings = $this->policies->settingsFor($user);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'photo' => $user->photo,
                'role' => $user->role?->slug,
                'branch_id' => $user->branch_id,
                'branch_group_id' => $user->branch_group_id,
                'tracking_enabled' => $this->policies->isEligible($user) && (bool) $settings->tracking_enabled,
                'selected' => in_array($user->id, $selected, true),
                'location_status' => $this->locationStatus($user),
                'last_location_at' => $user->currentLocation?->captured_at?->toISOString(),
            ];
        });

        return response()->json($page);
    }

    public function watchlist(Request $request)
    {
        $viewer = $request->user();
        $this->access->assertCanViewModule($viewer);
        $preference = UserLocationViewPreference::query()->firstOrCreate(
            ['viewer_user_id' => $viewer->id],
            ['mode' => 'all_available']
        );

        return response()->json(['data' => [
            'mode' => $preference->mode,
            'user_ids' => DB::table('user_location_watchlist')
                ->where('viewer_user_id', $viewer->id)
                ->pluck('target_user_id')
                ->map(fn ($id) => (int) $id)
                ->values(),
        ]]);
    }

    public function updateWatchlist(Request $request)
    {
        $viewer = $request->user();
        $this->access->assertCanViewModule($viewer);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['all_available', 'selected'])],
            'user_ids' => ['required_if:mode,selected', 'array', 'max:500'],
            'user_ids.*' => ['integer', 'distinct'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['user_ids'] ?? [])));
        $allowed = $ids === [] ? 0 : $this->access->availableUsersQuery($viewer)->whereIn('users.id', $ids)->count();

        if ($allowed !== count($ids)) {
            $this->access->deny('LOCATION_INVALID_WATCHLIST', 'Список содержит недоступных пользователей.', 422);
        }

        DB::transaction(function () use ($viewer, $data, $ids) {
            UserLocationViewPreference::query()->updateOrCreate(
                ['viewer_user_id' => $viewer->id],
                ['mode' => $data['mode']]
            );
            DB::table('user_location_watchlist')->where('viewer_user_id', $viewer->id)->delete();
            if ($data['mode'] === 'selected' && $ids !== []) {
                DB::table('user_location_watchlist')->insert(array_map(fn (int $id) => [
                    'viewer_user_id' => $viewer->id,
                    'target_user_id' => $id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $ids));
            }
        });

        return $this->watchlist($request);
    }

    public function map(Request $request)
    {
        $filters = $request->validate([
            'role' => ['nullable', Rule::in(['agent', 'mop'])],
            'branch_id' => ['nullable', 'integer'],
            'branch_group_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([
                'live', 'stale', 'offline', 'no_data', 'permission_denied',
                'background_permission_missing', 'outside_schedule', 'tracking_disabled',
            ])],
        ]);
        $viewer = $request->user();
        $query = $this->access->availableUsersQuery($viewer);
        $preference = UserLocationViewPreference::query()->where('viewer_user_id', $viewer->id)->first();

        if ($preference?->mode === 'selected') {
            $query->whereIn('users.id', DB::table('user_location_watchlist')->select('target_user_id')->where('viewer_user_id', $viewer->id));
        }
        if (isset($filters['role'])) {
            $query->whereHas('role', fn (Builder $q) => $q->where('slug', $filters['role']));
        }
        if (isset($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }
        if (isset($filters['branch_group_id'])) {
            $query->where('branch_group_id', $filters['branch_group_id']);
        }

        $items = $query->orderBy('name')->get()->map(fn (User $user) => $this->mapItem($user));
        if (isset($filters['status'])) {
            $items = $items->where('tracking.status', $filters['status'])->values();
        }

        return response()->json([
            'data' => $items,
            'meta' => ['server_time' => now()->toISOString()],
        ]);
    }

    public function history(Request $request, User $user)
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'quality' => ['nullable', Rule::in(['good', 'medium', 'low', 'suspect'])],
        ]);
        $from = CarbonImmutable::parse($data['from'])->utc();
        $to = CarbonImmutable::parse($data['to'])->utc();

        if ($from->diffInDays($to) > 31) {
            $this->access->deny('LOCATION_HISTORY_PERIOD_TOO_LARGE', 'Максимальный период — 31 день.', 422);
        }

        $query = UserLocationPoint::query()
            ->where('user_id', $user->id)
            ->whereBetween('captured_at', [
                $from->setTimezone(config('app.timezone')),
                $to->setTimezone(config('app.timezone')),
            ]);
        $this->access->applyHistoryScope($query, $request->user(), $user);
        if (isset($data['quality'])) {
            $query->where('quality', $data['quality']);
        }
        $points = $query->orderBy('captured_at')->get();
        $distance = 0.0;
        for ($i = 1; $i < $points->count(); $i++) {
            $distance += $this->ingestion->distanceMeters(
                (float) $points[$i - 1]->latitude,
                (float) $points[$i - 1]->longitude,
                (float) $points[$i]->latitude,
                (float) $points[$i]->longitude
            );
        }

        $this->audit($request, 'history_viewed', $user, ['from' => $from->toISOString(), 'to' => $to->toISOString()]);

        return response()->json(['data' => [
            'user' => ['id' => $user->id, 'name' => $user->name],
            'period' => ['from' => $from->toISOString(), 'to' => $to->toISOString()],
            'summary' => [
                'points_count' => $points->count(),
                'distance_m' => round($distance),
                'first_point_at' => $points->first()?->captured_at?->toISOString(),
                'last_point_at' => $points->last()?->captured_at?->toISOString(),
            ],
            'points' => $points,
            'is_simplified' => false,
        ]]);
    }

    public function settings(Request $request, User $user)
    {
        $this->access->assertCanConfigure($request->user(), $user);

        return response()->json(['data' => $this->policies->payload($user)]);
    }

    public function updateSettings(Request $request, User $user)
    {
        $actor = $request->user();
        $this->access->assertCanConfigure($actor, $user);
        if (! $this->policies->isEligible($user)) {
            $this->access->deny('LOCATION_FORBIDDEN_ROLE', 'Отслеживать можно только агентов и МОПов.', 422);
        }
        $data = $request->validate([
            'tracking_enabled' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', Rule::in(['off', 'work_schedule', 'always'])],
            'timezone' => ['sometimes', 'timezone'],
            'schedule' => ['sometimes', 'array'],
            'foreground_interval_sec' => ['sometimes', 'integer', 'between:15,3600'],
            'background_interval_sec' => ['sometimes', 'integer', 'between:60,7200'],
            'min_distance_m' => ['sometimes', 'integer', 'between:0,5000'],
            'history_retention_days' => ['sometimes', 'integer', 'between:1,365'],
            'require_background_permission' => ['sometimes', 'boolean'],
            'change_reason' => ['required', 'string', 'max:500'],
        ]);

        $settings = UserLocationTrackingSetting::query()->firstOrNew(['user_id' => $user->id]);
        if (! $settings->exists) {
            $defaults = $this->policies->settingsFor($user)->getAttributes();
            $settings->fill($defaults);
        }
        $settings->fill($data);
        $settings->configured_by = $actor->id;
        $settings->policy_version = ((int) $settings->policy_version) + 1;
        $settings->save();
        $this->audit($request, 'settings_updated', $user, ['policy_version' => $settings->policy_version, 'change_reason' => $data['change_reason']]);

        return response()->json(['data' => $this->policies->payload($user)]);
    }

    private function mapItem(User $user): array
    {
        $user->loadMissing(['role', 'branch', 'branchGroup', 'currentLocation']);
        $settings = $this->policies->settingsFor($user);
        $current = $user->currentLocation;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'photo' => $user->photo,
                'role' => $user->role?->slug,
                'branch_id' => $user->branch_id,
                'branch_group_id' => $user->branch_group_id,
            ],
            'tracking' => [
                'enabled' => $this->policies->isEligible($user) && (bool) $settings->tracking_enabled,
                'status' => $this->locationStatus($user),
            ],
            'location' => $current ? [
                'latitude' => (float) $current->latitude,
                'longitude' => (float) $current->longitude,
                'accuracy_m' => (float) $current->accuracy_m,
                'quality' => $current->quality,
                'captured_at' => $current->captured_at?->toISOString(),
                'received_at' => $current->received_at?->toISOString(),
            ] : null,
        ];
    }

    private function locationStatus(User $user): string
    {
        $settings = $this->policies->settingsFor($user);
        if (! $this->policies->isEligible($user) || ! $settings->tracking_enabled || $settings->mode === 'off') {
            return 'tracking_disabled';
        }

        $device = UserLocationDevice::query()->where('user_id', $user->id)->whereNull('revoked_at')->latest('last_seen_at')->first();
        if ($device?->permission_status === 'denied' || $device?->permission_status === 'restricted') {
            return 'permission_denied';
        }
        if ($settings->require_background_permission && $device && ! $device->background_permission) {
            return 'background_permission_missing';
        }
        if (! $this->policies->isTrackingAllowedAt($user, CarbonImmutable::now())) {
            return 'outside_schedule';
        }

        $current = $user->currentLocation;
        if (! $current) {
            return 'no_data';
        }

        $age = $current->captured_at->diffInSeconds(now());
        if ($age <= (int) config('location_tracking.live_threshold_seconds', 120)) {
            return 'live';
        }

        return $age <= (int) config('location_tracking.stale_threshold_seconds', 900) ? 'stale' : 'offline';
    }

    private function audit(Request $request, string $action, ?User $target, array $meta = []): void
    {
        UserLocationAuditLog::query()->create([
            'actor_user_id' => $request->user()?->id,
            'target_user_id' => $target?->id,
            'action' => $action,
            'meta' => $meta,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'succeeded' => true,
        ]);
    }
}
