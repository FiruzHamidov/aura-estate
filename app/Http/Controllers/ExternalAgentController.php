<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExternalAgentController extends Controller
{
    public function index(Request $request)
    {
        $actor = $this->actor($request, 'external_agents.view');
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()
            ->with(['role', 'branch', 'branchGroup'])
            ->whereHas('role', fn (Builder $roles) => $roles->where('slug', 'external_agent'));

        $this->applyActorScope($query, $actor);

        if (! empty($validated['q'])) {
            $search = trim($validated['q']);
            $query->where(function (Builder $searchQuery) use ($search) {
                $searchQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $query->where('status', $validated['status'] ?? User::STATUS_ACTIVE);

        return response()->json($query->latest('id')->paginate((int) ($validated['per_page'] ?? 20)));
    }

    public function store(Request $request)
    {
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => \App\Support\InternationalPhone::accountValue($request->input('phone'))]);
        }

        $actor = $this->actor($request, 'external_agents.create');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => [new \App\Rules\InternationalPhoneNumber, 'required', 'string', 'max:255', new \App\Rules\UniqueUserPhone],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'description' => ['sometimes', 'nullable', 'string'],
            'auth_method' => ['sometimes', Rule::in(['password', 'sms'])],
            'password' => ['nullable', 'string', 'min:6', 'required_if:auth_method,password'],
        ]);

        return DB::transaction(function () use ($request, $validated) {
            [$actor] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquire($request->user(), null, null, null);
            $this->ensureActor($actor, 'external_agents.create');
            $externalRole = Role::query()->where('slug', 'external_agent')->firstOrFail();
            $authMethod = $validated['auth_method'] ?? 'sms';

            $externalAgent = User::query()->create([
                'name' => $validated['name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
                'description' => $validated['description'] ?? null,
                'role_id' => $externalRole->id,
                'branch_id' => $actor->branch_id,
                'branch_group_id' => $actor->branch_group_id,
                'auth_method' => $authMethod,
                'status' => User::STATUS_ACTIVE,
                'password' => $authMethod === 'password' ? Hash::make($validated['password']) : null,
            ]);

            return response()->json($externalAgent->load(['role', 'branch', 'branchGroup']), 201);
        });
    }

    private function actor(Request $request, string $permission): User
    {
        /** @var User|null $actor */
        $actor = $request->user();
        abort_unless($actor, 401, 'Unauthenticated.');
        $this->ensureActor($actor, $permission);
        return $actor;
    }

    private function ensureActor(User $actor, string $permission): void
    {
        abort_unless($actor->hasPermission($permission), 403, 'Доступ запрещён');
        abort_unless($actor->branch_id, 403, 'Для работы с внешними агентами нужен филиал.');
        if ($actor->hasPermission('external_agents.require_group')) {
            abort_unless($actor->branch_group_id, 403, 'Для работы с внешними агентами нужна группа.');
        }
    }

    private function applyActorScope(Builder $query, User $actor): void
    {
        if ($actor->usesAssignedGroupScope()) {
            app(\App\Support\RopGroupAccess::class)->scope($query, $actor, 'users.branch_group_id', 'users.branch_id');
        }
        if ($actor->hasPermission('external_agents.scope.all')) return;
        if ($actor->hasPermission('external_agents.scope.group')) {
            $actor->branch_group_id ? $query->where('branch_group_id', $actor->branch_group_id) : $query->whereRaw('1 = 0');
            return;
        }
        if ($actor->hasPermission('external_agents.scope.branch')) {
            $query->where('branch_id', $actor->branch_id);
            return;
        }
        $query->whereRaw('1 = 0');
    }
}
