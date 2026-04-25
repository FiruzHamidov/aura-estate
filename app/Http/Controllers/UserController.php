<?php

namespace App\Http\Controllers;

use App\Models\BranchGroup;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const REPORT_AGENT_ROLE_SLUGS = ['agent', 'intern', 'rop', 'mop'];

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        abort_unless($user, 401, 'Unauthenticated.');

        $user->loadMissing('role');

        return $user;
    }

    private function roleSlug(?User $user): ?string
    {
        return $user?->role?->slug;
    }

    private function isPrivilegedRole(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['superadmin', 'admin', 'marketing', 'hr'], true);
    }

    private function isBranchScopedManager(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['branch_director', 'rop'], true);
    }

    private function isBranchScopedRole(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['branch_director', 'rop', 'mop', 'agent', 'manager', 'operator', 'intern'], true);
    }

    private function isBranchGroupScopedRole(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['mop'], true);
    }

    private function visibleUsersQuery(User $authUser)
    {
        $roleSlug = $this->roleSlug($authUser);

        $query = User::query()->with(['role', 'branch', 'branchGroup']);

        if ($this->isPrivilegedRole($roleSlug)) {
            return $query;
        }

        if (! $this->isBranchScopedManager($roleSlug) || empty($authUser->branch_id)) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('branch_id', $authUser->branch_id);

        return $query;
    }

    private function applyStatusFilter(Builder $query, string $status): Builder
    {
        return $query->where(function (Builder $statusQuery) use ($status) {
            if ($status === 'active') {
                $statusQuery->where('status', 'active')
                    ->orWhereNull('status');

                return;
            }

            $statusQuery->where('status', 'inactive');
        });
    }

    private function applyIndexFilters(Builder $query, array $validated, User $authUser, bool $applyStatusFilter = true): Builder
    {
        if (! empty($validated['name'])) {
            $query->where('name', 'like', '%'.trim($validated['name']).'%');
        }

        if (! empty($validated['phone'])) {
            $query->where('phone', 'like', '%'.trim($validated['phone']).'%');
        }

        if (! empty($validated['email'])) {
            $query->where('email', 'like', '%'.trim($validated['email']).'%');
        }

        if ($this->isPrivilegedRole($this->roleSlug($authUser))) {
            if (! empty($validated['branch_id'])) {
                $query->where('branch_id', $validated['branch_id']);
            }
        } elseif (! empty($authUser->branch_id)) {
            $query->where('branch_id', $authUser->branch_id);
        }

        if (! empty($validated['branch_group_id'])) {
            $query->where('branch_group_id', $validated['branch_group_id']);
        }

        if (! empty($validated['role'])) {
            $query->whereHas('role', fn ($roleQuery) => $roleQuery->where('slug', $validated['role']));
        }

        if (! empty($validated['roles'])) {
            $query->whereHas('role', fn ($roleQuery) => $roleQuery->whereIn('slug', $validated['roles']));
        }

        if (! empty($validated['report_agents'])) {
            $query->whereHas('role', fn ($roleQuery) => $roleQuery->whereIn('slug', self::REPORT_AGENT_ROLE_SLUGS));
        }

        if ($applyStatusFilter && ! empty($validated['status'])) {
            $this->applyStatusFilter($query, $validated['status']);
        }

        return $query;
    }

    private function paginationMeta(array $users): array
    {
        return [
            'current_page' => $users['current_page'] ?? 1,
            'last_page' => $users['last_page'] ?? 1,
            'per_page' => $users['per_page'] ?? count($users['data'] ?? []),
            'total' => $users['total'] ?? count($users['data'] ?? []),
        ];
    }

    private function statusCountsForIndex(Builder $query): array
    {
        return [
            'active_count' => $this->applyStatusFilter(clone $query, 'active')->count(),
            'inactive_count' => $this->applyStatusFilter(clone $query, 'inactive')->count(),
        ];
    }

    private function ensureUserIsVisible(User $authUser, User $targetUser): void
    {
        $allowed = $this->visibleUsersQuery($authUser)
            ->whereKey($targetUser->id)
            ->exists();

        abort_unless($allowed, 403, 'Forbidden');
    }

    private function allowedRoleSlugsForActor(User $authUser): ?array
    {
        return match ($this->roleSlug($authUser)) {
            'superadmin', 'admin' => null,
            'marketing' => ['marketing', 'branch_director', 'rop', 'mop', 'agent', 'manager', 'operator', 'intern', 'client'],
            'hr' => ['hr', 'marketing', 'branch_director', 'rop', 'mop', 'agent', 'manager', 'operator', 'intern', 'client', 'reels_manager'],
            'rop' => ['mop', 'agent', 'manager', 'operator', 'intern', 'client'],
            'branch_director' => ['agent', 'manager', 'operator', 'intern', 'client'],
            default => [],
        };
    }

    private function resolveRequestedRole(Request $request, ?User $targetUser = null): ?Role
    {
        if ($request->filled('role_id')) {
            return Role::find($request->integer('role_id'));
        }

        if ($targetUser) {
            $targetUser->loadMissing('role');

            return $targetUser->role;
        }

        return null;
    }

    private function authorizeAssignedRole(User $authUser, ?Role $targetRole): void
    {
        if (! $targetRole) {
            return;
        }

        $allowedRoleSlugs = $this->allowedRoleSlugsForActor($authUser);

        if ($allowedRoleSlugs === null) {
            return;
        }

        abort_unless(in_array($targetRole->slug, $allowedRoleSlugs, true), 422, 'This role cannot be assigned.');
    }

    private function normalizeBranchIdForMutation(array $data, User $authUser, ?Role $targetRole): array
    {
        $roleSlug = $targetRole?->slug;

        if (! $roleSlug) {
            return $data;
        }

        if ($this->isBranchScopedRole($roleSlug) && $this->isBranchScopedManager($this->roleSlug($authUser))) {
            $data['branch_id'] = $authUser->branch_id;
        }

        if ($this->isBranchScopedRole($roleSlug) && empty($data['branch_id'])) {
            abort(422, 'branch_id is required for this role.');
        }

        return $data;
    }

    private function normalizeBranchGroupIdForMutation(array $data, ?Role $targetRole): array
    {
        $roleSlug = $targetRole?->slug;

        if (! $roleSlug) {
            return $data;
        }

        if (! $this->isBranchScopedRole($roleSlug) && ! $this->isBranchGroupScopedRole($roleSlug)) {
            $data['branch_group_id'] = null;

            return $data;
        }

        if ($this->isBranchGroupScopedRole($roleSlug) && empty($data['branch_group_id'])) {
            abort(422, 'branch_group_id is required for this role.');
        }

        if (empty($data['branch_group_id'])) {
            return $data;
        }

        $branchGroup = BranchGroup::query()->find($data['branch_group_id']);

        if (! $branchGroup) {
            abort(422, 'branch_group_id must exist.');
        }

        if (! empty($data['branch_id']) && (int) $branchGroup->branch_id !== (int) $data['branch_id']) {
            abort(422, 'branch_group_id must belong to the user branch.');
        }

        return $data;
    }

    private function transferablePropertiesQuery(User $user): Builder
    {
        $closedModerationStatuses = ['sold', 'rented', 'sold_by_owner'];
        $closedStatusIds = DB::table('property_statuses')
            ->whereIn('slug', ['sold', 'rented'])
            ->pluck('id')
            ->all();

        return Property::query()
            ->where('created_by', $user->id)
            ->where(function (Builder $query) use ($closedModerationStatuses) {
                $query->whereNull('moderation_status')
                    ->orWhereNotIn('moderation_status', $closedModerationStatuses);
            })
            ->when(! empty($closedStatusIds), function (Builder $query) use ($closedStatusIds) {
                $query->where(function (Builder $statusQuery) use ($closedStatusIds) {
                    $statusQuery->whereNull('status_id')
                        ->orWhereNotIn('status_id', $closedStatusIds);
                });
            });
    }

    // Список всех пользователей
    public function index(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'name' => 'nullable|string',
            'phone' => 'nullable|string',
            'email' => 'nullable|string',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'role' => 'nullable|string|exists:roles,slug',
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:roles,slug',
            'report_agents' => 'nullable|boolean',
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $this->visibleUsersQuery($authUser);
        $baseQuery = $this->applyIndexFilters($query, $validated, $authUser, false);
        $tabCounts = $this->statusCountsForIndex(clone $baseQuery);
        $query = clone $baseQuery;

        if (! empty($validated['status'])) {
            $this->applyStatusFilter($query, $validated['status']);
        }

        $users = $query
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        $payload = $users->toArray();
        $meta = $this->paginationMeta($payload);

        return response()->json(array_merge($payload, $tabCounts, [
            'meta' => $meta,
        ], $meta));
    }

    // Создание пользователя
    public function store(Request $request)
    {
        $authUser = $this->authUser();

        $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'birthday' => 'nullable|date',
            'phone' => 'required|string|unique:users,phone',
            'email' => 'nullable|email|unique:users,email',
            'role_id' => 'required|exists:roles,id',
            'branch_id' => 'nullable|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'auth_method' => 'nullable|in:password,sms',
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'password' => 'nullable|string|min:6',
        ]);

        $targetRole = $this->resolveRequestedRole($request);
        $this->authorizeAssignedRole($authUser, $targetRole);

        $data = $request->only(['name', 'phone', 'email', 'role_id', 'branch_id', 'branch_group_id', 'auth_method', 'status', 'birthday', 'description']);
        $data = $this->normalizeBranchIdForMutation($data, $authUser, $targetRole);
        $data = $this->normalizeBranchGroupIdForMutation($data, $targetRole);

        if (! $request->filled('auth_method')) {
            unset($data['auth_method']);
        }

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user = User::create($data);

        return response()->json($user->load(['role', 'branch', 'branchGroup']), 201);
    }

    // Просмотр конкретного пользователя
    public function show(User $user)
    {
        $this->ensureUserIsVisible($this->authUser(), $user);

        return response()->json($user->load(['role', 'branch', 'branchGroup']));
    }

    public function profile()
    {
        return response()->json(
            $this->authUser()->loadMissing(['role', 'branch', 'branchGroup'])
        );
    }

    public function updateProfile(Request $request)
    {
        $user = $this->authUser();

        $data = $request->validate([
            'name' => 'sometimes|string',
            'description' => 'nullable|string',
            'birthday' => 'nullable|date',
            'phone' => 'sometimes|string|unique:users,phone,'.$user->id,
            'email' => 'sometimes|email|unique:users,email,'.$user->id,
        ]);

        $user->update($data);

        return response()->json($user->fresh(['role', 'branch', 'branchGroup']));
    }

    // Обновление пользователя
    public function update(Request $request, User $user)
    {
        $authUser = $this->authUser();
        $this->ensureUserIsVisible($authUser, $user);

        $request->validate([
            'name' => 'sometimes|string',
            'description' => 'nullable|string',
            'birthday' => 'nullable|date',
            'phone' => 'sometimes|string|unique:users,phone,'.$user->id,
            'email' => 'sometimes|email|unique:users,email,'.$user->id,
            'role_id' => 'sometimes|exists:roles,id',
            'branch_id' => 'sometimes|nullable|exists:branches,id',
            'branch_group_id' => 'sometimes|nullable|integer|exists:branch_groups,id',
            'auth_method' => 'sometimes|nullable|in:password,sms',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'password' => 'nullable|string|min:6',
        ]);

        $targetRole = $this->resolveRequestedRole($request, $user);

        if ($request->filled('role_id')) {
            $this->authorizeAssignedRole($authUser, $targetRole);
        }

        $data = array_merge([
            'branch_id' => $user->branch_id,
            'branch_group_id' => $user->branch_group_id,
        ], $request->only(['name', 'phone', 'email', 'role_id', 'branch_id', 'branch_group_id', 'auth_method', 'status', 'description', 'birthday']));
        $data = $this->normalizeBranchIdForMutation($data, $authUser, $targetRole);
        $data = $this->normalizeBranchGroupIdForMutation($data, $targetRole);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $filename = 'users/'.uniqid().'.'.$file->getClientOriginalExtension();
            $path = \Storage::disk('public')->put($filename, file_get_contents($file));
            $data['photo'] = $filename;
        }

        $user->update($data);

        return response()->json($user->fresh(['role', 'branch', 'branchGroup']));
    }

    public function updatePhoto(Request $request, User $user)
    {
        $request->validate([
            'photo' => 'required|image|max:2048',
        ]);

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $filename = 'users/'.uniqid().'.'.$file->getClientOriginalExtension();
            \Storage::disk('public')->put($filename, file_get_contents($file));
            $user->update(['photo' => $filename]);
        }

        return response()->json(['message' => 'Photo updated', 'photo' => $user->photo]);
    }

    public function deleteMyPhoto()
    {
        $user = Auth::user();

        if ($user->photo && \Storage::disk('public')->exists($user->photo)) {
            \Storage::disk('public')->delete($user->photo);
        }

        $user->update(['photo' => null]);

        return response()->json(['message' => 'Ваша фотография была удалена']);
    }

    public function agents(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
        ]);

        $status = $validated['status'] ?? 'active';

        $agents = User::with(['role', 'branch', 'branchGroup'])
            ->whereHas('role', function ($q) {
                $q->where('slug', 'agent');
            })
            ->when($status !== 'all', function ($q) use ($status) {
                $this->applyStatusFilter($q, $status);
            })
            ->get();

        return response()->json($agents);
    }

    // Увольнение пользователя и перераспределения
    public function destroy(Request $request, User $user)
    {
        $authUser = $this->authUser();
        $this->ensureUserIsVisible($authUser, $user);

        // Валидация входных параметров
        $request->validate([
            'distribute_to_agents' => 'nullable|boolean',
            'agent_id' => 'nullable|integer|exists:users,id',
        ]);

        $distribute = (bool) $request->boolean('distribute_to_agents');
        $agentId = $request->input('agent_id');

        // Нормы: либо distribute_to_agents=true, либо agent_id обязателен
        if (! $distribute && ! $agentId) {
            return response()->json([
                'message' => 'Укажите distribute_to_agents=true для авто-распределения ИЛИ передайте agent_id.',
            ], 422);
        }

        if ($agentId && (int) $agentId === (int) $user->id) {
            return response()->json([
                'message' => 'Нельзя передать объекты самому удаляемому пользователю.',
            ], 422);
        }

        // Проверка, что целевой получатель — агент (если указан)
        if ($agentId) {
            $target = User::with('role')->find($agentId);
            if (! $target || ! $target->role || $target->role->slug !== 'agent' || $target->status !== 'active') {
                return response()->json([
                    'message' => 'agent_id должен указывать на активного пользователя с ролью агент.',
                ], 422);
            }

            $this->ensureUserIsVisible($authUser, $target);
        }

        DB::transaction(function () use ($user, $distribute, $agentId) {
            // Передаём только активные объекты; закрытые остаются у уволенного пользователя.
            $props = $this->transferablePropertiesQuery($user)
                ->lockForUpdate()
                ->get(['id', 'created_by']);

            if ($props->isNotEmpty()) {
                if ($distribute) {
                    // Соберём список доступных агентов (кроме удаляемого)
                    $agentIds = User::whereHas('role', fn ($q) => $q->where('slug', 'agent'))
                        ->where(function (Builder $query) {
                            $query->where('status', 'active')
                                ->orWhereNull('status');
                        })
                        ->where('id', '!=', $user->id)
                        ->pluck('id')
                        ->all();

                    if (empty($agentIds)) {
                        // Нет агентов — нельзя распределить
                        throw new \RuntimeException('Нет доступных агентов для авто-распределения.');
                    }

                    // Равномерно распределяем (round-robin)
                    $countAgents = count($agentIds);
                    $i = 0;
                    foreach ($props as $p) {
                        $newAgentId = $agentIds[$i % $countAgents];
                        Property::whereKey($p->id)->update(['agent_id' => $newAgentId, 'created_by' => $newAgentId]);
                        $i++;
                    }
                } else {
                    Property::whereKey($props->pluck('id')->all())
                        ->update(['agent_id' => $agentId, 'created_by' => $agentId]);
                }
            }

            // Увольняем пользователя: деактивация + отзыв всех токенов
            $user->status = 'inactive';
            $user->remember_token = null;
            $user->password = Hash::make(Str::random(40));
            $user->telegram_id = null;
            $user->telegram_username = null;
            $user->telegram_photo_url = null;
            $user->telegram_chat_id = null;
            $user->telegram_linked_at = null;
            $user->save();
            $user->tokens()->delete();
        });

        return response()->json(['message' => 'Пользователь уволен, доступ в систему отключён, объекты перераспределены.']);
    }

    public function updatePassword(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        // Проверка текущего пароля
        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Текущий пароль введён неверно',
            ], 422);
        }

        // Обновление
        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json(['message' => 'Пароль успешно обновлён']);
    }
}
