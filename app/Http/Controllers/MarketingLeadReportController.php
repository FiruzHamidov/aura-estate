<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Property;
use App\Services\Crm\AuditLogger;
use App\Services\Marketing\MarketingLeadReport;
use App\Support\ClientPhone;
use App\Support\LeadAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MarketingLeadReportController extends Controller
{
    public function index(Request $request, MarketingLeadReport $report)
    {
        $data = $request->validate([
            'date_from' => 'required|date_format:Y-m-d',
            'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'horizon_days' => ['sometimes', 'integer', Rule::in([30, 60, 90])],
            'source' => 'nullable|string|max:100', 'campaign' => 'nullable|string|max:255',
            'user_ids' => 'sometimes|array|min:1|max:100', 'user_ids.*' => 'integer|distinct|min:1',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        abort_if(Carbon::parse($data['date_from'])->diffInDays(Carbon::parse($data['date_to'])) > 366, 422, 'Выберите период до одного года.');

        return response()->json($report->report($request->user()->loadMissing('role'), $data));
    }

    public function classify(Request $request, Lead $lead)
    {
        $actor = $request->user()->loadMissing('role');
        abort_unless($actor->hasPermission('leads.update') && in_array($actor->role?->slug, MarketingLeadReport::REVIEW_ROLES, true), 403);
        app(LeadAccess::class)->ensureVisible($actor, $lead);
        $data = $request->validate(['returning' => 'required|boolean', 'reason' => 'required|string|min:5|max:2000']);

        return DB::transaction(function () use ($actor, $lead, $data) {
            $receipt = DB::table('marketing_lead_receipts')->where('lead_id', $lead->id)->lockForUpdate()->first();
            abort_unless($receipt && in_array($receipt->classification, ['existing', 'repeat'], true), 422, 'Возвращённым может быть только ранее известный контакт.');
            DB::table('marketing_lead_receipts')->where('id', $receipt->id)->update([
                'returning_confirmed' => $data['returning'], 'classification_reason' => $data['reason'], 'updated_at' => now(),
            ]);
            app(AuditLogger::class)->log($lead, $actor, 'marketing_classification_review',
                ['returning' => (bool) $receipt->returning_confirmed], $data, 'Проверено повторное привлечение клиента.');

            return response()->json(['message' => 'Решение сохранено']);
        });
    }

    public function review(Request $request, Lead $lead, Property $property, MarketingLeadReport $report)
    {
        $actor = $request->user()->loadMissing('role');
        abort_unless($actor->hasPermission('leads.update') && in_array($actor->role?->slug, MarketingLeadReport::REVIEW_ROLES, true), 403);
        app(LeadAccess::class)->ensureVisible($actor, $lead);
        $data = $request->validate(['decision' => ['required', Rule::in(['confirm', 'reject'])], 'reason' => 'required|string|min:5|max:2000']);

        return DB::transaction(function () use ($actor, $lead, $property, $report, $data) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            app(LeadAccess::class)->ensureVisible($actor, $lead);
            $sale = $report->salesQuery($actor)->whereKey($property->id)->lockForUpdate()->firstOrFail();
            $receipt = DB::table('marketing_lead_receipts')->where('lead_id', $lead->id)->first();
            abort_unless($receipt && $sale->sold_at >= $receipt->received_at && $sale->sold_at <= now(), 422, 'Продажа должна быть после поступления лида.');
            $buyerMatch = $sale->buyer_client_id && in_array($sale->buyer_client_id, array_filter([$lead->client_id, $lead->converted_client_id]));
            $phoneMatch = $receipt->phone_key && ClientPhone::normalize($sale->buyer_phone) === $receipt->phone_key;
            abort_unless($buyerMatch || $phoneMatch, 422, 'Покупатель или номер не совпадает с лидом.');
            $old = DB::table('marketing_sale_reviews')->where('property_id', $sale->id)->first();
            abort_if($old && $old->decision === 'confirm' && $old->lead_id != $lead->id, 409, 'Продажа уже отнесена к другому лиду. Сначала отмените прежнюю связь.');
            DB::table('marketing_sale_reviews')->updateOrInsert(['property_id' => $sale->id], [
                'lead_id' => $lead->id, 'decision' => $data['decision'], 'reason' => $data['reason'],
                'reviewed_by' => $actor->id, 'created_at' => $old?->created_at ?? now(), 'updated_at' => now(),
            ]);
            app(AuditLogger::class)->log($lead, $actor, 'marketing_sale_review', $old ? (array) $old : [],
                ['property_id' => $sale->id] + $data, 'Проверена связь маркетингового лида с продажей.');

            return response()->json(['message' => 'Решение сохранено']);
        });
    }
}
