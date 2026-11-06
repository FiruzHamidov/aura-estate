<?php

namespace App\Http\Controllers;

use App\Services\Kpi\PlanningDraftService;
use App\Services\Kpi\PlanningScope;
use Illuminate\Http\Request;

final class KpiPlanningController extends Controller
{
    public function catalog(Request $request, PlanningScope $scope)
    {
        $data = $request->validate(['type' => 'required|in:goal,program']);
        $scope->ensureManager($request->user(), $data['type']);

        return response()->json(['data' => ['groups' => $scope->groups($request->user()),
            'users' => $scope->users($request->user(), $data['type'] === 'program')->orderBy('name')->orderBy('id')->get()]]);
    }

    public function readiness(Request $request, PlanningDraftService $service)
    {
        $data = $request->validate(['month' => 'required|date_format:Y-m', 'kind' => 'required|in:sale,rental',
            'audience' => 'required|in:group,branch,company',
            'branch_group_id' => 'nullable|required_if:audience,group|prohibited_unless:audience,group|integer|exists:branch_groups,id',
            'branch_id' => 'nullable|required_if:audience,branch|prohibited_unless:audience,branch|integer|exists:branches,id']);

        return response()->json(['data' => $service->readiness($request->user(), $data)]);
    }

    public function roster(Request $request, PlanningDraftService $service)
    {
        $data = $request->validate(['month' => 'required|date_format:Y-m']);

        return response()->json(['data' => $service->roster($request->user(), $data['month'])]);
    }

    public function drafts(Request $request, PlanningDraftService $service)
    {
        $data = $request->validate(['type' => 'required|in:goal,program', 'month' => 'nullable|date_format:Y-m']);

        return response()->json(['data' => $service->drafts($request->user(), $data['type'], $data['month'] ?? null)]);
    }

    public function save(Request $request, PlanningDraftService $service, ?int $draft = null)
    {
        $data = $request->validate(['type' => 'required|in:goal,program', 'payload' => 'present|array',
            'step' => 'required|integer|min:1|max:4', 'revision' => 'required|integer|min:0',
            'entity_id' => 'nullable|integer|exists:motivation_rules,id']);

        return response()->json(['data' => $service->save($request->user(), $data, $draft)], $draft ? 200 : 201);
    }

    public function preview(Request $request, PlanningDraftService $service, int $draft)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);

        return response()->json(['data' => $service->inspect($request->user(), $draft, $data['revision'])]);
    }

    public function publish(Request $request, PlanningDraftService $service, int $draft)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'preview_token' => 'required|string|size:64']);

        return response()->json(['data' => $service->inspect($request->user(), $draft, $data['revision'], $data['preview_token'])], 201);
    }

    public function copyMonth(Request $request, PlanningDraftService $service)
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m', 'to' => 'required|date_format:Y-m',
            'branch_group_id' => 'nullable|integer|exists:branch_groups,id']);

        return response()->json(['data' => $service->copyMonth($request->user(), $data)]);
    }
}
