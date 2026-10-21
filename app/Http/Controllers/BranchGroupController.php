<?php

namespace App\Http\Controllers;

use App\Models\BranchGroup;
use App\Models\User;
use App\Support\RbacBranchScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    private function visibleQuery(User $authUser)
    {
        $query = BranchGroup::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))
            ->with(['branch', 'rops:id,name,branch_id']);

        if ($authUser->usesAssignedGroupScope()) {
            if (!$authUser->hasPermission('branch_groups.scope.all') && !$authUser->hasPermission('branch_groups.scope.branch')) return $query->whereRaw('1 = 0');
            $query->withCount([
                'users' => fn ($users) => $users->where('branch_id', $authUser->branch_id)
                    ->whereHas('role', fn ($roles) => $roles->withPermission('users.subject.group_count')),
                'clients' => fn ($clients) => $clients->where('branch_id', $authUser->branch_id),
            ]);
            return app(\App\Support\RopGroupAccess::class)->scope($query, $authUser, 'branch_groups.id', 'branch_groups.branch_id');
        }
        $query->withCount(['users', 'clients']);

        if ($authUser->hasPermission('branch_groups.scope.all')) {
            return $query;
        }

        if (!$authUser->hasPermission('branch_groups.scope.branch') || empty($authUser->branch_id)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('branch_id', $authUser->branch_id);
    }

    private function ensureVisible(User $authUser, BranchGroup $branchGroup): void
    {
        if ($authUser->usesAssignedGroupScope()) {
            abort_unless(app(\App\Support\RopGroupAccess::class)->allowsGroup($authUser, $branchGroup->id), 404, 'NOT_FOUND');
        }

        $allowed = $this->visibleQuery($authUser)->withoutGlobalScope('active')
            // Authorization only needs the group id, not related directory counts.
            ->select('branch_groups.id')
            ->whereKey($branchGroup->id)
            ->exists();

        if (! $allowed) {
            if (($authUser->usesAssignedGroupScope() || $authUser->hasPermission('branch_groups.scope.branch'))) {
                $this->branchScope->denyBranchScopeViolation();
            }

            abort(403, 'Forbidden');
        }
    }

    private function ensureManageable(User $authUser, ?int $branchId = null, ?BranchGroup $branchGroup = null): void
    {
        abort_if($authUser->usesAssignedGroupScope(), 403, 'Forbidden');
        if ($authUser->hasPermission('branch_groups.manage.all')) {
            return;
        }

        abort_unless(
            $authUser->hasPermission('branch_groups.manage.branch') && !empty($authUser->branch_id),
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
        if ($authUser->hasPermission('branch_groups.manage.all')) {
            $branchId = $data['branch_id'] ?? null;
            abort_if(empty($branchId), 422, 'branch_id is required.');

            return (int) $branchId;
        }

        abort_if(empty($authUser->branch_id), 422, 'branch_id is required for this user.');

        return (int) $authUser->branch_id;
    }

    public function mergePreview(Request $request, BranchGroup $branchGroup)
    {
        $data = $request->validate(['target_group_id' => 'required|integer|exists:branch_groups,id',
            'include_all_properties' => 'sometimes|boolean']);
        return response()->json(app(\App\Services\GroupAccess\BranchGroupMerge::class)->preview(
            $this->authUser(), $branchGroup, BranchGroup::findOrFail($data['target_group_id']),
            (bool) ($data['include_all_properties'] ?? false)));
    }

    public function merge(Request $request, BranchGroup $branchGroup)
    {
        $data = $request->validate(['target_group_id' => 'required|integer|exists:branch_groups,id',
            'revision' => 'required|string|size:64', 'reason' => 'required|string|min:3|max:2000',
            'archive_source' => 'sometimes|boolean', 'include_all_properties' => 'sometimes|boolean']);
        return response()->json(app(\App\Services\GroupAccess\BranchGroupMerge::class)->merge(
            $this->authUser(), $branchGroup, BranchGroup::findOrFail($data['target_group_id']), $data['revision'], trim($data['reason']),
            (bool) ($data['archive_source'] ?? false), (bool) ($data['include_all_properties'] ?? false)));
    }

    public function index(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'include_archived' => 'sometimes|boolean',
            'search' => 'nullable|string',
            'name' => 'nullable|string',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'contact_visibility_mode' => ['nullable', Rule::in(BranchGroup::contactVisibilityModes())],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        // Attendance and HR user creation only need group labels, not CRM counts/settings.
        $query = ($authUser->hasPermission('branch_groups.labels.list') && !$authUser->hasPermission('branch_groups.scope.all') && !$authUser->hasPermission('branch_groups.scope.branch') && !$authUser->usesAssignedGroupScope())
            ? BranchGroup::query()->tap(fn ($q) => \App\Support\BranchVisibility::records($q))->select(['id', 'name', 'branch_id'])
            : $this->visibleQuery($authUser);

        if ($request->boolean('include_archived')) {
            $this->ensureManageable($authUser, !empty($validated['branch_id']) ? (int) $validated['branch_id'] : null);
            $query->withoutGlobalScope('active');
        }

        if (!empty($validated['search'])) {
            $query->where('name', 'like', '%' . trim($validated['search']) . '%');
        }

        if (!empty($validated['name'])) {
            $query->where('name', 'like', '%' . trim($validated['name']) . '%');
        }

        if (!empty($validated['branch_id'])) {
            if (($authUser->usesAssignedGroupScope() || $authUser->hasPermission('branch_groups.scope.branch'))) {
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
        return DB::transaction(function () use ($request) {
            $authUser = User::query()->lockForUpdate()->findOrFail($this->authUser()->id);
            abort_if($authUser->usesAssignedGroupScope(), 403, 'Forbidden');
            abort_unless(
                $authUser->hasPermission('branch_groups.manage.all') || $authUser->hasPermission('branch_groups.manage.branch'),
                403,
                'Forbidden'
            );

            $effectiveBranchId = $authUser->hasPermission('branch_groups.manage.all')
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
                $branchGroup->load(['branch', 'rops:id,name,branch_id'])->loadCount(['users', 'clients']),
                201
            );
        });
    }

    public function show(BranchGroup $branchGroup)
    {
        $authUser = $this->authUser();
        if ($authUser->hasPermission('branch_groups.labels.show') && !$authUser->hasPermission('branch_groups.scope.all') && !$authUser->hasPermission('branch_groups.scope.branch') && !$authUser->usesAssignedGroupScope()) {
            return response()->json($branchGroup->only(['id', 'name', 'branch_id']));
        }

        $this->ensureVisible($authUser, $branchGroup);

        return response()->json(
            $this->visibleQuery($authUser)->findOrFail($branchGroup->id)
        );
    }

    public function update(Request $request, BranchGroup $branchGroup)
    {
        return DB::transaction(function () use ($request, $branchGroup) {
            $authUser = User::query()->lockForUpdate()->findOrFail($this->authUser()->id);
            $branchGroup = BranchGroup::withoutGlobalScope('active')->lockForUpdate()->findOrFail($branchGroup->id);
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
                'is_archived' => 'sometimes|boolean',
            ]);

            $nextBranchId = array_key_exists('branch_id', $validated)
                ? $this->normalizedBranchId($validated, $authUser)
                : (int) $branchGroup->branch_id;

            $this->ensureManageable($authUser, $nextBranchId, $branchGroup);

            if (
                $nextBranchId !== (int) $branchGroup->branch_id
                && ($branchGroup->hasAssignedData(lock: true) || $branchGroup->hasAssignedRops(lock: true))
            ) {
                abort(422, 'Cannot change branch for a non-empty group.');
            }

            $data = array_intersect_key($validated, array_flip(['name', 'description', 'contact_visibility_mode', 'is_archived']));

            if (array_key_exists('branch_id', $validated)) {
                $data['branch_id'] = $nextBranchId;
            }

            $wasArchived = (bool) $branchGroup->is_archived;
            $branchGroup->update($data);
            if ($wasArchived !== (bool) $branchGroup->is_archived) {
                app(\App\Services\GroupAccess\GroupAccessAudit::class)->record($authUser,
                    $branchGroup->is_archived ? 'branch_group_archived' : 'branch_group_restored', 'branch_group', $branchGroup->id,
                    ['is_archived' => $wasArchived], ['is_archived' => (bool) $branchGroup->is_archived]);
            }

            return response()->json(
                $branchGroup->fresh()->load(['branch', 'rops:id,name,branch_id'])->loadCount(['users', 'clients'])
            );
        });
    }

    public function destroy(BranchGroup $branchGroup)
    {
        return DB::transaction(function () use ($branchGroup) {
            $authUser = User::query()->lockForUpdate()->findOrFail($this->authUser()->id);
            $branchGroup = BranchGroup::withoutGlobalScope('active')->lockForUpdate()->findOrFail($branchGroup->id);
            $this->ensureVisible($authUser, $branchGroup);
            $this->ensureManageable($authUser, branchGroup: $branchGroup);

            if ($branchGroup->hasAssignedData(lock: true) || $branchGroup->hasAssignedRops(lock: true)) {
                return response()->json([
                    'message' => 'Нельзя удалить группу: к ней привязаны пользователи или контакты.',
                ], 409);
            }

            $branchGroup->delete();

            return response()->json(['message' => 'Группа удалена']);
        });
    }


}
