<?php

namespace App\Http\Controllers;

use App\Http\Requests\SmsCampaignRequest;
use App\Models\Branch;
use App\Models\ClientSource;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Rules\InternationalPhoneNumber;
use App\Services\Marketing\SmsCampaigns;
use App\Support\InternationalPhone;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;

final class SmsCampaignController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [function (Request $request, $next) {
            abort_unless(SmsCampaign::allowed($request->user()), 403, 'Рассылки доступны маркетологу и администраторам.');

            return $next($request);
        }];
    }

    public function options(Request $request)
    {
        $branches = Branch::query();
        if ($request->user()->usesAssignedGroupScope()) {
            $branches->whereKey($request->user()->branch_id);
        }

        return response()->json([
            'branches' => $branches->orderBy('name')->get(['id', 'name']),
            'sources' => ClientSource::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function preview(SmsCampaignRequest $request, SmsCampaigns $service)
    {
        return response()->json($service->preview($request->user(), $request->validated()));
    }

    public function store(SmsCampaignRequest $request, SmsCampaigns $service)
    {
        $campaign = $service->create($request->user(), $request->validated());

        return response()->json(['id' => $campaign->id], $campaign->wasRecentlyCreated ? 201 : 200);
    }

    public function index(Request $request)
    {
        $campaigns = SmsCampaign::visibleTo($request->user())->latest('id')->paginate(15);
        $ids = $campaigns->getCollection()->modelKeys();
        $counts = SmsCampaignRecipient::whereIn('campaign_id', $ids)
            ->selectRaw('campaign_id, status, count(*) as total')->groupBy('campaign_id', 'status')
            ->get()->groupBy('campaign_id');
        $names = DB::table('users')->whereIntegerInRaw('id', $campaigns->getCollection()->pluck('created_by')->filter())
            ->pluck('name', 'id');
        $campaigns->through(function ($campaign) use ($counts, $names) {
            $totals = array_fill_keys(SmsCampaignRecipient::STATUSES, 0);
            foreach ($counts->get($campaign->id, []) as $row) {
                $totals[$row->status] = (int) $row->total;
            }

            return $campaign->toArray() + ['counts' => $totals, 'author' => $names->get($campaign->created_by)];
        });

        return response()->json($campaigns);
    }

    public function recipients(Request $request, int $id)
    {
        $campaign = SmsCampaign::visibleTo($request->user())->findOrFail($id);
        $filter = $request->validate(['status' => ['sometimes', \Illuminate\Validation\Rule::in(SmsCampaignRecipient::STATUSES)]]);
        $rows = $campaign->recipients()->when(isset($filter['status']), fn ($q) => $q->where('status', $filter['status']))
            ->orderBy('id')->paginate(30)->through(fn ($row) => [
                'id' => $row->id, 'phone' => SmsCampaignRecipient::mask($row->phone), 'status' => $row->status,
                'provider_id' => $row->provider_id, 'bulk_name' => $row->bulk_name, 'error' => $row->error,
            ]);

        return response()->json($rows);
    }

    public function cancel(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $campaign = SmsCampaign::visibleTo($request->user())->lockForUpdate()->findOrFail($id);
            if ($campaign->cancelled_at) {
                return;
            }
            $cancelled = $campaign->recipients()->where('status', 'pending')
                ->update(['status' => 'cancelled', 'next_action_at' => null]);
            abort_unless($cancelled, 409, 'Все сообщения уже обработаны.');
            $campaign->cancelled_at = now();
            $campaign->save();
        }, 3);

        return response()->noContent();
    }

    public function exclusions()
    {
        return response()->json(DB::table('sms_campaign_exclusions')->orderByDesc('id')->paginate(20)
            ->through(fn ($row) => ['id' => $row->id, 'phone' => SmsCampaignRecipient::mask($row->phone)]));
    }

    public function exclude(Request $request)
    {
        $data = $request->validate(['phone' => ['required', 'string', new InternationalPhoneNumber]]);
        DB::table('sms_campaign_exclusions')->insertOrIgnore(['phone' => InternationalPhone::e164($data['phone']), 'created_at' => now()]);

        return response()->noContent();
    }

    public function include(int $id)
    {
        DB::table('sms_campaign_exclusions')->where('id', $id)->delete();

        return response()->noContent();
    }
}
