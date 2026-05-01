<?php

namespace App\Http\Controllers;

use App\Models\BranchGroup;
use App\Models\User;
use App\Support\RbacBranchScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class BranchGroupController extends Controller
{
    public function __construct(private readonly RbacBranchScope $branchScope)
    {
    }

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
        return in_array($roleSlug, ['superadmin', 'admin', 'marketing'], true);
    }

    private function isBranchScopedManager(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['branch_director', 'rop'], true);
    }

    private function isBranchScopedRole(?string $roleSlug): bool
    {
        return in_array($roleSlug, ['branch_director', 'rop', 'agent', 'manager', 'operator'], true);
    }

    private function visibleQuery(User $authUser)
    {
        $roleSlug = $this->roleSlug($authUser);

        $query = BranchGroup::query()
            ->with('branch')
            ->withCount(['users', 'clients']);

        if ($this->isPrivilegedRole($roleSlug)) {
            return $query;
        }

        if (!$this->isBranchScopedRole($roleSlug) || empty($authUser->branch_id)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('branch_id', $authUser->branch_id);
    }

    private function ensureVisible(User $authUser, BranchGroup $branchGroup): void
    {
        $allowed = $this->visibleQuery($authUser)
            ->whereKey($branchGroup->id)
            ->exists();

        if (! $allowed) {
            if ($this->branchScope->isBranchScopedManager($authUser)) {
                $this->branchScope->denyBranchScopeViolation();
            }

            abort(403, 'Forbidden');
        }
    }

    private function ensureManageable(User $authUser, ?int $branchId = null, ?BranchGroup $branchGroup = null): void
    {
        $roleSlug = $this->roleSlug($authUser);

        if ($this->isPrivilegedRole($roleSlug)) {
            return;
        }

        abort_unless(
            $this->isBranchScopedManager($roleSlug) && !empty($authUser->branch_id),
            403,
            'Forbidden'
        );

        if ($branchId !== null && (int) $branchId !== (int) $authUser->branch_id) {
            abort(422, 'branch_id must match your branch.');
        }

        if ($branchGroup && (int) $branchGroup->branch_id !== (int) $authUser->branch_id) {
            $this->branchScope->denyBranchScopeViolation();
        }
    }

    private function normalizedBranchId(array $data, User $authUser): int
    {
        if ($this->isPrivilegedRole($this->roleSlug($authUser))) {
            $branchId = $data['branch_id'] ?? null;
            abort_if(empty($branchId), 422, 'branch_id is required.');

            return (int) $branchId;
        }

        abort_if(empty($authUser->branch_id), 422, 'branch_id is required for this user.');

        return (int) $authUser->branch_id;
    }

    public function index(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'search' => 'nullable|string',
            'name' => 'nullable|string',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'contact_visibility_mode' => ['nullable', Rule::in(BranchGroup::contactVisibilityModes())],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $this->visibleQuery($authUser);

        if (!empty($validated['search'])) {
            $query->where('name', 'like', '%' . trim($validated['search']) . '%');
        }

        if (!empty($validated['name'])) {
            $query->where('name', 'like', '%' . trim($validated['name']) . '%');
        }

        if (!empty($validated['branch_id'])) {
            if ($this->branchScope->isBranchScopedManager($authUser)) {
                $this->branchScope->ensureSameBranchOrDeny((int) $validated['branch_id'], $authUser);
            }

            $query->where('branch_id', $validated['branch_id']);
        }

        if (!empty($validated['contact_visibility_mode'])) {
            $query->where('contact_visibility_mode', $validated['contact_visibility_mode']);
        }

        return response()->json(
            $query->orderBy('name')
                ->orderBy('id')
                ->paginate((int) ($validated['per_page'] ?? 15))
                ->withQueryString()
        );
    }

    public function store(Request $request)
    {
        $authUser = $this->authUser();
        $roleSlug = $this->roleSlug($authUser);

        abort_unless(
            $this->isPrivilegedRole($roleSlug) || $this->isBranchScopedManager($roleSlug),
            403,
            'Forbidden'
        );

        $effectiveBranchId = $this->isPrivilegedRole($roleSlug)
            ? $request->integer('branch_id')
            : (int) $authUser->branch_id;

        $validated = $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id',
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('branch_groups', 'name')->where(
                    fn ($query) => $query->where('branch_id', $effectiveBranchId)
                ),
            ],
            'description' => 'nullable|string',
            'contact_visibility_mode' => ['required', Rule::in(BranchGroup::contactVisibilityModes())],
        ]);

        $branchId = $this->normalizedBranchId($validated, $authUser);
        $this->ensureManageable($authUser, $branchId);

        $branchGroup = BranchGroup::create([
            'branch_id' => $branchId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'contact_visibility_mode' => $validated['contact_visibility_mode'],
        ]);

        return response()->json(
            $branchGroup->load('branch')->loadCount(['users', 'clients']),
            201
        );
    }

    public function show(BranchGroup $branchGroup)
    {
        $this->ensureVisible($this->authUser(), $branchGroup);

        return response()->json(
            $branchGroup->load('branch')->loadCount(['users', 'clients'])
        );
    }

    public function update(Request $request, BranchGroup $branchGroup)
    {
        $authUser = $this->authUser();
        $this->ensureVisible($authUser, $branchGroup);
        $this->ensureManageable($authUser, branchGroup: $branchGroup);

        $requestedBranchId = $request->has('branch_id')
            ? $request->integer('branch_id')
            : $branchGroup->branch_id;

        $validated = $request->validate([
            'branch_id' => 'sometimes|integer|exists:branches,id',
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('branch_groups', 'name')
                    ->ignore($branchGroup->id)
                    ->where(fn ($query) => $query->where('branch_id', $requestedBranchId)),
            ],
            'description' => 'sometimes|nullable|string',
            'contact_visibility_mode' => ['sometimes', Rule::in(BranchGroup::contactVisibilityModes())],
        ]);

        $nextBranchId = array_key_exists('branch_id', $validated)
            ? $this->normalizedBranchId($validated, $authUser)
            : (int) $branchGroup->branch_id;

        $this->ensureManageable($authUser, $nextBranchId, $branchGroup);

        if (
            $nextBranchId !== (int) $branchGroup->branch_id
            && ($branchGroup->users()->exists() || $branchGroup->clients()->exists())
        ) {
            abort(422, 'Cannot change branch for a non-empty group.');
        }

        $data = $request->only(['name', 'description', 'contact_visibility_mode']);

        if (array_key_exists('branch_id', $validated)) {
            $data['branch_id'] = $nextBranchId;
        }

        $branchGroup->update($data);

        return response()->json(
            $branchGroup->fresh()->load('branch')->loadCount(['users', 'clients'])
        );
    }

    public function destroy(BranchGroup $branchGroup)
    {
        $authUser = $this->authUser();
        $this->ensureVisible($authUser, $branchGroup);
        $this->ensureManageable($authUser, branchGroup: $branchGroup);

        if ($branchGroup->users()->exists() || $branchGroup->clients()->exists()) {
            return response()->json([
                'message' => 'Нельзя удалить группу: к ней привязаны пользователи или контакты.',
            ], 409);
        }

        $branchGroup->delete();

        return response()->json(['message' => 'Группа удалена']);
    }
}
