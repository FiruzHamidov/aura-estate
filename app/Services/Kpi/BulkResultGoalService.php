<?php

namespace App\Services\Kpi;

use App\Models\KpiPlanningDraft;
use App\Models\User;
use App\Services\GroupAccess\GroupRecordWriteLock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Review and publish a bounded set of individual goals as one transaction. */
final class BulkResultGoalService
{
    public function inspect(User $actor, array $input, ?string $token = null): array
    {
        $data = Validator::make($input, [
            'month' => 'required|date_format:Y-m',
            'kind' => 'required|in:sale,rental',
            'mode' => 'required|in:count,amount,count_or_amount',
            'currency' => 'required|in:TJS,USD',
            'amount_basis' => ['nullable', 'required_unless:mode,count', \Illuminate\Validation\Rule::in(ResultAmount::BASES)],
            'rental_basis' => 'nullable|in:monthly_rent,contract_total',
            'rows' => 'required|array|min:1|max:200',
            'rows.*.user_id' => 'required|integer|distinct|exists:users,id',
            'rows.*.expected_version' => 'required|integer|min:0',
            'rows.*.count_target' => 'nullable|integer|min:1|max:1000000',
            'rows.*.amount_target' => 'nullable|numeric|min:0.01|max:999999999999.99|decimal:0,2',
        ])->validated();

        return DB::transaction(function () use ($actor, $data, $token) {
            [$actor] = app(GroupRecordWriteLock::class)->acquireMany($actor, [], array_column($data['rows'], 'user_id'));
            $scope = app(PlanningScope::class);
            $scope->ensureManager($actor, 'goal');
            abort_unless($actor->hasPermission('kpi.plans.user.manage'), 403);
            $month = CarbonImmutable::now('Asia/Dushanbe')->format('Y-m');
            if ($data['month'] < $month) {
                throw ValidationException::withMessages(['month' => 'Прошлые периоды зафиксированы. Выберите текущий или будущий месяц.']);
            }
            $users = $scope->users($actor)->whereIn('id', array_column($data['rows'], 'user_id'))->get()->keyBy('id');
            abort_unless($users->count() === count($data['rows']), 403);
            $goals = app(ResultGoalService::class);
            $review = [];
            foreach ($data['rows'] as $index => $row) {
                $payload = array_diff_key($data, ['rows' => true]) + $row;
                $validator = Validator::make($payload, ResultGoalService::rules($payload));
                if ($validator->fails()) {
                    throw ValidationException::withMessages(collect($validator->errors()->messages())
                        ->mapWithKeys(fn ($messages, $field) => ['rows.'.$index.'.'.$field => $messages])->all());
                }
                $payload = $validator->validated();
                $scope->authorize($actor, 'goal', $payload);
                $user = $users->get($row['user_id']);
                $current = $goals->resolve($user->id, $user->branch_group_id, $data['month'], $data['kind'], true);
                $personalVersion = ! empty($current['user_id']) ? (int) $current['version'] : 0;
                if ($personalVersion !== (int) $row['expected_version']) {
                    $this->conflict();
                }
                if ($current && $data['month'] === $month) {
                    throw ValidationException::withMessages(['rows.'.$index => $user->name.': цель текущего месяца уже зафиксирована.']);
                }
                $draft = KpiPlanningDraft::where('type', 'goal')->where('month', $data['month'])
                    ->where('payload->kind', $data['kind'])
                    ->where(fn ($q) => $q->where('user_id', $user->id)
                        ->when(empty($current['user_id']) && $user->branch_group_id, fn ($q) => $q->orWhere(fn ($q) => $q->whereNull('user_id')->where('branch_group_id', $user->branch_group_id))))
                    ->lockForUpdate()->first();
                if ($draft) {
                    throw ValidationException::withMessages(['rows.'.$index => $user->name.': есть черновик цели. Сначала завершите его.']);
                }
                $review[] = ['user_id' => $user->id, 'name' => $user->name, 'branch_id' => $user->branch_id,
                    'branch_group_id' => $user->branch_group_id, 'current' => $current, 'condition' => $payload];
            }
            $fingerprint = hash_hmac('sha256', json_encode([$actor->id, $actor->access_scope_version, $review], JSON_THROW_ON_ERROR), config('app.key'));
            if ($token === null) {
                return ['rows' => $review, 'preview_token' => $fingerprint];
            }
            if (! hash_equals($fingerprint, $token)) {
                $this->conflict();
            }
            // All rows have been authorized and rechecked while the member/group locks are held.
            // Any failure below rolls back the entire selection, including previously inserted rows.
            $published = array_map(fn ($row) => $goals->publish($actor, $row['condition']), $review);

            return ['published' => $published, 'count' => count($published)];
        }, 3);
    }

    private function conflict(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Цели или состав команды изменились. Значения остались в форме — проверьте их ещё раз.',
            'code' => 'BULK_GOAL_PREVIEW_CHANGED',
        ], 409));
    }
}
