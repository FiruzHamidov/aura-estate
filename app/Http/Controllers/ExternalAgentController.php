<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ExternalAgentController extends Controller
{
    public function index(Request $request)
    {
        $actor = $this->actor($request);
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
        $actor = $this->actor($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255', Rule::unique('users', 'phone')],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'description' => ['sometimes', 'nullable', 'string'],
            'auth_method' => ['sometimes', Rule::in(['password', 'sms'])],
            'password' => ['nullable', 'string', 'min:6', 'required_if:auth_method,password'],
        ]);

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
    }

    private function actor(Request $request): User
    {
        /** @var User|null $actor */
        $actor = $request->user();
        abort_unless($actor, 401, 'Unauthenticated.');
        $actor->loadMissing('role');
        abort_unless($actor->hasRole('agent') || $actor->hasRole('mop'), 403, 'Доступ запрещён');
        abort_unless($actor->branch_id, 403, 'Для добавления внешнего агента нужен филиал.');

        if ($actor->hasRole('mop')) {
            abort_unless($actor->branch_group_id, 403, 'Для МОП нужна группа.');
        }

        return $actor;
    }

    private function applyActorScope(Builder $query, User $actor): void
    {
        if ($actor->hasRole('mop')) {
            $query->where('branch_group_id', $actor->branch_group_id);

            return;
        }

        $query->where('branch_id', $actor->branch_id);
    }
}
