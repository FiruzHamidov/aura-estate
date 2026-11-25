<?php

namespace App\Http\Controllers;

use App\Services\AgentWorkspace\AgentDashboard;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AgentDashboardController extends Controller
{
    public function __invoke(Request $request, AgentDashboard $dashboard)
    {
        $v = $request->validate(['month' => ['nullable', 'date_format:Y-m', 'after_or_equal:2020-01', 'before_or_equal:'.now('Asia/Dushanbe')->format('Y-m')], 'kind' => ['nullable', Rule::in(['all', 'sale', 'rental'])]]);

        return response()->json($dashboard->get($request->user(), $v['month'] ?? now('Asia/Dushanbe')->format('Y-m'), $v['kind'] ?? 'all'))->header('Cache-Control', 'private, no-store');
    }
}
