<?php

namespace App\Http\Controllers;

use App\Models\CrmTask;
use App\Models\CrmTaskType;
use App\Models\User;
use App\Support\RbacBranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CrmTaskController extends Controller
{
    public function __construct(private readonly RbacBranchScope $branchScope)
    {
    }

    public function index(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'assignee_id' => 'nullable|integer|exists:users,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'task_type_code' => 'nullable|string|max:64',
            'status' => 'nullable|string|max:32',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if (! empty($validated['assignee_id']) && $this->branchScope->isBranchScopedManager($authUser)
            && ! app(\App\Support\RopGroupAccess::class)->hasVisibleTaskHistory($authUser, (int) $validated['assignee_id'])) {
            $this->branchScope->ensureUserInUserBranchOrDeny((int) $validated['assignee_id'], $authUser);
        }

        $query = CrmTask::query()->with(['type', 'assignee.role']);
        $this->applyVisibilityScope($query, $authUser);

        if (! empty($validated['assignee_id'])) {
            $query->where('assignee_id', (int) $validated['assignee_id']);
        }

        if (! empty($validated['branch_group_id'])) {
            $query->where('crm_tasks.branch_group_id', (int) $validated['branch_group_id']);
        }

        if (! empty($validated['task_type_code'])) {
            $code = trim((string) $validated['task_type_code']);
            $query->whereHas('type', fn (Builder $q) => $q->where('code', $code));
        }

        if (! empty($validated['status'])) {
            $query->where('status', trim((string) $validated['status']));
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        $page = $query->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 20))->withQueryString();
        app(\App\Services\GroupAccess\GroupDataProjection::class)->prepareTasks($page->getCollection(), $authUser);

        return response()->json($page);
    }

    public function store(Request $request)
    {
        $authUser = $this->authUser();

        $validated = $request->validate([
            'task_type_id' => 'required|integer|exists:crm_task_types,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'assignee_id' => 'required|integer|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => ['nullable', Rule::in(['new', 'in_progress', 'done', 'canceled', 'overdue'])],
            'result_code' => 'nullable|string|max:64',
            'related_entity_type' => ['nullable', Rule::in(['lead', 'client', 'deal', 'property', 'ad', 'showing'])],
            'related_entity_id' => 'nullable|integer',
            'due_at' => 'nullable|date',
            'completed_at' => 'nullable|date',
            'source' => ['nullable', Rule::in(['manual', 'system', 'integration'])],
        ]);

        return DB::transaction(function () use ($validated, $authUser) {
            $taskType = CrmTaskType::query()->findOrFail((int) $validated['task_type_id']);
            $this->validateTaskEntityBinding($taskType, $validated);
            $this->validateCallTaskPayload($taskType, $validated);

            $parent = app(\App\Services\GroupAccess\TaskGroupOwnership::class)->parent(new CrmTask($validated));
            [$authUser] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquireMany(
                $authUser, $parent ? [$parent] : [], [$validated['assignee_id']], [$validated['branch_group_id'] ?? null]
            );
            $assignee = User::query()->findOrFail($validated['assignee_id']);
            if ($this->branchScope->isBranchScopedManager($authUser)) {
                $this->branchScope->ensureUserInUserBranchOrDeny((int) $assignee->id, $authUser);
            }

            $task = CrmTask::query()->create(array_merge($validated, [
                'creator_id' => $authUser->id,
                'status' => $validated['status'] ?? 'new',
                'source' => $validated['source'] ?? 'manual',
            ]));

            return response()->json($task->fresh(['type', 'assignee.role']), 201);
        });
    }

    public function update(Request $request, CrmTask $crmTask)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => ['nullable', Rule::in(['new', 'in_progress', 'done', 'canceled', 'overdue'])],
            'result_code' => 'nullable|string|max:64',
            'due_at' => 'nullable|date',
            'completed_at' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($validated, $crmTask) {
            $ownership = app(\App\Services\GroupAccess\TaskGroupOwnership::class);
            $draft = (clone $crmTask)->fill($validated);
            $parent = $ownership->isReopening($draft) ? $ownership->parent($draft) : null;
            [$authUser, $locked] = app(\App\Services\GroupAccess\GroupRecordWriteLock::class)->acquireMany(
                $this->authUser(), array_filter(['task' => $crmTask, 'parent' => $parent])
            );
            $crmTask = $locked['task'];
            $this->ensureTaskVisible($crmTask, $authUser);
            // A concurrent completion may turn this edit into a reopen after waiting.
            $freshDraft = (clone $crmTask)->fill($validated);
            abort_if($ownership->isReopening($freshDraft) !== $ownership->isReopening($draft), 409, 'TASK_STATE_CHANGED');
            $this->validateCallTaskPayload($crmTask->type, array_merge($crmTask->getAttributes(), $validated));
            $crmTask->update($validated);

            return response()->json($crmTask->fresh(['type', 'assignee.role']));
        });
    }

    private function applyVisibilityScope(Builder $query, User $authUser): void
    {
        $authUser->loadMissing('role');

        match ($authUser->role?->slug) {
            'admin', 'superadmin' => null,
            'rop' => app(\App\Support\RopGroupAccess::class)->scope($query, $authUser, 'crm_tasks.branch_group_id'),
            'branch_director' => $query->whereHas('assignee', fn (Builder $q) => $q->where('branch_id', $authUser->branch_id)),
            'mop' => $query->whereHas('assignee', fn (Builder $q) => $q->where('branch_group_id', $authUser->branch_group_id)),
            default => $query->where('assignee_id', $authUser->id),
        };
    }

    private function ensureTaskVisible(CrmTask $task, User $authUser): void
    {
        app(\App\Support\RopGroupAccess::class)->ensureVisible($authUser, $task);
        $query = CrmTask::query()->whereKey($task->id);
        $this->applyVisibilityScope($query, $authUser);
        abort_unless($query->exists(), 403, 'Forbidden');
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();
        abort_unless($user, 401, 'Unauthenticated.');
        $user->loadMissing('role');

        return $user;
    }

    private function validateTaskEntityBinding(CrmTaskType $taskType, array $payload): void
    {
        $entityType = $payload['related_entity_type'] ?? null;
        $entityId = $payload['related_entity_id'] ?? null;
        $code = $taskType->code;

        if (str_starts_with($code, 'SHOWING_') || $code === 'SHOWING' || $code === 'MEETING_OFFICE') {
            abort_if(! in_array($entityType, ['showing', 'property'], true) || empty($entityId), 422, 'Showing tasks require related_entity_type=showing|property and related_entity_id.');
        }

        if (str_starts_with($code, 'AD_') || $code === 'AD_PUBLICATION') {
            abort_if($entityType !== 'ad' || empty($entityId), 422, 'Ad tasks require related_entity_type=ad and related_entity_id.');
        }

        if (in_array($code, ['CALL', 'LEAD_ACCEPT', 'FOLLOW_UP', 'DOCUMENT_REQUEST'], true)) {
            abort_if(! in_array($entityType, ['lead', 'client', 'deal'], true) || empty($entityId), 422, 'CRM contact tasks require related_entity_type=lead|client|deal and related_entity_id.');
        }
    }

    private function validateCallTaskPayload(CrmTaskType $taskType, array $payload): void
    {
        if ($taskType->code !== 'CALL') {
            return;
        }

        abort_if(($payload['status'] ?? 'new') === 'done' && empty($payload['result_code']), 422, 'CALL task with status=done requires result_code.');
        abort_if(($payload['status'] ?? 'new') === 'done' && empty($payload['completed_at']), 422, 'CALL task with status=done requires completed_at.');
    }
}
