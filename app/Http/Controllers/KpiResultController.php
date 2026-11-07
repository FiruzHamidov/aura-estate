<?php

namespace App\Http\Controllers;

use App\Services\Kpi\ResultGoalService;
use App\Services\Kpi\ResultReportService;
use Illuminate\Http\Request;

final class KpiResultController extends Controller
{
    public function adjust(Request $request, \App\Services\Kpi\ResultPeriodAdjustment $service)
    {
        $data = $request->validate([
            'month' => 'required|date_format:Y-m', 'user_id' => 'required|integer|exists:users,id',
            'preview' => 'required|boolean', 'preview_token' => 'required_if:preview,false|string|size:64',
            'reason' => 'required_if:preview,false|string|min:3|max:1000',
        ]);

        return response()->json(['data' => $service->run($request->user(), $data)]);
    }

    public function periodHistory(Request $request)
    {
        $data = $request->validate(['month' => 'required|date_format:Y-m', 'user_id' => 'required|integer']);
        $actor = $request->user();
        abort_unless($actor->hasPermission('kpi.adjustments.view'), 403);
        $period = \DB::table('kpi_result_periods')->where('month', $data['month'].'-01')->where('user_id', $data['user_id'])->first();
        abort_unless($period && app(\App\Support\KpiResultAccess::class)->visible($actor, $period), 403);

        return response()->json(['data' => \DB::table('kpi_result_period_revisions')->where('period_id', $period->id)->orderBy('version')->get(['version', 'reason', 'actor_id', 'created_at'])]);
    }

    public function goals(Request $request)
    {
        $data = $request->validate(['month' => 'required|date_format:Y-m']);
        $actor = $request->user();
        abort_unless($actor->hasPermission('kpi.plans.user.view') || $actor->hasPermission('kpi.plans.common.view'), 403);
        $goals = \DB::table('kpi_result_goals')->where('month', $data['month'].'-01')->orderByDesc('version')->get()
            ->filter(fn ($goal) => app(\App\Support\KpiResultAccess::class)->visible($actor, $goal, 'kpi.plans'))->values();

        return response()->json(['data' => $goals]);
    }

    public function index(Request $request, ResultReportService $service)
    {
        $filters = $request->validate([
            'month' => 'required|date_format:Y-m', 'user_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer', 'branch_group_id' => 'nullable|integer',
        ]);

        return response()->json($service->report($request->user(), $filters));
    }

    public function publish(Request $request, ResultGoalService $service)
    {
        $data = $request->validate(ResultGoalService::rules($request->all()));

        return response()->json(['data' => $service->publish($request->user(), $data)], 201);
    }

    public function close(Request $request, ResultReportService $service)
    {
        $data = $request->validate(['month' => 'required|date_format:Y-m', 'user_id' => 'required|integer|exists:users,id']);

        return response()->json(['data' => $service->close($request->user(), $data['month'], $data['user_id'])]);
    }
}
