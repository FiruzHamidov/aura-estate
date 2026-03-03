<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();
        $user->loadMissing('role');

        return $user;
    }

    private function roleSlug(?User $user): ?string
    {
        return $user?->role?->slug;
    }

    private function isPrivilegedRole(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['superadmin', 'admin'], true);
    }

    private function isBranchScopedManager(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['branch_director', 'rop'], true);
    }

    private function isBranchScopedRole(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['branch_director', 'rop', 'agent', 'manager', 'operator'], true);
    }

    private function visibleUsersQuery(User $authUser)
    {
        $roleSlug = $this->roleSlug($authUser);

        $query = User::query()->with(['role', 'branch']);

        if ($this->isPrivilegedRole($roleSlug)) {
            return $query;
        }

        if (!$this->isBranchScopedManager($roleSlug) || empty($authUser->branch_id)) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('branch_id', $authUser->branch_id);

        if ($roleSlug === 'rop') {
            $query->whereHas('role', fn ($q) => $q->where('slug', '!=', 'branch_director'));
        }

        return $query;
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
            'rop' => ['agent', 'client'],
            'branch_director' => ['agent', 'client'],
            default => [],
        };
    }

    private function resolveTargetRole(Request $request, ?User $targetUser = null): ?Role
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
        if (!$targetRole) {
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

        if (!$roleSlug) {
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

    // Список всех пользователей
    public function index(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'name' => 'nullable|string',
            'phone' => 'nullable|string',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'role' => 'nullable|string|exists:roles,slug',
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $this->visibleUsersQuery($authUser);

        if (!empty($validated['name'])) {
            $query->where('name', 'like', '%' . trim($validated['name']) . '%');
        }

        if (!empty($validated['phone'])) {
            $query->where('phone', 'like', '%' . trim($validated['phone']) . '%');
        }

        if ($this->isPrivilegedRole($this->roleSlug($authUser))) {
            if (!empty($validated['branch_id'])) {
                $query->where('branch_id', $validated['branch_id']);
            }
        } elseif (!empty($authUser->branch_id)) {
            $query->where('branch_id', $authUser->branch_id);
        }

        if (!empty($validated['role'])) {
            $query->whereHas('role', fn ($q) => $q->where('slug', $validated['role']));
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $users = $query
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        return response()->json($users);
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
            'auth_method' => 'nullable|in:password,sms',
            'password' => 'nullable|string|min:6',
        ]);

        $targetRole = $this->resolveTargetRole($request);
        $this->authorizeAssignedRole($authUser, $targetRole);

        $data = $request->only(['name', 'phone', 'email', 'role_id', 'branch_id', 'auth_method', 'status', 'birthday', 'description']);
        $data = $this->normalizeBranchIdForMutation($data, $authUser, $targetRole);

        if (!$request->filled('auth_method')) {
            unset($data['auth_method']);
        }

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user = User::create($data);

        return response()->json($user, 201);
    }

    // Просмотр конкретного пользователя
    public function show(User $user)
    {
        $this->ensureUserIsVisible($this->authUser(), $user);

        return response()->json($user->load(['role', 'branch']));
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
            'phone' => 'sometimes|string|unique:users,phone,' . $user->id,
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'role_id' => 'sometimes|exists:roles,id',
            'branch_id' => 'sometimes|nullable|exists:branches,id',
            'auth_method' => 'sometimes|nullable|in:password,sms',
            'password' => 'nullable|string|min:6',
        ]);

        $targetRole = $this->resolveTargetRole($request, $user);
        $this->authorizeAssignedRole($authUser, $targetRole);

        $data = $request->only(['name', 'phone', 'email', 'role_id', 'branch_id', 'auth_method', 'status', 'description', 'birthday']);
        $data = $this->normalizeBranchIdForMutation($data, $authUser, $targetRole);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $filename = 'users/' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = \Storage::disk('public')->put($filename, file_get_contents($file));
            $data['photo'] = $filename;
        }

        $user->update($data);

        return response()->json($user->fresh(['role', 'branch']));
    }

    public function updatePhoto(Request $request, User $user)
    {
        $request->validate([
            'photo' => 'required|image|max:2048'
        ]);

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $filename = 'users/' . uniqid() . '.' . $file->getClientOriginalExtension();
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

        $agents = User::with(['role', 'branch'])
            ->whereHas('role', function ($q) {
                $q->where('slug', 'agent');
            })
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
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
        if (!$distribute && !$agentId) {
            return response()->json([
                'message' => 'Укажите distribute_to_agents=true для авто-распределения ИЛИ передайте agent_id.',
            ], 422);
        }

        if ($agentId && (int)$agentId === (int)$user->id) {
            return response()->json([
                'message' => 'Нельзя передать объекты самому удаляемому пользователю.',
            ], 422);
        }

        // Проверка, что целевой получатель — агент (если указан)
        if ($agentId) {
            $target = User::with('role')->find($agentId);
            if (!$target || !$target->role || $target->role->slug !== 'agent' || $target->status !== 'active') {
                return response()->json([
                    'message' => 'agent_id должен указывать на активного пользователя с ролью агент.',
                ], 422);
            }

            $this->ensureUserIsVisible($authUser, $target);
        }

        DB::transaction(function () use ($user, $distribute, $agentId) {
            // Блокируем набор properties пользователя на время операции
            $props = Property::where('created_by', $user->id)
                ->lockForUpdate()
                ->get(['id', 'created_by']);

            if ($props->isNotEmpty()) {
                if ($distribute) {
                    // Соберём список доступных агентов (кроме удаляемого)
                    $agentIds = User::whereHas('role', fn($q) => $q->where('slug', 'agent'))
                        ->where('status', 'active')
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
                    // Передаём все объекты одному агенту
                    Property::where('created_by', $user->id)->update(['agent_id' => $agentId, 'created_by' => $agentId]);
                }
            }

            // Увольняем пользователя: деактивация + отзыв всех токенов
            $user->status = 'inactive';
            $user->remember_token = null;
            $user->password = Hash::make(Str::random(40));
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
            'new_password'     => 'required|string|min:6|confirmed',
        ]);

        // Проверка текущего пароля
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Текущий пароль введён неверно'
            ], 422);
        }

        // Обновление
        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json(['message' => 'Пароль успешно обновлён']);
    }
}
