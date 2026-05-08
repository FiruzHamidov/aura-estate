<?php

namespace App\Http\Controllers;

use App\Models\MotivationAchievement;
use App\Models\MotivationRewardIssue;
use App\Models\MotivationRule;
use App\Models\User;
use App\Services\MotivationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class MotivationController extends Controller
{
    public function __construct(private readonly MotivationService $service)
    {
    }

    public function rules(Request $request)
    {
        $validated = $request->validate([
            'scope' => ['nullable', Rule::in(['agent', 'company'])],
            'period_type' => ['nullable', Rule::in(['week', 'month', 'year'])],
            'is_active' => 'nullable|boolean',
        ]);

        $query = MotivationRule::query();

        if (isset($validated['scope'])) {
            $query->where('scope', $validated['scope']);
        }
        if (isset($validated['period_type'])) {
            $query->where('period_type', $validated['period_type']);
        }
        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        $rules = $query->orderBy('id')->get()->map(function (MotivationRule $rule) {
            $data = $rule->toArray();
            $data['ui_meta'] = $this->service->applyUiMetaFallback($rule);

            return $data;
        });

        return response()->json(['data' => $rules]);
    }

    public function storeRule(Request $request)
    {
        $actor = $this->authUser();
        $this->ensureManageAccess($actor);

        $validated = $request->validate([
            'scope' => ['required', Rule::in(['agent', 'company'])],
            'metric_key' => 'required|string|max:64',
            'threshold_value' => 'required|numeric|min:0',
            'reward_type' => 'required|string|max:64',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'period_type' => ['required', Rule::in(['week', 'month', 'year'])],
            'date_from' => 'required|date_format:Y-m-d',
            'date_to' => 'required|date_format:Y-m-d',
            'is_active' => 'sometimes|boolean',
            'ui_meta' => 'sometimes|array',
        ]);

        $this->service->validatePeriod($validated['period_type'], $validated['date_from'], $validated['date_to']);
        $this->service->ensureNoRuleOverlap($validated);

        $rule = MotivationRule::query()->create(array_merge($validated, [
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]));

        return response()->json(['data' => $rule], 201);
    }

    public function updateRule(Request $request, MotivationRule $rule)
    {
        $actor = $this->authUser();
        $this->ensureManageAccess($actor);

        $validated = $request->validate([
            'scope' => ['sometimes', Rule::in(['agent', 'company'])],
            'metric_key' => 'sometimes|string|max:64',
            'threshold_value' => 'sometimes|numeric|min:0',
            'reward_type' => 'sometimes|string|max:64',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'period_type' => ['sometimes', Rule::in(['week', 'month', 'year'])],
            'date_from' => 'sometimes|date_format:Y-m-d',
            'date_to' => 'sometimes|date_format:Y-m-d',
            'is_active' => 'sometimes|boolean',
            'ui_meta' => 'sometimes|array',
        ]);

        $periodType = $validated['period_type'] ?? $rule->period_type;
        $dateFrom = $validated['date_from'] ?? Carbon::parse((string) $rule->date_from, MotivationService::TZ)->toDateString();
        $dateTo = $validated['date_to'] ?? Carbon::parse((string) $rule->date_to, MotivationService::TZ)->toDateString();

        $this->service->validatePeriod($periodType, $dateFrom, $dateTo);
        $overlapPayload = [
            'scope' => $validated['scope'] ?? $rule->scope,
            'metric_key' => $validated['metric_key'] ?? $rule->metric_key,
            'reward_type' => $validated['reward_type'] ?? $rule->reward_type,
            'period_type' => $periodType,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
        $this->service->ensureNoRuleOverlap($overlapPayload, (int) $rule->id);

        $rule->fill($validated);
        $rule->updated_by = $actor->id;
        $rule->save();

        return response()->json(['data' => $rule->fresh()]);
    }

    public function achievements(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'status' => ['nullable', Rule::in(['new', 'approved', 'issued', 'cancelled'])],
            'rule_id' => 'nullable|integer|exists:motivation_rules,id',
            'company_scope' => 'nullable|boolean',
        ]);

        $actor = $this->authUser();

        $query = MotivationAchievement::query()->with(['rule', 'rewardIssue', 'user']);

        $role = (string) ($actor->role?->slug ?? '');
        if (in_array($role, ['admin', 'superadmin', 'owner'], true)) {
            // Global access.
        } elseif (in_array($role, ['rop', 'branch_director'], true) && $actor->branch_id !== null) {
            $query->where(function ($builder) use ($actor) {
                $builder->where('company_scope', true)
                    ->orWhereHas('user', fn ($q) => $q->where('branch_id', (int) $actor->branch_id));
            });
        } elseif ($role === 'mop' && $actor->branch_group_id !== null) {
            $query->where(function ($builder) use ($actor) {
                $builder->where('company_scope', true)
                    ->orWhereHas('user', fn ($q) => $q->where('branch_group_id', (int) $actor->branch_group_id));
            });
        } else {
            $query->where(function ($builder) use ($actor) {
                $builder->where('company_scope', true)
                    ->orWhere('user_id', $actor->id);
            });
        }

        foreach (['user_id', 'status', 'rule_id'] as $key) {
            if (isset($validated[$key])) {
                $query->where($key, $validated[$key]);
            }
        }
        if (array_key_exists('company_scope', $validated)) {
            $query->where('company_scope', (bool) $validated['company_scope']);
        }

        return response()->json(['data' => $query->orderByDesc('won_at')->get()]);
    }

    public function recalculate(Request $request)
    {
        $actor = $this->authUser();
        $this->ensureManageAccess($actor);

        $validated = $request->validate([
            'rule_id' => 'nullable|integer|exists:motivation_rules,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $result = $this->service->recalculate($validated, $actor);

        return response()->json(['data' => $result]);
    }

    public function myOverview(Request $request)
    {
        $actor = $this->authUser();
        $this->ensureOverviewAccess($actor);

        $validated = $request->validate([
            'period_type' => ['required', Rule::in(['week', 'month', 'year'])],
            'date_from' => 'required|date_format:Y-m-d',
            'date_to' => 'required|date_format:Y-m-d',
        ]);

        $this->service->validatePeriod($validated['period_type'], $validated['date_from'], $validated['date_to']);
        $payload = $this->service->buildOverview(
            $actor,
            $validated['period_type'],
            $validated['date_from'],
            $validated['date_to']
        );

        return response()->json($payload);
    }

    public function assignRewardIssue(Request $request, MotivationAchievement $achievement)
    {
        $actor = $this->authUser();
        $this->ensureManageAccess($actor);

        $validated = $request->validate([
            'assignee_id' => 'nullable|integer|exists:users,id',
            'comment' => 'nullable|string',
        ]);

        $issue = $this->service->upsertRewardIssue($achievement->id, [
            'assignee_id' => $validated['assignee_id'] ?? null,
            'comment' => $validated['comment'] ?? null,
            'status' => 'in_progress',
        ]);

        return response()->json(['data' => $issue]);
    }

    public function updateRewardIssue(Request $request, MotivationRewardIssue $rewardIssue)
    {
        $actor = $this->authUser();
        $this->ensureManageAccess($actor);

        $validated = $request->validate([
            'assignee_id' => 'nullable|integer|exists:users,id',
            'status' => ['sometimes', Rule::in(['new', 'in_progress', 'issued', 'rejected'])],
            'comment' => 'nullable|string',
        ]);

        $issue = $this->service->upsertRewardIssue($rewardIssue->achievement_id, $validated);

        if (($validated['status'] ?? null) === 'issued') {
            $achievement = $issue->achievement;
            if ($achievement && $achievement->status !== 'issued') {
                $achievement->status = 'issued';
                $achievement->issued_by = $actor->id;
                $achievement->issued_at = Carbon::now(MotivationService::TZ);
                $achievement->save();
            }
        }

        return response()->json(['data' => $issue->fresh(['achievement'])]);
    }

    private function ensureManageAccess(User $user): void
    {
        if (! in_array($user->role?->slug, ['rop', 'branch_director', 'admin', 'superadmin', 'owner'], true)) {
            abort(response()->json([
                'code' => 'KPI_FORBIDDEN_ROLE_ACTION',
                'message' => 'Role is not allowed to perform this action.',
                'details' => (object) [],
                'trace_id' => request()->attributes->get('trace_id'),
            ], 403));
        }
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = Auth::user();
        abort_unless($user, 401, 'Unauthenticated.');
        $user->loadMissing('role');

        return $user;
    }

    private function ensureOverviewAccess(User $user): void
    {
        if (! in_array($user->role?->slug, ['agent', 'mop'], true)) {
            abort(response()->json([
                'code' => 'KPI_FORBIDDEN_ROLE_ACTION',
                'message' => 'Role is not allowed to perform this action.',
                'details' => (object) [],
                'trace_id' => request()->attributes->get('trace_id'),
            ], 403));
        }
    }
}
