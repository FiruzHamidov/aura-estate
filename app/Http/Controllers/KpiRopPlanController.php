<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\KpiRopPlanService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KpiRopPlanController extends Controller
{
    private const METRIC_WHITELIST = ['objects', 'shows', 'ads', 'calls', 'sales'];

    public function __construct(private readonly KpiRopPlanService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'role' => ['nullable', Rule::in(['agent', 'intern', 'mop', 'rop'])],
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
        ]);

        $data = $this->service->list($this->authUser(), $validated)->values();

        return response()->json([
            'data' => $data,
            'plans' => $data,
            'source' => 'rop_plan',
            'meta' => [
                'exists' => $data->isNotEmpty(),
                'source' => 'rop_plan',
                'storage_unit' => 'monthly',
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $plan = $this->service->get($this->authUser(), $id);
        if ($plan === null) {
            return $this->kpiError('KPI_PLAN_NOT_FOUND', 'KPI ROP plan not found.', 404);
        }

        return response()->json($plan);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePlanPayload($request, false);

        try {
            $plan = $this->service->create($this->authUser(), $validated);
        } catch (\DomainException $e) {
            return $this->kpiError('KPI_PLAN_PERIOD_CONFLICT', $e->getMessage(), 409);
        }

        return response()->json($plan, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $this->validatePlanPayload($request, true);

        try {
            $plan = $this->service->update($this->authUser(), $id, $validated);
        } catch (\DomainException $e) {
            return $this->kpiError('KPI_PLAN_PERIOD_CONFLICT', $e->getMessage(), 409);
        }

        if ($plan === null) {
            return $this->kpiError('KPI_PLAN_NOT_FOUND', 'KPI ROP plan not found.', 404);
        }

        return response()->json($plan);
    }

    public function copy(Request $request, int $id): JsonResponse
    {
        $validated = $this->validatePlanPayload($request, true, true);

        try {
            $plan = $this->service->copy($this->authUser(), $id, $validated);
        } catch (\DomainException $e) {
            return $this->kpiError('KPI_PLAN_PERIOD_CONFLICT', $e->getMessage(), 409);
        }

        if ($plan === null) {
            return $this->kpiError('KPI_PLAN_NOT_FOUND', 'KPI ROP plan not found.', 404);
        }

        return response()->json($plan, 201);
    }

    private function validatePlanPayload(Request $request, bool $partial = false, bool $copyMode = false): array
    {
        $rules = [
            'role' => [$partial ? 'sometimes' : 'required', Rule::in(['agent', 'intern', 'mop', 'rop'])],
            'month' => [$partial ? 'sometimes' : 'required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id',
            'items' => [($partial || $copyMode) ? 'sometimes' : 'required', 'array', 'min:1'],
            'items.*.metric' => ['nullable', 'required_without:items.*.metric_key', 'string', 'max:64'],
            'items.*.metric_key' => ['nullable', 'required_without:items.*.metric', 'string', 'max:64'],
            'items.*.plan_value' => 'nullable|numeric|min:0',
            'items.*.monthly_plan' => 'nullable|numeric|min:0',
            'items.*.weight' => 'required_with:items|numeric|min:0|max:1',
            'items.*.comment' => 'nullable|string|max:500',
        ];

        $validated = $request->validate($rules);

        if (array_key_exists('items', $validated)) {
            $this->validateMetricWhitelist((array) $validated['items']);
            $this->validateWeightSum((array) $validated['items']);
        }

        return $validated;
    }

    private function validateMetricWhitelist(array $items): void
    {
        $validator = Validator::make(['items' => $items], []);

        $validator->after(function ($validator) use ($items) {
            foreach ($items as $i => $item) {
                $hasMetricKey = isset($item['metric_key']) && $item['metric_key'] !== null && $item['metric_key'] !== '';
                $hasMetric = isset($item['metric']) && $item['metric'] !== null && $item['metric'] !== '';

                if ($hasMetricKey && ! in_array((string) $item['metric_key'], self::METRIC_WHITELIST, true)) {
                    $validator->errors()->add("items.$i.metric_key", 'Unsupported metric');
                }

                if ($hasMetric && ! in_array((string) $item['metric'], self::METRIC_WHITELIST, true)) {
                    $validator->errors()->add("items.$i.metric", 'Unsupported metric');
                }
            }
        });

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }
    }

    private function validateWeightSum(array $items): void
    {
        $sum = round((float) collect($items)->sum(fn (array $item) => (float) ($item['weight'] ?? 0)), 4);
        if (abs($sum - 1.0) > 0.0001) {
            throw ValidationException::withMessages([
                'items' => ['The sum of all items.weight must be exactly 1.0.'],
            ]);
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

    private function kpiError(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'details' => (object) $details,
            'trace_id' => request()->attributes->get('trace_id'),
        ], $status);
    }
}
