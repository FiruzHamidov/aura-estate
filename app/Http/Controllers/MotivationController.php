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
        abort_unless($this->authUser()->hasPermission('motivation.rules.view'), 403, 'Forbidden');
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

        $actor = $this->authUser();
        $rules = $query->orderBy('id')->get()->map(function (MotivationRule $rule) use ($actor) {
            $data = $rule->toArray();
            $data['ui_meta'] = $this->service->applyUiMetaFallback($rule);
            $data['can_manage'] = app(\App\Services\Kpi\MotivationProgramService::class)->canManage($actor, $rule);

            return $data;
        });

        return response()->json(['data' => $rules]);
    }

    public function storeRule(Request $request)
    {
        $actor = $this->authUser();
        $this->ensureManageAccess($actor);
        $data = $request->validate($this->programRules());
        $rule = app(\App\Services\Kpi\MotivationProgramService::class)->save($actor, $data);
        return response()->json(['data' => $rule], 201);
    }

    public function updateRule(Request $request, MotivationRule $rule)
    {
        $actor = $this->authUser();
        $this->ensureManageAccess($actor);
        $data = $request->validate($this->programRules(true));
        $rule = app(\App\Services\Kpi\MotivationProgramService::class)->save($actor, $data, $rule->id);
        return response()->json(['data' => $rule]);
    }

    private function programRules(bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';
        return [
            'scope' => [$required, Rule::in(['agent', 'company'])],
            'metric_key' => $required.'|in:sales_count,result_goal',
            'threshold_value' => $required.'|numeric|min:0.01|max:9999',
            'reward_type' => $required.'|string|max:64',
            'name' => $required.'|string|max:255', 'description' => 'nullable|string|max:3000',
            'period_type' => [$required, Rule::in(['week', 'month', 'year'])],
            'date_from' => $required.'|date_format:Y-m-d', 'date_to' => $required.'|date_format:Y-m-d',
            'is_active' => 'sometimes|boolean', 'ui_meta' => 'sometimes|array',
            'expected_version' => ($update ? 'required' : 'sometimes').'|integer|min:1',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'condition' => 'nullable|array:source,kind,mode,count_target,amount_target,currency,rental_basis,goal_id',
            'condition.source' => ['nullable', Rule::in(['independent', 'kpi_goal'])],
            'condition.goal_id' => 'nullable|integer|exists:kpi_result_goals,id',
            'condition.kind' => ['required_with:condition', Rule::in(['sale', 'rental'])],
            'condition.mode' => ['required_with:condition', Rule::in(['count', 'amount', 'count_or_amount'])],
            'condition.count_target' => ['nullable', Rule::requiredIf(fn () => request('condition.source') !== 'kpi_goal' && in_array(request('condition.mode'), ['count', 'count_or_amount'])), 'integer', 'min:1', 'max:1000000'],
            'condition.amount_target' => ['nullable', Rule::requiredIf(fn () => request('condition.source') !== 'kpi_goal' && in_array(request('condition.mode'), ['amount', 'count_or_amount'])), 'numeric', 'min:0.01', 'max:999999999999.99', 'decimal:0,2'],
            'condition.currency' => ['required_with:condition', Rule::in(['TJS', 'USD'])],
            'condition.rental_basis' => ['nullable', 'required_if:condition.kind,rental', Rule::in(['monthly_rent', 'contract_total'])],
            'reward_amount' => 'nullable|required_if:reward_type,cash|numeric|min:0.01|max:999999999999.99|decimal:0,2',
            'reward_currency' => ['nullable', 'required_with:reward_amount', Rule::in(['TJS', 'USD'])],
        ];
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

        abort_unless($actor->hasPermission('motivation.achievements.view'), 403, 'Forbidden');
        foreach (['user_id', 'status', 'rule_id'] as $key) {
            if (isset($validated[$key])) {
                $query->where($key, $validated[$key]);
            }
        }
        if (array_key_exists('company_scope', $validated)) {
            $query->where('company_scope', (bool) $validated['company_scope']);
        }

        $rows = $query->orderByDesc('won_at')->get()->filter(function ($achievement) use ($actor) {
            if ($achievement->company_scope) return $actor->hasPermission('motivation.achievements.company');
            $scope = $achievement->meta['scope'] ?? [
                'user_id' => $achievement->user_id, 'branch_id' => $achievement->user?->branch_id,
                'branch_group_id' => $achievement->user?->branch_group_id,
            ];
            return app(\App\Support\KpiResultAccess::class)->visible($actor, $scope, 'motivation.achievements');
        })->values();
        return response()->json(['data' => $rows]);
    }

    public function recalculate(Request $request)
    {
        $actor = $this->authUser();
        $this->ensureManageAccess($actor);

        $validated = $request->validate([
            'rule_id' => 'nullable|integer|exists:motivation_rules,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'reason' => 'nullable|string|max:500',
            'preview' => 'sometimes|boolean',
            'preview_token' => 'nullable|string|size:64',
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

    public function transition(Request $request, MotivationAchievement $achievement)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['verify', 'approve', 'return', 'issue', 'resolve_review'])],
            'expected_version' => 'required|integer|min:0',
            'reason' => 'nullable|required_if:action,return,resolve_review|string|min:3|max:1000',
            'issued_at' => 'nullable|required_if:action,issue|date',
            'method' => ['nullable', 'required_if:action,issue', Rule::in(['cash', 'bank_transfer', 'trip', 'other'])],
            'proof' => 'nullable|required_if:action,issue|string|min:3|max:1000',
            'idempotency_key' => 'nullable|required_if:action,issue|uuid',
        ]);
        $result = app(\App\Services\Kpi\MotivationWorkflow::class)->transition($this->authUser(), $achievement->id, $data);
        return response()->json(['data' => $result]);
    }

    public function history(MotivationAchievement $achievement)
    {
        app(\App\Services\Kpi\MotivationWorkflow::class)->ensureVisible($this->authUser(), $achievement);
        return response()->json(['data' => \DB::table('motivation_events')->where('achievement_id', $achievement->id)->orderBy('id')->get()]);
    }

    public function assignRewardIssue(Request $request, MotivationAchievement $achievement)
    {
        $actor = $this->authUser();
        abort_unless($actor->hasPermission('motivation.issue'), 403);
        app(\App\Services\Kpi\MotivationWorkflow::class)->ensureVisible($actor, $achievement);
        $data = $request->validate(['assignee_id' => 'nullable|integer|exists:users,id', 'comment' => 'nullable|string|max:1000']);
        $issue = $this->service->upsertRewardIssue($achievement->id, $data + ['status' => 'in_progress']);
        return response()->json(['data' => $issue]);
    }

    public function updateRewardIssue(Request $request, MotivationRewardIssue $rewardIssue)
    {
        // Old clients cannot bypass verification and approval through the legacy route.
        abort_unless($this->authUser()->hasPermission('motivation.issue'), 403);
        app(\App\Services\Kpi\MotivationWorkflow::class)->ensureVisible($this->authUser(), $rewardIssue->achievement);
        if ($request->input('status') === 'issued') {
            $request->merge(['action' => 'issue']);
            return $this->transition($request, $rewardIssue->achievement);
        }
        $data = $request->validate(['assignee_id' => 'nullable|integer|exists:users,id', 'comment' => 'nullable|string|max:1000']);
        return response()->json(['data' => $this->service->upsertRewardIssue($rewardIssue->achievement_id, $data)]);
    }

    private function ensureManageAccess(User $user): void
    {
        if (! $user->hasPermission('motivation.manage')) {
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
        if (! $user->hasPermission('motivation.own.view')) {
            abort(response()->json([
                'code' => 'KPI_FORBIDDEN_ROLE_ACTION',
                'message' => 'Role is not allowed to perform this action.',
                'details' => (object) [],
                'trace_id' => request()->attributes->get('trace_id'),
            ], 403));
        }
    }
}
