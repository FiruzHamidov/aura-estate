<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Services\PropertyModeration\PropertyModerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PropertyContactAnalyticsController extends Controller
{
    public function track(Request $request, Property $property, PropertyModerationService $moderation)
    {
        $data = $request->validate(['action' => ['required', Rule::in(['phone', 'chat', 'whatsapp'])]]);
        $moderation->publicOrFail($property, $request->user() ?? $request->user('sanctum'));
        // Atomic updates preserve concurrent clicks. No visitor identifiers are stored.
        app(\App\Services\PropertyAnalyticsRecorder::class)->record($property->id, $data['action'].'_clicks');
        return response()->noContent();
    }

    public function index(Request $request)
    {
        abort_unless($request->user()?->hasPermission('properties.analytics.view'), 403);
        $data = $request->validate([
            'sort' => ['sometimes', Rule::in(['views_count', 'phone_clicks', 'chat_clicks', 'whatsapp_clicks', 'total_clicks'])],
            'search' => ['nullable', 'string', 'max:150'],
            'period' => ['sometimes', Rule::in(['all', 'today', 'week', 'month', 'custom'])],
            'date_from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'group_id' => ['nullable', 'integer', Rule::exists('branch_groups', 'id')->where(fn ($q) => $request->filled('branch_id') ? $q->where('branch_id', $request->integer('branch_id')) : $q)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $period = $data['period'] ?? 'all';
        $today = now('Asia/Dushanbe')->startOfDay();
        $from = match ($period) {
            'today' => $today->toDateString(),
            'week' => $today->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString(),
            'month' => $today->copy()->startOfMonth()->toDateString(),
            'custom' => $data['date_from'],
            default => null,
        };
        $to = $period === 'custom' ? $data['date_to'] : $today->toDateString();
        $query = DB::table('properties as p')->select(['p.id', 'p.title', 'p.address', 'p.moderation_status']);
        $columns = ['views_count', 'phone_clicks', 'chat_clicks', 'whatsapp_clicks'];
        if ($from) {
            $daily = DB::table('property_analytics_daily')->select('property_id')->whereBetween('day', [$from, $to])->groupBy('property_id');
            foreach ($columns as $column) $daily->selectRaw("SUM($column) as $column");
            $query->leftJoinSub($daily, 'd', 'd.property_id', '=', 'p.id');
        }
        $prefix = $from ? 'd' : 'p';
        foreach ($columns as $column) $query->selectRaw("COALESCE($prefix.$column, 0) as $column");
        $query->selectRaw("(COALESCE($prefix.phone_clicks, 0) + COALESCE($prefix.chat_clicks, 0) + COALESCE($prefix.whatsapp_clicks, 0)) as total_clicks");
        if (! empty($data['branch_id'])) $query->where('p.branch_id', $data['branch_id']);
        if (! empty($data['group_id'])) $query->where('p.branch_group_id', $data['group_id']);
        if (! empty($data['agent_id'])) $query->whereRaw('COALESCE(p.agent_id, p.created_by) = ?', [(int) $data['agent_id']]);
        if ($search = trim($data['search'] ?? '')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%'.$search.'%')->orWhere('address', 'like', '%'.$search.'%');
                if (ctype_digit($search)) $q->orWhere('p.id', $search);
            });
        }
        $result = $query->orderByDesc($data['sort'] ?? 'views_count')->orderByDesc('p.id')->paginate(25)->toArray();
        $result['filters'] = ['period' => $period, 'date_from' => $from, 'date_to' => $from ? $to : null, 'timezone' => 'Asia/Dushanbe'];
        $result['tracking_started_at'] = DB::table('property_analytics_coverage')->where('id', 1)->value('started_at');
        $result['branches'] = DB::table('branches')->select('id', 'name')->orderBy('name')->get();
        $result['groups'] = DB::table('branch_groups')->select('id', 'name', 'branch_id')->orderBy('name')->get();
        // Options follow the same property ownership and organization scope as the rows.
        $result['agents'] = DB::table('users as u')->select('u.id', 'u.name')
            ->whereExists(function ($q) use ($data) {
                $q->selectRaw('1')->from('properties as owned')->whereRaw('COALESCE(owned.agent_id, owned.created_by) = u.id');
                if (! empty($data['branch_id'])) $q->where('owned.branch_id', $data['branch_id']);
                if (! empty($data['group_id'])) $q->where('owned.branch_group_id', $data['group_id']);
            })->orderBy('u.name')->orderBy('u.id')->get();
        return response()->json($result);
    }
}
